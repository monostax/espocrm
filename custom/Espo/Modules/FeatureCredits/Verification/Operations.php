<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Verification;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Crypt;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use Espo\Modules\FeatureCredits\Accounting\RecordId;
use Espo\Modules\FeatureCredits\Accounting\ReservationInput;
use Espo\Modules\FeatureCredits\Accounting\Reservations;
use Espo\Modules\FeatureCredits\Accounting\SourceReference;
use Espo\Modules\FeatureCredits\Accounting\WalletLock;
use Espo\Modules\FeatureCredits\Enrichment\ReviewLock;
use Espo\Modules\FeatureCredits\Jobs\RunPhoneVerification;
use Espo\ORM\EntityManager;
use InvalidArgumentException;
use PDO;
use stdClass;

/**
 * Context resolution, optional DOB save-first, admission, deduplication, 30-day reuse,
 * status recovery, and 90-day retained history for phone verification (D5, D7, D13, D16–D23).
 */
class Operations
{
    public function __construct(
        private EntityManager $entityManager,
        private Clock $clock,
        private Access $access,
        private CapabilityGate $capability,
        private WalletLock $walletLock,
        private ReviewLock $reviewLock,
        private Reservations $reservations,
        private Crypt $crypt,
        private Config $config,
        private ?ServiceContainer $records = null,
        private ?JobSchedulerFactory $scheduler = null,
    ) {}

    public function context(stdClass $input): array
    {
        $data = get_object_vars($input);
        if (array_diff(array_keys($data), ['tenantId', 'contactId', 'sources']) ||
            !is_string($data['tenantId'] ?? null) ||
            !is_string($data['contactId'] ?? null) ||
            !is_array($data['sources'] ?? null) ||
            count($data['sources']) > 50) {
            throw new InvalidArgumentException('Phone verification context requires tenantId, contactId, and a bounded sources list.');
        }
        $tenantId = $data['tenantId'];
        $contactId = $data['contactId'];
        $this->access->contact($tenantId, $contactId);

        $sources = [];
        $seenKeys = [];
        foreach ($data['sources'] as $index => $rawSource) {
            if ($rawSource instanceof stdClass) {
                $rawSource = get_object_vars($rawSource);
            }
            if (!is_array($rawSource)) {
                throw new InvalidArgumentException('Each verification source must be an object.');
            }
            $key = $rawSource['key'] ?? (string) $index;
            if (!is_string($key) || $key === '' || strlen($key) > 128 || isset($seenKeys[$key])) {
                throw new InvalidArgumentException('Each verification source requires a unique key.');
            }
            $seenKeys[$key] = true;
            $rawSource['key'] = $key;
            $sources[$key] = $rawSource;
        }

        return $this->reviewLock->run($tenantId, function (PDO $pdo) use ($tenantId, $contactId, $sources): array {
            $now = $this->clock->now();
            $contact = $this->access->contact($tenantId, $contactId);
            $identity = $this->access->contactIdentity($contact, $now);
            $cap = $this->capability->inspect($tenantId);
            $hmacKey = $this->hmacKey();

            $rows = [];
            foreach ($sources as $key => $source) {
                try {
                    $resolved = $this->access->resolveSource($pdo, $tenantId, $contact, $source);
                } catch (NotFound | Forbidden | Conflict) {
                    $rows[$key] = [
                        'key' => $key,
                        'eligible' => false,
                        'reason' => 'source_unavailable',
                        'canonicalPhone' => null,
                        'ownershipStatus' => 'unverified',
                        'contextRevision' => null,
                        'actions' => ['canVerify' => false, 'canRefresh' => false, 'requiresPaidRefresh' => false],
                        'activeOperation' => null,
                        'currentResult' => null,
                        'lastFailedAttempt' => null,
                    ];
                    continue;
                }

                $e164 = $resolved['e164'];
                $ownershipRejected = $resolved['ownershipRejected'];
                if ($ownershipRejected) {
                    $rows[$key] = [
                        'key' => $key,
                        'eligible' => false,
                        'reason' => 'ownership_rejected',
                        'canonicalPhone' => $e164,
                        'ownershipStatus' => 'rejected',
                        'contextRevision' => null,
                        'actions' => ['canVerify' => false, 'canRefresh' => false, 'requiresPaidRefresh' => false],
                        'activeOperation' => null,
                        'currentResult' => null,
                        'lastFailedAttempt' => null,
                    ];
                    continue;
                }

                if ($e164 === null) {
                    $rows[$key] = [
                        'key' => $key,
                        'eligible' => false,
                        'reason' => $resolved['phoneReason'] ?? 'invalid_phone',
                        'canonicalPhone' => null,
                        'ownershipStatus' => 'unverified',
                        'contextRevision' => null,
                        'actions' => ['canVerify' => false, 'canRefresh' => false, 'requiresPaidRefresh' => false],
                        'activeOperation' => null,
                        'currentResult' => null,
                        'lastFailedAttempt' => null,
                    ];
                    continue;
                }

                if (!$identity['nameReady']) {
                    $rows[$key] = [
                        'key' => $key,
                        'eligible' => false,
                        'reason' => 'missing_name',
                        'canonicalPhone' => $e164,
                        'ownershipStatus' => 'unverified',
                        'contextRevision' => null,
                        'actions' => ['canVerify' => false, 'canRefresh' => false, 'requiresPaidRefresh' => false],
                        'activeOperation' => null,
                        'currentResult' => null,
                        'lastFailedAttempt' => null,
                    ];
                    continue;
                }

                if (!$identity['dateOfBirthValid']) {
                    $rows[$key] = [
                        'key' => $key,
                        'eligible' => false,
                        'reason' => 'invalid_date_of_birth',
                        'canonicalPhone' => $e164,
                        'ownershipStatus' => 'unverified',
                        'contextRevision' => null,
                        'actions' => ['canVerify' => false, 'canRefresh' => false, 'requiresPaidRefresh' => false],
                        'activeOperation' => null,
                        'currentResult' => null,
                        'lastFailedAttempt' => null,
                    ];
                    continue;
                }

                $firstName = $identity['name']['firstName'];
                $lastName = $identity['name']['lastName'];
                $dob = $identity['dateOfBirth'];
                $policyVersion = $cap['policyVersion'];
                $fingerprintHash = IdentityInput::fingerprintHash(
                    $hmacKey,
                    $tenantId,
                    $contactId,
                    $e164,
                    $firstName,
                    $lastName,
                    $dob,
                    $policyVersion,
                );

                [$active, $completed, $failed] = $this->findMatchingOperations($pdo, $tenantId, $contactId, $fingerprintHash, $now);
                $fresh = $completed !== null && $completed['fresh'];
                $stale = $completed !== null && $completed['stale'];
                $canNewDispatch = $cap['available'];
                $canVerify = $active !== null || $fresh || $canNewDispatch;
                $canRefresh = $completed !== null && $active === null && $canNewDispatch;
                $reason = !$canVerify ? ($cap['reason'] ?? 'verification_unavailable') : null;

                $rows[$key] = [
                    'key' => $key,
                    'eligible' => $canVerify,
                    'reason' => $reason,
                    'canonicalPhone' => $e164,
                    'ownershipStatus' => 'unverified',
                    'contextRevision' => self::computeContextRevision(
                        $tenantId,
                        $contactId,
                        $e164,
                        $firstName,
                        $lastName,
                        $dob,
                        $policyVersion,
                    ),
                    'actions' => [
                        'canVerify' => $canVerify,
                        'canRefresh' => $canRefresh,
                        'requiresPaidRefresh' => $stale,
                    ],
                    'activeOperation' => $active,
                    'currentResult' => $completed,
                    'lastFailedAttempt' => $failed,
                ];
            }

            return [
                'tenantId' => $tenantId,
                'contactId' => $contactId,
                'observedAt' => $now,
                'priceCredits' => CapabilityGate::PRICE_CREDITS,
                'available' => $cap['available'],
                'reason' => $cap['reason'],
                'contact' => [
                    'nameReady' => $identity['nameReady'],
                    'hasDateOfBirth' => $identity['hasDateOfBirth'],
                    'dateOfBirth' => $identity['dateOfBirth'],
                    'dateOfBirthEditable' => $identity['dateOfBirthEditable'],
                ],
                'rows' => $rows,
            ];
        });
    }

    public function start(stdClass $input): array
    {
        $data = get_object_vars($input);
        if (array_diff(array_keys($data), ['tenantId', 'contactId', 'source', 'requestKey', 'contextRevision', 'refresh', 'dateOfBirth']) ||
            !is_string($data['tenantId'] ?? null) ||
            !is_string($data['contactId'] ?? null) ||
            !is_string($data['requestKey'] ?? null) ||
            !preg_match('/\A[a-zA-Z0-9._:-]{1,64}\z/', $data['requestKey']) ||
            !is_string($data['contextRevision'] ?? null) ||
            !preg_match('/\A[a-f0-9]{64}\z/', $data['contextRevision'])) {
            throw new InvalidArgumentException('Valid tenantId, contactId, source, requestKey, and contextRevision are required.');
        }
        $source = $data['source'] ?? null;
        if ($source instanceof stdClass) {
            $source = get_object_vars($source);
        }
        if (!is_array($source)) {
            throw new InvalidArgumentException('A phone source reference is required.');
        }
        $refresh = $data['refresh'] ?? false;
        if (!is_bool($refresh)) {
            throw new InvalidArgumentException('Refresh flag must be boolean.');
        }
        $suppliedDob = $data['dateOfBirth'] ?? null;
        if ($suppliedDob !== null && $suppliedDob !== '') {
            $suppliedDob = IdentityInput::validateDateOfBirth($suppliedDob, $this->clock->now());
        } else {
            $suppliedDob = null;
        }

        $tenantId = $data['tenantId'];
        $contactId = $data['contactId'];
        $requestKey = $data['requestKey'];
        $contextRevision = $data['contextRevision'];
        $actorId = $this->access->actorId();
        $contact = $this->access->contact($tenantId, $contactId);

        $canonicalSource = $source;
        unset($canonicalSource['key']);
        ksort($canonicalSource);
        $commandHash = self::hash([
            'tenantId' => $tenantId,
            'contactId' => $contactId,
            'source' => $canonicalSource,
            'contextRevision' => $contextRevision,
            'refresh' => $refresh,
            'dateOfBirth' => $suppliedDob,
        ]);

        // D7: Save supplied DOB before checking in its own transaction, so a successful
        // DOB save remains on the Contact even if verification subsequently fails (e.g. insufficient funds).
        $preSaveRevision = null;
        if ($suppliedDob !== null) {
            $nowPre = $this->clock->now();
            $currentIdentity = $this->access->contactIdentity($contact, $nowPre);
            if ($currentIdentity['dateOfBirth'] !== $suppliedDob) {
                $this->access->assertDateOfBirthWriteAccess($contact);
                if ($currentIdentity['nameReady']) {
                    $resolvedPre = $this->access->resolveSource($this->entityManager->getPDO(), $tenantId, $contact, $source);
                    if ($resolvedPre['e164'] !== null) {
                        $preSaveRevision = self::computeContextRevision(
                            $tenantId,
                            $contactId,
                            $resolvedPre['e164'],
                            $currentIdentity['name']['firstName'],
                            $currentIdentity['name']['lastName'],
                            $currentIdentity['dateOfBirth'],
                            $this->capability->policyVersion(),
                        );
                    }
                }
                $this->persistDateOfBirth($tenantId, $contactId, $suppliedDob);
            }
        }

        $result = $this->walletLock->run(
            $tenantId,
            function (PDO $pdo, array $wallet, string $now) use (
                $tenantId,
                $contactId,
                $actorId,
                $source,
                $requestKey,
                $contextRevision,
                $preSaveRevision,
                $refresh,
                $commandHash,
            ): array {
                // 1. Check request binding first so retries return the original operation even after expiry/erasure.
                $q = $pdo->prepare('SELECT * FROM phone_verification_request WHERE tenant_id = ? AND request_key = ?');
                $q->execute([$tenantId, $requestKey]);
                if ($existingRequest = $q->fetch(PDO::FETCH_ASSOC)) {
                    if ($existingRequest['deleted'] || !hash_equals($existingRequest['command_hash'], $commandHash)) {
                        throw new Conflict('Phone verification request key already has different parameters.');
                    }
                    $this->access->contact($tenantId, $contactId);
                    $op = $this->loadOperationLocked($pdo, $tenantId, $existingRequest['operation_id']);
                    if ($op['contact_id'] !== $contactId) {
                        throw new Conflict('Phone verification request belongs to another Contact.');
                    }
                    return [
                        'tenantId' => $tenantId,
                        'contactId' => $contactId,
                        'requestKey' => $requestKey,
                        'resolution' => $existingRequest['resolution'],
                        'operation' => $this->projectOperationLocked($pdo, $tenantId, $op, $now),
                    ];
                }

                // 2. Lock Contact and revalidate current permissions, inputs, and rejection status.
                $q = $pdo->prepare('SELECT id FROM contact WHERE id = ? AND tenant_id = ? AND deleted = FALSE FOR UPDATE');
                $q->execute([$contactId, $tenantId]);
                if (!$q->fetchColumn()) {
                    throw new NotFound('Unknown verification Contact.');
                }
                $contact = $this->access->contact($tenantId, $contactId);
                $identity = $this->access->contactIdentity($contact, $now);
                $resolved = $this->access->resolveSource($pdo, $tenantId, $contact, $source);

                if ($resolved['ownershipRejected']) {
                    throw Conflict::createWithBody('Phone ownership is rejected.', '{"reason":"ownership_rejected"}');
                }
                if ($resolved['e164'] === null) {
                    $reason = $resolved['phoneReason'] ?? 'invalid_phone';
                    throw Conflict::createWithBody('Phone number is not eligible for Brazil verification.', json_encode(['reason' => $reason], JSON_THROW_ON_ERROR));
                }
                if (!$identity['nameReady']) {
                    throw Conflict::createWithBody('Contact first and last name are required.', '{"reason":"missing_name"}');
                }
                if (!$identity['dateOfBirthValid']) {
                    throw Conflict::createWithBody('Contact date of birth is invalid.', '{"reason":"invalid_date_of_birth"}');
                }

                $e164 = $resolved['e164'];
                $firstName = $identity['name']['firstName'];
                $lastName = $identity['name']['lastName'];
                $dob = $identity['dateOfBirth'];
                $cap = $this->capability->inspect($tenantId);
                $policyVersion = $cap['policyVersion'];

                $currentRevision = self::computeContextRevision(
                    $tenantId,
                    $contactId,
                    $e164,
                    $firstName,
                    $lastName,
                    $dob,
                    $policyVersion,
                );
                if (!hash_equals($currentRevision, $contextRevision) &&
                    ($preSaveRevision === null || !hash_equals($preSaveRevision, $contextRevision))) {
                    throw Conflict::createWithBody('Phone verification context changed; review and confirm again.', '{"reason":"context_changed"}');
                }

                $hmacKey = $this->hmacKey();
                $subjectHash = IdentityInput::subjectHash($hmacKey, $tenantId, $contactId, $e164);
                $fingerprintHash = IdentityInput::fingerprintHash(
                    $hmacKey,
                    $tenantId,
                    $contactId,
                    $e164,
                    $firstName,
                    $lastName,
                    $dob,
                    $policyVersion,
                );

                // 3. Join an active matching operation (D18: one shared active check per input fingerprint).
                $q = $pdo->prepare("SELECT * FROM phone_verification_operation
                    WHERE tenant_id = ? AND contact_id = ? AND fingerprint_hash = ?
                    AND state IN ('admitted', 'claimed') AND deleted = FALSE
                    ORDER BY admitted_at DESC, id DESC FOR UPDATE");
                $q->execute([$tenantId, $contactId, $fingerprintHash]);
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $activeOp) {
                    if ($activeOp['deadline_at'] <= $now) {
                        continue;
                    }
                    $this->insertRequestBinding($pdo, $tenantId, $contactId, $actorId, $requestKey, $commandHash, 'joined', $activeOp['id'], $now);
                    return [
                        'tenantId' => $tenantId,
                        'contactId' => $contactId,
                        'requestKey' => $requestKey,
                        'resolution' => 'joined',
                        'operation' => $this->projectOperationLocked($pdo, $tenantId, $activeOp, $now),
                    ];
                }

                // 4. Reuse fresh completed evidence within 30 days unless explicit refresh was confirmed (D13).
                if (!$refresh) {
                    $q = $pdo->prepare("SELECT * FROM phone_verification_operation
                        WHERE tenant_id = ? AND contact_id = ? AND fingerprint_hash = ?
                        AND state = 'completed' AND erased_at IS NULL AND deleted = FALSE
                        ORDER BY completed_at DESC, id DESC LIMIT 1 FOR UPDATE");
                    $q->execute([$tenantId, $contactId, $fingerprintHash]);
                    $completedOp = $q->fetch(PDO::FETCH_ASSOC);
                    if ($completedOp && $completedOp['fresh_until'] > $now && $completedOp['retention_expires_at'] > $now) {
                        $this->insertRequestBinding($pdo, $tenantId, $contactId, $actorId, $requestKey, $commandHash, 'reused', $completedOp['id'], $now);
                        return [
                            'tenantId' => $tenantId,
                            'contactId' => $contactId,
                            'requestKey' => $requestKey,
                            'resolution' => 'reused',
                            'operation' => $this->projectOperationLocked($pdo, $tenantId, $completedOp, $now),
                        ];
                    }
                }

                // 5. New paid check requires active capability gate, pilot limits, cooldown, and funds (D2, D9, D20, D25).
                if (!$cap['available']) {
                    throw Conflict::createWithBody(
                        'Phone verification is not currently available for this workspace.',
                        json_encode(['reason' => $cap['reason'] ?? 'verification_unavailable'], JSON_THROW_ON_ERROR),
                    );
                }

                $this->enforceLimitsLocked($pdo, $tenantId, $fingerprintHash, $cap['limits'], $now);

                $q = $pdo->prepare('SELECT id FROM tenant_credit_billing_rate WHERE tenant_id = ? AND deleted = FALSE
                    AND effective_from <= ? AND (effective_until IS NULL OR effective_until > ?)');
                $q->execute([$tenantId, $now, $now]);
                $agreements = $q->fetchAll(PDO::FETCH_COLUMN);
                if (count($agreements) !== 1) {
                    throw new Conflict('Admission requires exactly one active tenant agreement.');
                }

                $operationId = RecordId::generate();
                $hold = $this->reservations->reserveLocked($pdo, $wallet, $now, new ReservationInput(
                    $tenantId,
                    'verification',
                    'verification:' . $operationId,
                    'verification:' . $operationId,
                    $agreements[0],
                    CapabilityGate::PRICE_CREDITS,
                    new SourceReference('Contact', $contactId),
                ));

                $snapshotIdentity = [
                    'phone' => $e164,
                    'firstName' => $firstName,
                    'lastName' => $lastName,
                    'dateOfBirth' => $dob,
                ];
                $identityCiphertext = $this->crypt->encrypt(json_encode($snapshotIdentity, JSON_THROW_ON_ERROR));
                $deadlineAt = self::after($now, CapabilityGate::OPERATION_DEADLINE_SECONDS);
                $identityHash = self::hash([
                    'id' => $operationId,
                    'tenantId' => $tenantId,
                    'contactId' => $contactId,
                    'actorId' => $actorId,
                    'usageId' => $hold['usageId'],
                    'reservationId' => $hold['reservationId'],
                    'policyVersion' => $policyVersion,
                    'priceCredits' => CapabilityGate::PRICE_CREDITS,
                    'subjectHash' => $subjectHash,
                    'fingerprintHash' => $fingerprintHash,
                    'hasDateOfBirth' => $dob !== null,
                    'identity' => $snapshotIdentity,
                    'admittedAt' => $now,
                    'deadlineAt' => $deadlineAt,
                ]);

                $pdo->prepare('INSERT INTO phone_verification_operation
                    (id, tenant_id, contact_id, actor_id, usage_id, reservation_id, policy_version, price_credits,
                     subject_hash, fingerprint_hash, has_date_of_birth, identity_ciphertext, identity_hash,
                     state, admitted_at, deadline_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
                        $operationId,
                        $tenantId,
                        $contactId,
                        $actorId,
                        $hold['usageId'],
                        $hold['reservationId'],
                        $policyVersion,
                        CapabilityGate::PRICE_CREDITS,
                        $subjectHash,
                        $fingerprintHash,
                        $dob !== null ? 1 : 0,
                        $identityCiphertext,
                        $identityHash,
                        'admitted',
                        $now,
                        $deadlineAt,
                    ]);

                $this->insertRequestBinding($pdo, $tenantId, $contactId, $actorId, $requestKey, $commandHash, 'admitted', $operationId, $now);
                $op = $this->loadOperationLocked($pdo, $tenantId, $operationId);

                return [
                    'tenantId' => $tenantId,
                    'contactId' => $contactId,
                    'requestKey' => $requestKey,
                    'resolution' => 'admitted',
                    'operation' => $this->projectOperationLocked($pdo, $tenantId, $op, $now),
                ];
            },
        );

        if (($result['resolution'] ?? null) === 'admitted' && $this->scheduler !== null) {
            try {
                $this->scheduler->create()
                    ->setClassName(RunPhoneVerification::class)
                    ->setData(['tenantId' => $tenantId, 'operationId' => $result['operation']['id']])
                    ->setGroup('phone-verification-' . $result['operation']['id'])
                    ->schedule();
            } catch (\Throwable) {
                // Durable admitted operations are recovered and scheduled by DispatchPhoneVerification.
            }
        }

        return $result;
    }

    /**
     * Recover the original operation or receipt with current authorization checks; never dispatch (Section 5).
     */
    public function status(array $params): array
    {
        if (array_diff(array_keys($params), ['tenantId', 'contactId', 'requestKey', 'operationId']) ||
            !is_string($params['tenantId'] ?? null) ||
            !is_string($params['contactId'] ?? null)) {
            throw new InvalidArgumentException('Status query requires tenantId, contactId, and requestKey or operationId.');
        }
        $tenantId = $params['tenantId'];
        $contactId = $params['contactId'];
        $requestKey = $params['requestKey'] ?? null;
        $operationId = $params['operationId'] ?? null;
        if (($requestKey === null && $operationId === null) || ($requestKey !== null && $operationId !== null)) {
            throw new InvalidArgumentException('Specify either requestKey or operationId.');
        }
        if ($requestKey !== null && (!is_string($requestKey) || !preg_match('/\A[a-zA-Z0-9._:-]{1,64}\z/', $requestKey))) {
            throw new InvalidArgumentException('Invalid verification requestKey.');
        }
        if ($operationId !== null) {
            if (!is_string($operationId)) {
                throw new InvalidArgumentException('Invalid verification operationId.');
            }
            GrantInput::identity($operationId);
        }

        $this->access->contact($tenantId, $contactId);

        return $this->reviewLock->run($tenantId, function (PDO $pdo) use ($tenantId, $contactId, $requestKey, $operationId): array {
            $now = $this->clock->now();
            $this->access->contact($tenantId, $contactId);
            $resolution = null;
            if ($requestKey !== null) {
                $q = $pdo->prepare('SELECT * FROM phone_verification_request WHERE tenant_id = ? AND request_key = ?');
                $q->execute([$tenantId, $requestKey]);
                $req = $q->fetch(PDO::FETCH_ASSOC);
                if (!$req || $req['deleted'] || $req['contact_id'] !== $contactId) {
                    throw new NotFound('Unknown phone verification request.');
                }
                $operationId = $req['operation_id'];
                $resolution = $req['resolution'];
            }
            $op = $this->loadOperationLocked($pdo, $tenantId, (string) $operationId);
            if ($op['contact_id'] !== $contactId) {
                throw new NotFound('Unknown phone verification operation.');
            }
            return [
                'tenantId' => $tenantId,
                'contactId' => $contactId,
                ...($requestKey !== null ? ['requestKey' => $requestKey, 'resolution' => $resolution] : []),
                'operation' => $this->projectOperationLocked($pdo, $tenantId, $op, $now),
            ];
        });
    }

    /**
     * Return authorized, retained evidence and dated financial receipts for that Contact (Section 5).
     */
    public function history(array $params): array
    {
        if (array_diff(array_keys($params), ['tenantId', 'contactId', 'limit', 'beforeAt', 'beforeId']) ||
            !is_string($params['tenantId'] ?? null) ||
            !is_string($params['contactId'] ?? null)) {
            throw new InvalidArgumentException('Invalid phone verification history query.');
        }
        $tenantId = $params['tenantId'];
        $contactId = $params['contactId'];
        $limit = $params['limit'] ?? 20;
        if (is_string($limit) && preg_match('/\A[1-9][0-9]?\z/', $limit)) {
            $limit = (int) $limit;
        }
        if (!is_int($limit) || $limit < 1 || $limit > 50) {
            throw new InvalidArgumentException('History limit must be 1–50.');
        }
        $cursor = array_key_exists('beforeAt', $params) || array_key_exists('beforeId', $params);
        if ($cursor) {
            if (!is_string($params['beforeAt'] ?? null) || !is_string($params['beforeId'] ?? null)) {
                throw new InvalidArgumentException('Complete history cursor required.');
            }
            GrantInput::timestamp($params['beforeAt']);
            GrantInput::identity($params['beforeId']);
        }

        $this->access->contact($tenantId, $contactId);

        return $this->reviewLock->run($tenantId, function (PDO $pdo) use ($tenantId, $contactId, $params, $cursor, $limit): array {
            $now = $this->clock->now();
            $this->access->contact($tenantId, $contactId);
            $sql = 'SELECT * FROM phone_verification_operation WHERE tenant_id = ? AND contact_id = ? AND deleted = FALSE';
            $args = [$tenantId, $contactId];
            if ($cursor) {
                $sql .= ' AND (admitted_at < ? OR (admitted_at = ? AND id < ?))';
                array_push($args, $params['beforeAt'], $params['beforeAt'], $params['beforeId']);
            }
            $q = $pdo->prepare($sql . ' ORDER BY admitted_at DESC, id DESC LIMIT ' . ($limit + 1));
            $q->execute($args);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            $more = count($rows) > $limit;
            if ($more) {
                array_pop($rows);
            }
            $items = [];
            foreach ($rows as $row) {
                $items[] = $this->projectOperationLocked($pdo, $tenantId, $row, $now);
            }
            $last = $more ? $rows[$limit - 1] : null;
            return [
                'tenantId' => $tenantId,
                'contactId' => $contactId,
                'observedAt' => $now,
                'list' => $items,
                'next' => $last === null ? null : ['beforeAt' => $last['admitted_at'], 'beforeId' => $last['id']],
            ];
        });
    }

    public function loadOperationLocked(PDO $pdo, string $tenantId, string $operationId): array
    {
        GrantInput::identity($operationId);
        $q = $pdo->prepare('SELECT * FROM phone_verification_operation WHERE id = ? AND tenant_id = ?');
        $q->execute([$operationId, $tenantId]);
        $op = $q->fetch(PDO::FETCH_ASSOC);
        if (!$op || $op['deleted']) {
            throw new NotFound('Unknown phone verification operation.');
        }
        return $op;
    }

    public function projectOperationLocked(PDO $pdo, string $tenantId, array $op, string $now): array
    {
        $q = $pdo->prepare('SELECT u.state AS usage_state, u.settled_credits, u.deleted AS usage_deleted,
            r.id AS reservation_id, r.state AS reservation_state, r.reserved_credits, r.deleted AS reservation_deleted
            FROM credit_usage u JOIN credit_reservation r ON r.usage_id = u.id AND r.tenant_id = u.tenant_id
            WHERE u.id = ? AND u.tenant_id = ?');
        $q->execute([$op['usage_id'], $tenantId]);
        $financial = $q->fetch(PDO::FETCH_ASSOC);
        if (!$financial || $financial['usage_deleted'] || $financial['reservation_deleted'] ||
            $financial['reservation_id'] !== $op['reservation_id']) {
            throw new Conflict('Invalid phone verification financial binding.');
        }

        $erased = ($op['erased_at'] ?? null) !== null;
        $expired = ($op['retention_expires_at'] ?? null) !== null && $op['retention_expires_at'] <= $now;
        $hideDetails = $erased || $expired;

        $canonicalPhone = null;
        $identityChanged = false;
        if (!$hideDetails && $op['identity_ciphertext'] !== null) {
            $snapshotIdentity = json_decode($this->crypt->decrypt($op['identity_ciphertext']), true, 16, JSON_THROW_ON_ERROR);
            $this->verifyIdentityHash($op, $snapshotIdentity);
            $canonicalPhone = $snapshotIdentity['phone'] ?? null;
            try {
                $contact = $this->access->contact($tenantId, $op['contact_id']);
                $currentIdentity = $this->access->contactIdentity($contact, $now);
                $identityChanged = !$currentIdentity['nameReady'] ||
                    $currentIdentity['name']['firstName'] !== ($snapshotIdentity['firstName'] ?? null) ||
                    $currentIdentity['name']['lastName'] !== ($snapshotIdentity['lastName'] ?? null) ||
                    $currentIdentity['dateOfBirth'] !== ($snapshotIdentity['dateOfBirth'] ?? null);
            } catch (\Throwable) {
                $identityChanged = true;
            }
        }

        $matchedFields = [];
        $fieldOutcomes = null;
        if ($op['state'] === 'completed' && !$hideDetails && $op['evidence_ciphertext'] !== null) {
            $evidence = json_decode($this->crypt->decrypt($op['evidence_ciphertext']), true, 16, JSON_THROW_ON_ERROR);
            $expectedHash = self::evidenceHash($tenantId, $op['id'], $op['outcome'], $evidence);
            if (!hash_equals($op['evidence_hash'] ?? '', $expectedHash)) {
                throw new Conflict('Phone verification evidence integrity differs.');
            }
            $matchedFields = $evidence['matchedFields'] ?? [];
            $fieldOutcomes = $evidence['fieldOutcomes'] ?? null;
        }

        $publicState = match ($op['state']) {
            'admitted', 'claimed' => 'pending',
            'completed' => 'completed',
            'waived' => 'failed',
            default => throw new Conflict('Invalid phone verification state.'),
        };
        $publicOutcome = match ($op['state']) {
            'completed' => $hideDetails ? null : $op['outcome'],
            'waived' => 'failed',
            default => null,
        };
        $fresh = $op['state'] === 'completed' && !$hideDetails && !$identityChanged &&
            $op['fresh_until'] !== null && $op['fresh_until'] > $now;
        $stale = $op['state'] === 'completed' && !$hideDetails && !$identityChanged &&
            $op['fresh_until'] !== null && $op['fresh_until'] <= $now;

        return [
            'id' => $op['id'],
            'usageId' => $op['usage_id'],
            'reservationId' => $op['reservation_id'],
            'canonicalPhone' => $canonicalPhone,
            'state' => $publicState,
            'outcome' => $publicOutcome,
            'matchedFields' => $matchedFields,
            'fieldOutcomes' => $fieldOutcomes,
            'hasDateOfBirth' => (bool) $op['has_date_of_birth'],
            'priceCredits' => (string) $op['price_credits'],
            'reservedCredits' => (string) $financial['reserved_credits'],
            'settledCredits' => $financial['settled_credits'] === null ? null : (string) $financial['settled_credits'],
            'admittedAt' => $op['admitted_at'],
            'completedAt' => $op['completed_at'],
            'freshUntil' => $hideDetails ? null : $op['fresh_until'],
            'fresh' => $fresh,
            'stale' => $stale,
            'erased' => $erased,
            'expired' => $expired,
            'identityChanged' => $identityChanged,
        ];
    }

    public static function verifyIdentityHash(array $op, array $snapshotIdentity): void
    {
        $expected = self::hash([
            'id' => $op['id'],
            'tenantId' => $op['tenant_id'],
            'contactId' => $op['contact_id'],
            'actorId' => $op['actor_id'],
            'usageId' => $op['usage_id'],
            'reservationId' => $op['reservation_id'],
            'policyVersion' => $op['policy_version'],
            'priceCredits' => (string) $op['price_credits'],
            'subjectHash' => $op['subject_hash'],
            'fingerprintHash' => $op['fingerprint_hash'],
            'hasDateOfBirth' => (bool) $op['has_date_of_birth'],
            'identity' => $snapshotIdentity,
            'admittedAt' => $op['admitted_at'],
            'deadlineAt' => $op['deadline_at'],
        ]);
        if (!hash_equals($op['identity_hash'] ?? '', $expected)) {
            throw new Conflict('Phone verification identity snapshot integrity differs.');
        }
    }

    public static function evidenceHash(string $tenantId, string $operationId, string $outcome, array $evidence): string
    {
        return self::hash([
            'evidence-v1',
            $tenantId,
            $operationId,
            $outcome,
            $evidence,
        ]);
    }

    public static function computeContextRevision(
        string $tenantId,
        string $contactId,
        string $e164,
        string $firstName,
        string $lastName,
        ?string $dob,
        string $policyVersion,
    ): string {
        return self::hash([
            'context-revision-v1',
            $tenantId,
            $contactId,
            $e164,
            $firstName,
            $lastName,
            $dob,
            $policyVersion,
            CapabilityGate::PRICE_CREDITS,
        ]);
    }

    public static function hash(array $value): string
    {
        $object = json_decode(json_encode($value, JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR);
        return hash('sha256', json_encode(GrantInput::canonical($object), JSON_THROW_ON_ERROR));
    }

    public static function after(string $time, int $seconds): string
    {
        return (new DateTimeImmutable($time, new DateTimeZone('UTC')))
            ->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds')
            ->format('Y-m-d H:i:s');
    }

    /**
     * @return array{0: ?array, 1: ?array, 2: ?array}
     */
    private function findMatchingOperations(
        PDO $pdo,
        string $tenantId,
        string $contactId,
        string $fingerprintHash,
        string $now,
    ): array {
        $q = $pdo->prepare('SELECT * FROM phone_verification_operation
            WHERE tenant_id = ? AND contact_id = ? AND fingerprint_hash = ? AND deleted = FALSE
            ORDER BY admitted_at DESC, id DESC');
        $q->execute([$tenantId, $contactId, $fingerprintHash]);

        $active = null;
        $completed = null;
        $failed = null;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $op) {
            if (in_array($op['state'], ['admitted', 'claimed'], true)) {
                if ($op['deadline_at'] > $now && $active === null) {
                    $active = [
                        'operationId' => $op['id'],
                        'usageId' => $op['usage_id'],
                        'state' => 'pending',
                        'admittedAt' => $op['admitted_at'],
                        'priceCredits' => (string) $op['price_credits'],
                    ];
                }
                continue;
            }
            if ($op['state'] === 'completed' && $completed === null) {
                if (($op['erased_at'] ?? null) !== null ||
                    ($op['retention_expires_at'] ?? null) === null ||
                    $op['retention_expires_at'] <= $now ||
                    $op['evidence_ciphertext'] === null) {
                    continue;
                }
                $projected = $this->projectOperationLocked($pdo, $tenantId, $op, $now);
                if ($projected['identityChanged']) {
                    continue;
                }
                $completed = [
                    'operationId' => $projected['id'],
                    'usageId' => $projected['usageId'],
                    'outcome' => $projected['outcome'],
                    'matchedFields' => $projected['matchedFields'],
                    'fieldOutcomes' => $projected['fieldOutcomes'],
                    'hasDateOfBirth' => $projected['hasDateOfBirth'],
                    'completedAt' => $projected['completedAt'],
                    'freshUntil' => $projected['freshUntil'],
                    'fresh' => $projected['fresh'],
                    'stale' => $projected['stale'],
                    'settledCredits' => $projected['settledCredits'],
                ];
                continue;
            }
            if ($op['state'] === 'waived' && $failed === null) {
                $failed = [
                    'operationId' => $op['id'],
                    'usageId' => $op['usage_id'],
                    'state' => 'failed',
                    'outcome' => 'failed',
                    'completedAt' => $op['completed_at'],
                    'settledCredits' => '0.0000',
                ];
            }
        }
        return [$active, $completed, $failed];
    }

    private function enforceLimitsLocked(PDO $pdo, string $tenantId, string $fingerprintHash, array $limits, string $now): void
    {
        $cooldownCutoff = self::after($now, -$limits['sameInputCooldownSeconds']);
        $q = $pdo->prepare('SELECT COUNT(*) FROM phone_verification_operation
            WHERE tenant_id = ? AND fingerprint_hash = ? AND admitted_at > ? AND deleted = FALSE');
        $q->execute([$tenantId, $fingerprintHash, $cooldownCutoff]);
        if ((int) $q->fetchColumn() > 0) {
            throw Conflict::createWithBody(
                'Please wait before starting another verification for the same phone and identity.',
                '{"reason":"cooldown_active"}',
            );
        }

        $q = $pdo->prepare("SELECT COUNT(*) FROM phone_verification_operation
            WHERE tenant_id = ? AND state IN ('admitted', 'claimed') AND deadline_at > ? AND deleted = FALSE");
        $q->execute([$tenantId, $now]);
        if ((int) $q->fetchColumn() >= $limits['maxOutstandingPerTenant']) {
            throw Conflict::createWithBody(
                'Too many phone verification checks are currently in progress.',
                '{"reason":"rate_limited","limit":"outstanding"}',
            );
        }

        $minuteCutoff = self::after($now, -60);
        $q = $pdo->prepare('SELECT COUNT(*) FROM phone_verification_operation
            WHERE tenant_id = ? AND admitted_at > ? AND deleted = FALSE');
        $q->execute([$tenantId, $minuteCutoff]);
        if ((int) $q->fetchColumn() >= $limits['maxPerMinutePerTenant']) {
            throw Conflict::createWithBody(
                'Per-minute phone verification limit reached.',
                '{"reason":"rate_limited","limit":"per_minute"}',
            );
        }

        $dayStart = substr($now, 0, 10) . ' 00:00:00';
        $dayEnd = substr($now, 0, 10) . ' 23:59:59';
        $q = $pdo->prepare('SELECT COUNT(*) FROM phone_verification_operation
            WHERE tenant_id = ? AND admitted_at >= ? AND admitted_at <= ? AND deleted = FALSE');
        $q->execute([$tenantId, $dayStart, $dayEnd]);
        if ((int) $q->fetchColumn() >= $limits['maxPerDayPerTenant']) {
            throw Conflict::createWithBody(
                'Daily phone verification limit reached.',
                '{"reason":"rate_limited","limit":"per_day"}',
            );
        }
    }

    private function insertRequestBinding(
        PDO $pdo,
        string $tenantId,
        string $contactId,
        string $actorId,
        string $requestKey,
        string $commandHash,
        string $resolution,
        string $operationId,
        string $now,
    ): void {
        $pdo->prepare('INSERT INTO phone_verification_request
            (id, tenant_id, contact_id, actor_id, request_key, command_hash, resolution, operation_id, created_at)
            VALUES (?,?,?,?,?,?,?,?,?)')->execute([
                RecordId::generate(),
                $tenantId,
                $contactId,
                $actorId,
                $requestKey,
                $commandHash,
                $resolution,
                $operationId,
                $now,
            ]);
    }

    private function persistDateOfBirth(string $tenantId, string $contactId, string $dob): void
    {
        $pdo = $this->entityManager->getPDO();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Contact DOB save must own its transaction before wallet admission.');
        }
        if ($this->records !== null) {
            $this->records->get('Contact')->update($contactId, (object) ['dateOfBirth' => $dob]);
            $updated = $this->entityManager->getEntityById('Contact', $contactId);
            if (!$updated || $updated->get('tenantId') !== $tenantId || $updated->get('dateOfBirth') !== $dob) {
                throw new Conflict('Contact date of birth could not be saved.');
            }
            return;
        }
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('SELECT id FROM contact WHERE id = ? AND tenant_id = ? AND deleted = FALSE FOR UPDATE');
            $q->execute([$contactId, $tenantId]);
            if (!$q->fetchColumn()) {
                throw new NotFound('Unknown verification Contact.');
            }
            $pdo->prepare('UPDATE contact SET date_of_birth = ? WHERE id = ? AND tenant_id = ?')
                ->execute([$dob, $contactId, $tenantId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function hmacKey(): string
    {
        return IdentityInput::hmacKey((string) $this->config->get('cryptKey', 'feature-credits-default-key'));
    }
}
