<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Record\UpdateParams;
use Espo\Modules\FeatureCredits\Verification\Access;
use Espo\Modules\FeatureCredits\Verification\Kind;
use Espo\Modules\FeatureCredits\Verification\WhatsAppPhone;
use Espo\ORM\EntityManager;

/**
 * Adds a found enrichment value to its Contact, as the run-as user.
 *
 * Built inside ActorScope. The value is resolved server-side from the encrypted receipt
 * (same rules as verification: same workspace, the receipt's own Contact, intact and not
 * erased, enrichment-credit permission). The write goes through the Contact record service,
 * so record + field edit ACL, validation and the channel-identity hook all apply:
 *  - phone    → added to the Contact's phone numbers; the hook adds the WhatsApp identity
 *  - email    → added to the Contact's email addresses; the hook adds the email identity
 *  - whatsapp → WhatsApp identity only
 * A value the person rejected as not theirs is never added.
 */
class ContactChannelWriter
{
    public const CHANNELS = ['phone', 'email', 'whatsapp'];

    public function __construct(
        private EntityManager $entityManager,
        private Access $access,
        private Acl $acl,
        private ServiceContainer $recordServiceContainer,
    ) {}

    /**
     * @param array<string, mixed> $source verification source descriptor of a found value
     * @return 'added'|'already_present'|'invalid'|'rejected'|'conflict'
     */
    public function add(string $tenantId, string $contactId, string $channel, array $source): string
    {
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new \InvalidArgumentException("Unknown channel '{$channel}'.");
        }

        $kind = $channel === 'email' ? Kind::EMAIL : Kind::WHATSAPP;
        $contact = $this->access->contact($tenantId, $contactId, $kind);
        $resolved = $this->access->resolveSource($this->entityManager->getPDO(), $tenantId, $contact, $source, $kind);

        if ($resolved['ownershipRejected']) {
            return 'rejected';
        }

        $value = $resolved['value'] ?? null;
        if (!is_string($value) || $value === '') {
            return 'invalid';
        }

        if (!$this->acl->check($contact, 'edit')) {
            throw new Forbidden('Contact edit access required.');
        }

        $data = match ($channel) {
            'phone' => $this->phoneData($contact, $value),
            'email' => $this->emailData($contact, $value),
            'whatsapp' => $this->whatsAppData($tenantId, $contactId, $value),
        };

        if ($data === null) {
            return 'already_present';
        }

        try {
            $this->recordServiceContainer->get('Contact')->update(
                $contactId,
                $data,
                UpdateParams::create()->withSkipDuplicateCheck(),
            );
        } catch (Conflict) {
            // The identity already belongs to another contact in this workspace.
            return 'conflict';
        }

        return 'added';
    }

    private function phoneData(\Espo\ORM\Entity $contact, string $e164): ?\stdClass
    {
        $this->requireFieldEdit('phoneNumber');
        $list = $this->entityManager->getRDBRepository('PhoneNumber')->getPhoneNumberData($contact);

        foreach ($list as $row) {
            if (WhatsAppPhone::e164(((object) $row)->phoneNumber ?? null) === $e164) {
                return null;
            }
        }

        $list[] = (object) [
            'phoneNumber' => $e164,
            'type' => 'Mobile',
            'primary' => $list === [],
            'optOut' => false,
            'invalid' => false,
        ];

        return (object) ['phoneNumberData' => array_values($list)];
    }

    private function emailData(\Espo\ORM\Entity $contact, string $email): ?\stdClass
    {
        $this->requireFieldEdit('emailAddress');
        $list = $this->entityManager->getRDBRepository('EmailAddress')->getEmailAddressData($contact);

        foreach ($list as $row) {
            if (Kind::email(((object) $row)->emailAddress ?? null) === $email) {
                return null;
            }
        }

        $list[] = (object) [
            'emailAddress' => $email,
            'primary' => $list === [],
            'optOut' => false,
            'invalid' => false,
        ];

        return (object) ['emailAddressData' => array_values($list)];
    }

    private function whatsAppData(string $tenantId, string $contactId, string $e164): ?\stdClass
    {
        if (!$this->acl->checkScope('ContactChannelIdentity', 'create')) {
            throw new Forbidden('Contact channel create access required.');
        }

        $existing = $this->entityManager
            ->getRDBRepository('ContactChannelIdentity')
            ->where([
                'tenantId' => $tenantId,
                'contactId' => $contactId,
                'channelType' => 'whatsapp',
                'sourceId' => $e164,
            ])
            ->findOne();

        return $existing ? null : (object) ['whatsappNumber' => $e164];
    }

    private function requireFieldEdit(string $field): void
    {
        if (!$this->acl->checkField('Contact', $field, 'edit')) {
            throw new Forbidden("Contact {$field} edit access required.");
        }
    }
}
