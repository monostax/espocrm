<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Verification;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Crypt;
use Espo\Entities\User;
use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use Espo\Modules\FeatureCredits\Enrichment\FieldCache;
use Espo\Modules\FeatureCredits\Enrichment\Quotes;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use InvalidArgumentException;
use PDO;

/**
 * Authorization, Contact identity extraction, and server-side phone subject resolution (D10, D14, D21–D23).
 */
class Access
{
    public function __construct(
        private User $user,
        private Acl $acl,
        private UserTenantResolver $tenants,
        private EntityManager $entityManager,
        private Crypt $crypt,
    ) {}

    public function actorId(): string
    {
        if (!$this->user->getId() || $this->user->isPortal() || $this->user->get('deleted')) {
            throw new Forbidden('Phone verification access required.');
        }
        return $this->user->getId();
    }

    public function isHumanActor(): bool
    {
        $this->actorId();
        return !$this->user->isApi() && !$this->user->isPortal() && !$this->user->isSystem();
    }

    public function contact(string $tenantId, string $contactId): Entity
    {
        $this->actorId();
        GrantInput::identity($tenantId);
        GrantInput::identity($contactId);
        if ($this->acl->getPermissionLevel('useEnrichmentCredits') !== 'yes' ||
            !$this->tenants->canActForTenant($this->user, $tenantId) ||
            !$this->acl->checkScope('Contact', 'read') ||
            !$this->acl->checkField('Contact', 'phoneNumber', 'read') ||
            !$this->acl->checkField('Contact', 'firstName', 'read') ||
            !$this->acl->checkField('Contact', 'lastName', 'read')) {
            throw new Forbidden('Phone verification access required.');
        }
        $tenant = $this->entityManager->getEntityById('Tenant', $tenantId);
        $contact = $this->entityManager->getEntityById('Contact', $contactId);
        if (!$tenant || $tenant->get('deleted') || !$contact || $contact->get('deleted') ||
            $contact->get('tenantId') !== $tenantId || !$this->acl->check($contact, 'read')) {
            throw new Forbidden('Phone verification access required.');
        }
        return $contact;
    }

    /**
     * Extract readable Contact identity attributes (D14, Section 5).
     * Unreadable fields are never silently sent to Twilio.
     */
    public function contactIdentity(Entity $contact, string $nowUtc): array
    {
        $firstName = $this->acl->checkField('Contact', 'firstName', 'read') ? $contact->get('firstName') : null;
        $lastName = $this->acl->checkField('Contact', 'lastName', 'read') ? $contact->get('lastName') : null;
        $fullName = $this->acl->checkField('Contact', 'name', 'read') ? $contact->get('name') : null;
        $split = IdentityInput::splitBrazilName(
            is_string($firstName) ? $firstName : null,
            is_string($lastName) ? $lastName : null,
            is_string($fullName) ? $fullName : null,
        );

        $dobReadable = $this->acl->checkField('Contact', 'dateOfBirth', 'read');
        $rawDob = null;
        if ($dobReadable) {
            $candidate = $contact->get('dateOfBirth');
            if ((!is_string($candidate) || trim($candidate) === '') &&
                $this->acl->checkField('Contact', 'dataNascimento', 'read')) {
                $candidate = $contact->get('dataNascimento');
            }
            if (is_string($candidate) && trim($candidate) !== '') {
                $rawDob = trim($candidate);
            }
        }
        $dob = null;
        $dobValid = true;
        if ($rawDob !== null) {
            try {
                $dob = IdentityInput::validateDateOfBirth($rawDob, $nowUtc);
            } catch (InvalidArgumentException) {
                $dobValid = false;
            }
        }

        $dobEditable = $this->isHumanActor() &&
            $this->acl->check($contact, 'edit') &&
            $this->acl->checkField('Contact', 'dateOfBirth', 'edit');

        return [
            'name' => $split,
            'nameReady' => $split !== null,
            'dateOfBirth' => $dob,
            'hasDateOfBirth' => $dob !== null,
            'dateOfBirthValid' => $dobValid,
            'dateOfBirthReadable' => $dobReadable,
            'dateOfBirthEditable' => $dobEditable,
        ];
    }

    public function assertDateOfBirthWriteAccess(Entity $contact): void
    {
        if (!$this->isHumanActor() ||
            !$this->acl->check($contact, 'edit') ||
            !$this->acl->checkField('Contact', 'dateOfBirth', 'edit')) {
            throw new Forbidden('Contact date of birth edit access required.');
        }
    }

    /**
     * Resolve a phone selection server-side from a saved Contact phone/channel or a purchased phone row (D8, D21–D23).
     *
     * @return array{rawPhone: string, e164: ?string, phoneReason: ?string, ownershipRejected: bool}
     */
    public function resolveSource(PDO $pdo, string $tenantId, Entity $contact, array $source): array
    {
        $kind = $source['kind'] ?? null;
        if (!is_string($kind) || !in_array($kind, ['contact_phone', 'channel', 'purchased'], true)) {
            throw new InvalidArgumentException('Unsupported phone verification source.');
        }
        $contactId = $contact->getId();
        $resolved = match ($kind) {
            'contact_phone' => $this->resolveContactPhone($contact, $source),
            'channel' => $this->resolveChannelPhone($pdo, $tenantId, $contactId, $source),
            'purchased' => $this->resolvePurchasedPhone($pdo, $tenantId, $contactId, $source),
        };
        $norm = BrazilianPhone::normalize($resolved['rawPhone']);
        $rejected = $resolved['channelRejected'] ||
            $this->isPhoneOwnershipRejected($pdo, $tenantId, $contactId, $resolved['rawPhone'], $norm['e164']);

        return [
            'rawPhone' => $resolved['rawPhone'],
            'e164' => $norm['e164'],
            'phoneReason' => $norm['reason'],
            'ownershipRejected' => $rejected,
        ];
    }

    /** @return array{rawPhone: string, channelRejected: bool} */
    private function resolveContactPhone(Entity $contact, array $source): array
    {
        if (array_diff(array_keys($source), ['key', 'kind'])) {
            throw new InvalidArgumentException('Invalid contact_phone source descriptor.');
        }
        if (!$this->acl->checkField('Contact', 'phoneNumber', 'read')) {
            throw new Forbidden('Contact phone read access required.');
        }
        $phone = $contact->get('phoneNumber');
        if (!is_string($phone) || trim($phone) === '') {
            throw new NotFound('Contact has no saved primary phone number.');
        }
        return ['rawPhone' => trim($phone), 'channelRejected' => false];
    }

    /** @return array{rawPhone: string, channelRejected: bool} */
    private function resolveChannelPhone(PDO $pdo, string $tenantId, string $contactId, array $source): array
    {
        if (array_diff(array_keys($source), ['key', 'kind', 'id', 'channelType', 'sourceId'])) {
            throw new InvalidArgumentException('Invalid channel source descriptor.');
        }
        if (!$this->acl->checkField('Contact', 'channelIdentitiesData', 'read') ||
            !$this->acl->checkScope('ContactChannelIdentity', 'read') ||
            !$this->acl->checkField('ContactChannelIdentity', 'channelType', 'read') ||
            !$this->acl->checkField('ContactChannelIdentity', 'sourceId', 'read') ||
            !$this->acl->checkField('ContactChannelIdentity', 'ownershipStatus', 'read')) {
            throw new Forbidden('Contact channel identity read access required.');
        }

        $id = $source['id'] ?? null;
        if ($id !== null) {
            if (!is_string($id)) {
                throw new InvalidArgumentException('Invalid channel identity ID.');
            }
            GrantInput::identity($id);
            $q = $pdo->prepare("SELECT id, channel_type, source_id, ownership_status FROM contact_channel_identity
                WHERE id = ? AND tenant_id = ? AND contact_id = ? AND deleted = FALSE");
            $q->execute([$id, $tenantId, $contactId]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
        } else {
            $sourceId = $source['sourceId'] ?? null;
            $channelType = $source['channelType'] ?? 'whatsapp';
            if (!is_string($sourceId) || trim($sourceId) === '' ||
                !is_string($channelType) || !in_array($channelType, ['whatsapp', 'sms'], true)) {
                throw new InvalidArgumentException('Channel identity id or sourceId is required.');
            }
            $targetNorm = BrazilianPhone::e164($sourceId);
            $q = $pdo->prepare("SELECT id, channel_type, source_id, ownership_status FROM contact_channel_identity
                WHERE tenant_id = ? AND contact_id = ? AND channel_type = ? AND deleted = FALSE ORDER BY id");
            $q->execute([$tenantId, $contactId, $channelType]);
            $row = false;
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
                if (trim((string) $candidate['source_id']) === trim($sourceId) ||
                    ($targetNorm !== null && BrazilianPhone::e164($candidate['source_id']) === $targetNorm)) {
                    $row = $candidate;
                    break;
                }
            }
        }
        if (!$row || !in_array($row['channel_type'], ['whatsapp', 'sms'], true)) {
            throw new NotFound('Unknown Contact phone channel identity.');
        }
        $entity = $this->entityManager->getEntityById('ContactChannelIdentity', $row['id']);
        if (!$entity || !$this->acl->check($entity, 'read')) {
            throw new Forbidden('Contact channel identity read access required.');
        }
        return [
            'rawPhone' => trim((string) $row['source_id']),
            'channelRejected' => ($row['ownership_status'] ?? null) === 'rejected',
        ];
    }

    /** @return array{rawPhone: string, channelRejected: bool} */
    private function resolvePurchasedPhone(PDO $pdo, string $tenantId, string $contactId, array $source): array
    {
        if (array_diff(array_keys($source), ['key', 'kind', 'receiptId', 'index', 'value'])) {
            throw new InvalidArgumentException('Invalid purchased phone source descriptor.');
        }
        $receiptId = $source['receiptId'] ?? null;
        if (!is_string($receiptId)) {
            throw new InvalidArgumentException('Purchased receiptId is required.');
        }
        GrantInput::identity($receiptId);
        $index = $source['index'] ?? null;
        $value = $source['value'] ?? null;
        if ($index === null && $value === null) {
            throw new InvalidArgumentException('Purchased phone index or value is required.');
        }
        if ($index !== null && (!is_int($index) || $index < 0 || $index > 100)) {
            throw new InvalidArgumentException('Invalid purchased phone index.');
        }
        if ($value !== null && (!is_string($value) || trim($value) === '' || strlen($value) > 64)) {
            throw new InvalidArgumentException('Invalid purchased phone value.');
        }

        $q = $pdo->prepare('SELECT * FROM enrichment_attempt WHERE tenant_id = ? AND id = ? AND deleted = FALSE AND purchased_at IS NOT NULL');
        $q->execute([$tenantId, $receiptId]);
        $attempt = $q->fetch(PDO::FETCH_ASSOC);
        if (!$attempt) {
            throw new NotFound('Unknown purchased phone result.');
        }
        if (($attempt['erased_at'] ?? null) !== null || $attempt['result_ciphertext'] === null) {
            throw new Conflict('Purchased phone data was erased.');
        }
        $q = $pdo->prepare('SELECT * FROM enrichment_quote WHERE tenant_id = ? AND id = ?');
        $q->execute([$tenantId, $attempt['quote_id']]);
        $quote = $q->fetch(PDO::FETCH_ASSOC);
        if (!$quote || $quote['deleted'] || $quote['target_type'] !== 'Contact' || $quote['target_id'] !== $contactId ||
            $attempt['product'] !== 'phone' || $attempt['state'] !== 'matched') {
            throw new Forbidden('Purchased phone result does not belong to this Contact.');
        }
        Quotes::decode($quote);
        $values = json_decode($this->crypt->decrypt($attempt['result_ciphertext']), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($values) ||
            !hash_equals(
                $attempt['result_hash'] ?? '',
                FieldCache::resultHash($tenantId, $quote['id'], $receiptId, $attempt['provider'], $attempt['product'], $values, $attempt),
            )) {
            throw new Conflict('Purchased phone material integrity differs.');
        }
        $list = array_values($values);
        $selected = null;
        if ($index !== null) {
            if (!array_key_exists($index, $list) || !is_string($list[$index])) {
                throw new NotFound('Purchased phone index not found in receipt.');
            }
            $selected = $list[$index];
            if ($value !== null && trim($value) !== trim($selected)) {
                throw new Conflict('Purchased phone value does not match index.');
            }
        } else {
            foreach ($list as $item) {
                if (is_string($item) && trim($item) === trim((string) $value)) {
                    $selected = $item;
                    break;
                }
            }
            if ($selected === null) {
                throw new NotFound('Purchased phone value is not part of this receipt.');
            }
        }
        return ['rawPhone' => trim($selected), 'channelRejected' => false];
    }

    public function isPhoneOwnershipRejected(PDO $pdo, string $tenantId, string $contactId, string $rawPhone, ?string $e164): bool
    {
        $q = $pdo->prepare("SELECT source_id FROM contact_channel_identity
            WHERE tenant_id = ? AND contact_id = ? AND deleted = FALSE AND ownership_status = 'rejected'
            AND channel_type IN ('whatsapp', 'sms')");
        $q->execute([$tenantId, $contactId]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $rejectedSource) {
            if (!is_string($rejectedSource)) {
                continue;
            }
            if (trim($rejectedSource) === trim($rawPhone)) {
                return true;
            }
            if ($e164 !== null && BrazilianPhone::e164($rejectedSource) === $e164) {
                return true;
            }
        }
        return false;
    }
}
