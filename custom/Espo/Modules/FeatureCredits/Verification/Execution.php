<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Verification;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Crypt;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCredits\Accounting\Amount;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Accounting\FixedSettlement;
use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use Espo\Modules\FeatureCredits\Accounting\WalletLock;
use Espo\ORM\EntityManager;
use PDO;

/**
 * At-most-once verification submission, email status polling, atomic settlement, deadline recovery,
 * and 90-day retention cleanup (D11–D12, D16, D19–D21, D24–D25).
 */
class Execution
{
    public function __construct(
        private EntityManager $entityManager,
        private WalletLock $walletLock,
        private ActorAccess $actors,
        private CapabilityGate $capability,
        private TwilioLookup $provider,
        private FixedSettlement $settlement,
        private Crypt $crypt,
        private Config $config,
        private Clock $clock,
        private ?Log $log = null,
        private ?BounceBanLookup $emailProvider = null,
        private ?WahaLookup $whatsAppProvider = null,
    ) {}

    /**
     * Background job entry. Persists one-time dispatch claim before HTTP; never holds DB locks over HTTP.
     */
    public function run(string $tenantId, string $operationId): void
    {
        $claim = $this->claim($tenantId, $operationId);
        if ($claim === null) {
            return;
        }
        $timeoutMs = $this->dispatchWindow($tenantId, $operationId, $claim['token']);
        if ($timeoutMs === null) {
            return;
        }
        try {
            $result = match ($claim['kind']) {
                Kind::EMAIL => ($this->emailProvider ?? new BounceBanLookup(new BounceBanCredentials(), new BounceBanTransport()))
                    ->verify($claim['identity']['email'], $claim['taskId'], $timeoutMs),
                Kind::WHATSAPP => ($this->whatsAppProvider ?? new WahaLookup(new WahaCredentials(), new WahaTransport()))
                    ->verify($claim['identity']['phone'], $timeoutMs),
                default => $this->provider->verify($claim['identity'], $timeoutMs),
            };
        } catch (\Throwable) {
            $result = VerificationResult::failed('provider_exception');
        }
        $this->record($tenantId, $operationId, $claim['token'], $result);
    }

    /**
     * Claim an admitted verification operation for one-time supplier dispatch.
     * Never returns a claim for an already claimed operation (D12).
     */
    public function claim(string $tenantId, string $operationId, bool $dispatch = true): ?array
    {
        GrantInput::identity($tenantId);
        GrantInput::identity($operationId);

        return $this->walletLock->run(
            $tenantId,
            function (PDO $pdo, array $wallet, string $now) use ($tenantId, $operationId, $dispatch): ?array {
                [$op, $usage, $hold] = $this->loadLocked($pdo, $tenantId, $operationId);
                $kind = $op['verification_kind'] ?? Kind::PHONE;
                $hasTask = $kind === Kind::EMAIL && $op['supplier_task_ciphertext'] !== null;
                if (in_array($op['state'], ['completed', 'waived'], true) || $usage['state'] !== 'admitted') {
                    return null;
                }

                // Check Contact existence and erasure first (D24).
                if (!$this->contactExistsLocked($pdo, $tenantId, $op['contact_id'])) {
                    $this->waiveLocked($pdo, $wallet, $now, $op, $usage, $hold, 'cancelled', 'contact_erased', true);
                    return null;
                }

                // Already claimed operations are never resent (D12).
                // If their lease or deadline has expired, waive and close at zero.
                if ($op['state'] === 'claimed') {
                    if ($op['deadline_at'] <= $now || (!$hasTask && $op['lease_until'] !== null && $op['lease_until'] <= $now)) {
                        $this->waiveLocked($pdo, $wallet, $now, $op, $usage, $hold, 'unavailable', 'deadline');
                    } elseif ($hasTask && $op['lease_until'] !== null && $op['lease_until'] <= $now) {
                        // A lost read-only status response can be recovered; a lost submission never can.
                        $pdo->prepare("UPDATE phone_verification_operation SET state = 'awaiting', claim_token = NULL, lease_until = NULL WHERE id = ?")
                            ->execute([$operationId]);
                    }
                    return null;
                }

                if ($op['deadline_at'] <= $now) {
                    $this->waiveLocked($pdo, $wallet, $now, $op, $usage, $hold, 'unavailable', 'deadline');
                    return null;
                }

                // D20, D25: The dispatch kill switch blocks unclaimed work; capability changes before claim waive.
                $cap = $this->capability->inspect($tenantId, $kind);
                if (!$hasTask && (!$cap['available'] || $cap['policyVersion'] !== $op['policy_version'])) {
                    $reason = $cap['reason'] === 'kill_switch' ? 'kill_switch' : 'capability_changed';
                    $this->waiveLocked($pdo, $wallet, $now, $op, $usage, $hold, 'unavailable', $reason);
                    return null;
                }

                $snapshotIdentity = $this->decryptSnapshot($op);

                // Revalidate original actor authorization, rejection status, and unchanged pre-dispatch inputs (D19, D21, D24).
                $precheck = $hasTask ? $this->deliveryAuthorizationCheck($pdo, $tenantId, $op, $snapshotIdentity)
                    : $this->preDispatchCheck($pdo, $tenantId, $op, $snapshotIdentity, $now);
                if ($precheck !== 'ok') {
                    $this->waiveLocked($pdo, $wallet, $now, $op, $usage, $hold, 'cancelled', $precheck);
                    return null;
                }

                if (!$dispatch) {
                    return null;
                }

                if ($hasTask && ((int) $op['poll_count'] >= 10 || ($op['next_poll_at'] !== null && $op['next_poll_at'] > $now))) {
                    return null;
                }
                if ($kind === Kind::EMAIL && !ProviderLimit::claim($pdo, $now, $hasTask)) return null;

                $token = bin2hex(random_bytes(24));
                $leaseUntil = min(Operations::after($now, 30), $op['deadline_at']);
                $pdo->prepare("UPDATE phone_verification_operation
                    SET state = 'claimed', claim_token = ?, claimed_at = COALESCE(claimed_at, ?), lease_until = ?,
                        poll_count = poll_count + ?
                    WHERE id = ? AND tenant_id = ?")->execute([
                        $token,
                        $now,
                        $leaseUntil,
                        $hasTask ? 1 : 0,
                        $operationId,
                        $tenantId,
                    ]);

                return [
                    'id' => $operationId,
                    'token' => $token,
                    'identity' => $snapshotIdentity,
                    'kind' => $kind,
                    'taskId' => $hasTask ? $this->crypt->decrypt($op['supplier_task_ciphertext']) : null,
                ];
            },
        );
    }

    /**
     * Recheck after any scheduler pause and bound the HTTP timeout to the remaining lease/deadline.
     */
    public function dispatchWindow(string $tenantId, string $operationId, string $token): ?int
    {
        return $this->walletLock->run(
            $tenantId,
            function (PDO $pdo, array $wallet, string $now) use ($tenantId, $operationId, $token): ?int {
                [$op, $usage, $hold] = $this->loadLocked($pdo, $tenantId, $operationId);
                if ($op['state'] !== 'claimed' || $usage['state'] !== 'admitted' ||
                    !is_string($op['claim_token']) || !hash_equals($op['claim_token'], $token)) {
                    return null;
                }
                if (!$this->contactExistsLocked($pdo, $tenantId, $op['contact_id'])) {
                    $this->waiveLocked($pdo, $wallet, $now, $op, $usage, $hold, 'cancelled', 'contact_erased', true);
                    return null;
                }
                $end = min($op['deadline_at'], $op['lease_until'] ?? $op['deadline_at']);
                if ($end <= $now) {
                    $this->waiveLocked($pdo, $wallet, $now, $op, $usage, $hold, 'unavailable', 'deadline');
                    return null;
                }
                $snapshotIdentity = $this->decryptSnapshot($op);
                $hasTask = ($op['verification_kind'] ?? Kind::PHONE) === Kind::EMAIL && $op['supplier_task_ciphertext'] !== null;
                if (($op['verification_kind'] ?? Kind::PHONE) === Kind::WHATSAPP &&
                    !$this->capability->inspect($tenantId, Kind::WHATSAPP)['available']) {
                    $this->waiveLocked($pdo, $wallet, $now, $op, $usage, $hold, 'unavailable', 'capability_changed');
                    return null;
                }
                $precheck = $hasTask ? $this->deliveryAuthorizationCheck($pdo, $tenantId, $op, $snapshotIdentity)
                    : $this->preDispatchCheck($pdo, $tenantId, $op, $snapshotIdentity, $now);
                if ($precheck !== 'ok') {
                    $this->waiveLocked($pdo, $wallet, $now, $op, $usage, $hold, 'cancelled', $precheck);
                    return null;
                }
                $zone = new DateTimeZone('UTC');
                $remainingMs = ((new DateTimeImmutable($end, $zone))->getTimestamp() -
                    (new DateTimeImmutable($now, $zone))->getTimestamp()) * 1000;
                return min(CapabilityGate::PROVIDER_TIMEOUT_MS, max(1, $remainingMs));
            },
        );
    }

    /**
     * Publish the result and atomically settle the kind-specific price; failed/waived checks cost zero.
     * Late results after closure are ignored and never reopen an operation (D11–D12, D19, D24).
     */
    public function record(string $tenantId, string $operationId, string $token, VerificationResult | EmailResult | WhatsAppResult $result): void
    {
        GrantInput::identity($tenantId);
        GrantInput::identity($operationId);

        $this->walletLock->run(
            $tenantId,
            function (PDO $pdo, array $wallet, string $now) use ($tenantId, $operationId, $token, $result): void {
                [$op, $usage, $hold] = $this->loadLocked($pdo, $tenantId, $operationId);
                if ($op['state'] !== 'claimed' || $usage['state'] !== 'admitted' ||
                    !is_string($op['claim_token']) || !hash_equals($op['claim_token'], $token)) {
                    return;
                }

                // D24: Revoke pending delivery on Contact erasure or loss of authorization.
                if (!$this->contactExistsLocked($pdo, $tenantId, $op['contact_id'])) {
                    $this->waiveLocked($pdo, $wallet, $now, $op, $usage, $hold, 'cancelled', 'contact_erased', true);
                    return;
                }
                $snapshotIdentity = $this->decryptSnapshot($op);
                $deliveryAuth = $this->deliveryAuthorizationCheck($pdo, $tenantId, $op, $snapshotIdentity);
                if ($deliveryAuth !== 'ok') {
                    $this->waiveLocked($pdo, $wallet, $now, $op, $usage, $hold, 'cancelled', $deliveryAuth);
                    return;
                }

                if ($op['deadline_at'] <= $now) {
                    $this->waiveLocked($pdo, $wallet, $now, $op, $usage, $hold, 'unavailable', 'deadline');
                    return;
                }

                $kind = $op['verification_kind'] ?? Kind::PHONE;
                $emailResult = $result instanceof EmailResult;
                $whatsAppResult = $result instanceof WhatsAppResult;
                if ($emailResult && $kind === Kind::EMAIL && $result->status === 'pending') {
                    if ($op['supplier_task_ciphertext'] !== null &&
                        !hash_equals($this->crypt->decrypt($op['supplier_task_ciphertext']), $result->taskId ?? '')) {
                        $this->waiveLocked($pdo, $wallet, $now, $op, $usage, $hold, 'failed', 'provider_failed');
                        return;
                    }
                    $next = Operations::after($now, 30);
                    if ($result->tryAgainAt !== null) {
                        $next = max($next, gmdate('Y-m-d H:i:s', $result->tryAgainAt));
                    }
                    $pdo->prepare("UPDATE phone_verification_operation SET state = 'awaiting', supplier_task_ciphertext = ?,
                        next_poll_at = ?, claim_token = NULL, lease_until = NULL WHERE id = ? AND tenant_id = ?")
                        ->execute([$this->crypt->encrypt($result->taskId), min($next, $op['deadline_at']), $operationId, $tenantId]);
                    return;
                }
                $valid = $whatsAppResult ? $kind === Kind::WHATSAPP && $result->registered !== null : ($emailResult
                    ? $kind === Kind::EMAIL && $result->status === 'completed' && $result->evidence !== null
                    : $kind === Kind::PHONE && $result->billable && $result->fieldOutcomes !== null);
                if (!$valid) {
                    $this->waiveLocked(
                        $pdo,
                        $wallet,
                        $now,
                        $op,
                        $usage,
                        $hold,
                        'failed',
                        ($emailResult || $whatsAppResult) ? 'provider_failed' : ($result->failureReason ?? 'provider_failed'),
                    );
                    return;
                }

                // D19: Identity inputs were frozen at dispatch; later edits do not cancel a completed check.
                $evidencePayload = $whatsAppResult ? ['registered' => $result->registered] : ($emailResult ? $result->evidence : [
                    'matchedFields' => array_values($result->matchedFields),
                    'fieldOutcomes' => $result->fieldOutcomes,
                ]);
                $evidenceCiphertext = $this->crypt->encrypt(json_encode($evidencePayload, JSON_THROW_ON_ERROR));
                $evidenceHash = Operations::evidenceHash($tenantId, $operationId, $result->outcome, $evidencePayload);
                $freshUntil = Operations::after($now, CapabilityGate::FRESH_REUSE_SECONDS);
                $retentionExpiresAt = Operations::after($now, CapabilityGate::RETENTION_SECONDS);
                if ($emailResult) {
                    if ($op['supplier_task_ciphertext'] !== null &&
                        !hash_equals($this->crypt->decrypt($op['supplier_task_ciphertext']), $result->taskId ?? '')) {
                        $this->waiveLocked($pdo, $wallet, $now, $op, $usage, $hold, 'failed', 'provider_failed');
                        return;
                    }
                    $pdo->prepare('UPDATE phone_verification_operation SET supplier_task_ciphertext = ? WHERE id = ?')
                        ->execute([$this->crypt->encrypt($result->taskId), $operationId]);
                }

                $pdo->prepare("UPDATE phone_verification_operation
                    SET state = 'completed', outcome = ?, failure_reason = NULL, completed_at = ?,
                        fresh_until = ?, retention_expires_at = ?, evidence_ciphertext = ?, evidence_hash = ?
                    WHERE id = ? AND tenant_id = ?")->execute([
                        $result->outcome,
                        $now,
                        $freshUntil,
                        $retentionExpiresAt,
                        $evidenceCiphertext,
                        $evidenceHash,
                        $operationId,
                        $tenantId,
                    ]);

                $this->settlement->settleLocked(
                    $pdo,
                    $wallet,
                    $now,
                    $usage,
                    $hold,
                    Amount::fromString(Kind::price($kind)),
                );
            },
        );
    }

    /**
     * Deadline/crash recovery without any provider I/O (D12, D25).
     */
    public function recover(string $tenantId, string $operationId): void
    {
        $this->claim($tenantId, $operationId, false);
    }

    /**
     * Bounded scheduled discovery of open verification operations; no personal data in job payloads.
     */
    public function due(int $limit = 20): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Invalid phone verification job batch.');
        }
        $q = $this->entityManager->getPDO()->prepare(
            "SELECT o.id, o.tenant_id, o.state FROM phone_verification_operation o
             JOIN credit_usage u ON u.id = o.usage_id AND u.tenant_id = o.tenant_id
             WHERE o.deleted = FALSE AND u.deleted = FALSE AND u.state = 'admitted'
              AND o.state IN ('admitted', 'claimed', 'awaiting')
              AND (o.state <> 'awaiting' OR o.next_poll_at <= ? OR o.deadline_at <= ?)
             ORDER BY o.admitted_at, o.id LIMIT " . $limit
        );
        $q->execute([$this->clock->now(), $this->clock->now()]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Scrub expired 90-day personal identity/evidence ciphertext while retaining financial receipts (D16, D25).
     */
    public function expireEvidence(): void
    {
        $now = $this->clock->now();
        $this->entityManager->getPDO()->prepare(
            'UPDATE phone_verification_operation
             SET identity_ciphertext = NULL, evidence_ciphertext = NULL, supplier_task_ciphertext = NULL, erased_at = COALESCE(erased_at, ?)
             WHERE retention_expires_at <= ?
             AND (identity_ciphertext IS NOT NULL OR evidence_ciphertext IS NOT NULL OR supplier_task_ciphertext IS NOT NULL)'
        )->execute([$now, $now]);
    }

    /**
     * Monitor open operations older than 6 minutes and missed recovery heartbeats,
     * and return sanitized operational counts without PII (D25).
     */
    public function diagnostics(): array
    {
        $pdo = $this->entityManager->getPDO();
        $now = $this->clock->now();
        $cfg = $this->capability->configuration();
        $lastRecoveryAt = $cfg['lastRecoveryAt'];
        $heartbeatMissed = $lastRecoveryAt !== null && Operations::after($lastRecoveryAt, 120) < $now;

        $staleCutoff = Operations::after($now, -360);
        $q = $pdo->prepare(
            "SELECT COUNT(*) FROM phone_verification_operation
             WHERE deleted = FALSE AND state IN ('admitted', 'claimed', 'awaiting') AND admitted_at <= ?"
        );
        $q->execute([$staleCutoff]);
        $staleOpenCount = (int) $q->fetchColumn();

        if ($staleOpenCount > 0) {
            $this->log?->error('Phone verification alert: open operations older than 6 minutes detected.', [
                'staleOpenCount' => $staleOpenCount,
            ]);
        }
        if ($heartbeatMissed) {
            $this->log?->error('Phone verification alert: recovery heartbeat missed.', [
                'lastRecoveryAt' => $lastRecoveryAt,
            ]);
        }

        $q = $pdo->query(
            'SELECT verification_kind, state, outcome, failure_reason, COUNT(*) AS count, SUM(poll_count) AS poll_count
             FROM phone_verification_operation
             WHERE deleted = FALSE
             GROUP BY verification_kind, state, outcome, failure_reason'
        );
        $breakdown = $q->fetchAll(PDO::FETCH_ASSOC);

        $q = $pdo->query(
            "SELECT COUNT(*) FROM phone_verification_operation
             WHERE deleted = FALSE AND state = 'waived' AND claimed_at IS NOT NULL"
        );
        $waivedAfterClaimCount = (int) $q->fetchColumn();

        return [
            'observedAt' => $now,
            'lastRecoveryAt' => $lastRecoveryAt,
            'heartbeatMissed' => $heartbeatMissed,
            'staleOpenCount' => $staleOpenCount,
            'waivedAfterClaimCount' => $waivedAfterClaimCount,
            'breakdown' => $breakdown,
        ];
    }

    /**
     * @return array{0: array, 1: array, 2: array}
     */
    private function loadLocked(PDO $pdo, string $tenantId, string $operationId): array
    {
        $q = $pdo->prepare('SELECT * FROM phone_verification_operation WHERE id = ? AND tenant_id = ? FOR UPDATE');
        $q->execute([$operationId, $tenantId]);
        $op = $q->fetch(PDO::FETCH_ASSOC);
        if (!$op || $op['deleted']) {
            throw new NotFound('Unknown phone verification operation.');
        }
        $q = $pdo->prepare('SELECT * FROM credit_usage WHERE id = ? AND tenant_id = ?');
        $q->execute([$op['usage_id'], $tenantId]);
        $usage = $q->fetch(PDO::FETCH_ASSOC);
        $q = $pdo->prepare('SELECT * FROM credit_reservation WHERE id = ? AND usage_id = ? AND tenant_id = ?');
        $q->execute([$op['reservation_id'], $op['usage_id'], $tenantId]);
        $hold = $q->fetch(PDO::FETCH_ASSOC);
        if (!$usage || !$hold || $usage['deleted'] || $hold['deleted'] ||
            $usage['operation_type'] !== 'verification' ||
            $usage['operation_key'] !== 'verification:' . $operationId ||
            $usage['execution_id'] !== 'verification:' . $operationId ||
            $hold['execution_id'] !== $usage['execution_id'] ||
            ($usage['state'] === 'admitted' && $hold['state'] !== 'held')) {
            throw new Conflict('Invalid phone verification financial binding.');
        }
        return [$op, $usage, $hold];
    }

    private function contactExistsLocked(PDO $pdo, string $tenantId, string $contactId): bool
    {
        $q = $pdo->prepare('SELECT id FROM contact WHERE id = ? AND tenant_id = ? AND deleted = FALSE FOR UPDATE');
        $q->execute([$contactId, $tenantId]);
        return (bool) $q->fetchColumn();
    }

    private function decryptSnapshot(array $op): array
    {
        if (($op['erased_at'] ?? null) !== null || $op['identity_ciphertext'] === null) {
            throw new Conflict('Phone verification identity snapshot is unavailable.');
        }
        $snapshot = json_decode($this->crypt->decrypt($op['identity_ciphertext']), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($snapshot)) {
            throw new Conflict('Invalid phone verification identity snapshot.');
        }
        Operations::verifyIdentityHash($op, $snapshot);
        return $snapshot;
    }

    private function preDispatchCheck(PDO $pdo, string $tenantId, array $op, array $snapshot, string $now): string
    {
        $auth = $this->deliveryAuthorizationCheck($pdo, $tenantId, $op, $snapshot);
        if ($auth !== 'ok') {
            return $auth;
        }
        try {
            $access = $this->actors->forActor($op['actor_id']);
            if (($op['verification_kind'] ?? Kind::PHONE) === Kind::WHATSAPP) {
                $contact = $access->contact($tenantId, $op['contact_id'], Kind::WHATSAPP);
                $resolved = $access->resolveSource($pdo, $tenantId, $contact, $snapshot['source'], Kind::WHATSAPP);
                return $resolved['value'] === $snapshot['phone'] ? 'ok' : 'identity_changed';
            }
            if (($op['verification_kind'] ?? Kind::PHONE) === Kind::EMAIL) {
                $contact = $access->contact($tenantId, $op['contact_id'], Kind::EMAIL);
                $resolved = $access->resolveSource($pdo, $tenantId, $contact, $snapshot['source'], Kind::EMAIL);
                return $resolved['value'] === $snapshot['email'] ? 'ok' : 'identity_changed';
            }
            $contact = $access->contact($tenantId, $op['contact_id']);
            $identity = $access->contactIdentity($contact, $now);
            if (!$identity['nameReady'] || !$identity['dateOfBirthValid']) {
                return 'identity_changed';
            }
            if ($identity['name']['firstName'] !== ($snapshot['firstName'] ?? null) ||
                $identity['name']['lastName'] !== ($snapshot['lastName'] ?? null) ||
                $identity['dateOfBirth'] !== ($snapshot['dateOfBirth'] ?? null)) {
                return 'identity_changed';
            }
            $hmacKey = IdentityInput::hmacKey((string) $this->config->get('cryptKey', 'feature-credits-default-key'));
            $expectedFingerprint = IdentityInput::fingerprintHash(
                $hmacKey,
                $tenantId,
                $op['contact_id'],
                (string) $snapshot['phone'],
                $identity['name']['firstName'],
                $identity['name']['lastName'],
                $identity['dateOfBirth'],
                $op['policy_version'],
            );
            if (!hash_equals($op['fingerprint_hash'], $expectedFingerprint)) {
                return 'identity_changed';
            }
            if (!$this->phoneStillPresentOnContactOrPurchases($pdo, $access, $tenantId, $contact, (string) $snapshot['phone'])) {
                return 'identity_changed';
            }
            return 'ok';
        } catch (\Throwable) {
            return 'authorization_revoked';
        }
    }

    private function deliveryAuthorizationCheck(PDO $pdo, string $tenantId, array $op, array $snapshot): string
    {
        try {
            $access = $this->actors->forActor($op['actor_id']);
            if (($op['verification_kind'] ?? Kind::PHONE) === Kind::WHATSAPP) {
                $contact = $access->contact($tenantId, $op['contact_id'], Kind::WHATSAPP);
                $access->authorizeWhatsAppSource($pdo, $tenantId, $contact, $snapshot['source']);
                return 'ok';
            }
            if (($op['verification_kind'] ?? Kind::PHONE) === Kind::EMAIL) {
                $contact = $access->contact($tenantId, $op['contact_id'], Kind::EMAIL);
                $access->authorizeEmailSource($pdo, $tenantId, $contact, $snapshot['source']);
                return $access->isEmailOwnershipRejected($pdo, $tenantId, $op['contact_id'], $snapshot['email'])
                    ? 'ownership_rejected' : 'ok';
            }
            $contact = $access->contact($tenantId, $op['contact_id']);
            $identity = $access->contactIdentity($contact, $this->clock->now());
            if (($snapshot['dateOfBirth'] ?? null) !== null && !$identity['dateOfBirthReadable']) {
                return 'authorization_revoked';
            }
            $phone = (string) ($snapshot['phone'] ?? '');
            if ($access->isPhoneOwnershipRejected($pdo, $tenantId, $op['contact_id'], $phone, $phone)) {
                return 'ownership_rejected';
            }
            return 'ok';
        } catch (\Throwable) {
            return 'authorization_revoked';
        }
    }

    private function phoneStillPresentOnContactOrPurchases(
        PDO $pdo,
        Access $access,
        string $tenantId,
        \Espo\ORM\Entity $contact,
        string $e164,
    ): bool {
        $primary = $contact->get('phoneNumber');
        if (is_string($primary) && BrazilianPhone::e164($primary) === $e164) {
            return true;
        }
        $q = $pdo->prepare("SELECT source_id FROM contact_channel_identity
            WHERE tenant_id = ? AND contact_id = ? AND deleted = FALSE
            AND channel_type IN ('whatsapp', 'sms')
            AND (ownership_status IS NULL OR ownership_status <> 'rejected')");
        $q->execute([$tenantId, $contact->getId()]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $sourceId) {
            if (is_string($sourceId) && BrazilianPhone::e164($sourceId) === $e164) {
                return true;
            }
        }
        $q = $pdo->prepare("SELECT a.id FROM enrichment_attempt a
            JOIN enrichment_quote q ON q.id = a.quote_id AND q.tenant_id = a.tenant_id
            WHERE a.tenant_id = ? AND q.target_type = 'Contact' AND q.target_id = ?
            AND a.product = 'phone' AND a.state = 'matched' AND a.purchased_at IS NOT NULL
            AND a.erased_at IS NULL AND a.deleted = FALSE AND q.deleted = FALSE");
        $q->execute([$tenantId, $contact->getId()]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $attemptId) {
            try {
                $resolved = $access->resolveSource($pdo, $tenantId, $contact, [
                    'kind' => 'purchased',
                    'receiptId' => $attemptId,
                    'value' => $e164,
                ]);
                if ($resolved['e164'] === $e164) {
                    return true;
                }
            } catch (\Throwable) {
                continue;
            }
        }
        return false;
    }

    private function waiveLocked(
        PDO $pdo,
        array $wallet,
        string $now,
        array $op,
        array $usage,
        array $hold,
        string $outcome,
        string $failureReason,
        bool $erase = false,
    ): void {
        if ($erase) {
            $pdo->prepare("UPDATE phone_verification_operation
                SET state = 'waived', outcome = ?, failure_reason = ?, completed_at = ?,
                    identity_ciphertext = NULL, evidence_ciphertext = NULL, supplier_task_ciphertext = NULL,
                    evidence_hash = NULL, fresh_until = NULL, erased_at = COALESCE(erased_at, ?)
                WHERE id = ? AND tenant_id = ?")->execute([
                    $outcome,
                    $failureReason,
                    $now,
                    $now,
                    $op['id'],
                    $op['tenant_id'],
                ]);
        } else {
            $pdo->prepare("UPDATE phone_verification_operation
                SET state = 'waived', outcome = ?, failure_reason = ?, completed_at = ?,
                    evidence_ciphertext = NULL, evidence_hash = NULL, fresh_until = NULL,
                    supplier_task_ciphertext = NULL, retention_expires_at = ?
                WHERE id = ? AND tenant_id = ?")->execute([
                    $outcome,
                    $failureReason,
                    $now,
                    Operations::after($now, CapabilityGate::RETENTION_SECONDS),
                    $op['id'],
                    $op['tenant_id'],
                ]);
        }
        $this->settlement->settleLocked($pdo, $wallet, $now, $usage, $hold, Amount::fromString('0'));
    }
}
