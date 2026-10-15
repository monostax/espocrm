<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\AclManager;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\Chatwoot\Jobs\SyncContactsFromChatwoot;
use Espo\Modules\Chatwoot\Tools\IdentityOwnership;
use Espo\Modules\Chatwoot\Tools\PhoneNormalizer;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Selected phone only. No primary-number fallback and no secondary enrollment. */
class ManagedCampaignRecipients
{
    public function __construct(
        private EntityManager $em, private SyncContactsFromChatwoot $sync, private ManagedCampaignAccess $access, private AclManager $acl,
    ) {}

    public function resolve(array $record, array $ctx): Entity
    {
        $phone = $record['phone_number'];
        $query = $this->em->getQueryBuilder()->select()->from('ChatwootContact')->where([
            'chatwootAccountId' => $ctx['account']->getId(), 'chatwootContactId' => $record['contact_id'],
        ])->withDeleted()->build();
        $bridge = $this->em->getRDBRepository('ChatwootContact')->clone($query)->findOne();
        $ids = $this->owners($phone, $ctx['account']->get('tenantId'));
        if ($bridge?->get('contactId')) $ids[] = $bridge->get('contactId');
        $email = strtolower(trim($record['contact']['email'] ?? ''));
        if ($email) {
            foreach ($this->em->getRDBRepository('Contact')->where([
                'tenantId' => $ctx['account']->get('tenantId'), 'emailAddress' => strtolower($email),
            ])->limit(0, 2)->find() as $contact) $ids[] = $contact->getId();
            foreach ($this->em->getRDBRepository('ContactChannelIdentity')->where([
                'tenantId' => $ctx['account']->get('tenantId'), 'channelType' => 'email', 'sourceId' => $email,
            ])->limit(0, 2)->find() as $identity) if ($identity->get('contactId')) $ids[] = $identity->get('contactId');
        }
        $identifier = $record['contact']['identifier'] ?? '';
        if (str_ends_with($identifier, '@lid')) {
            foreach ($this->em->getRDBRepository('ContactChannelIdentity')->where([
                'tenantId' => $ctx['account']->get('tenantId'), 'channelType' => 'whatsapp', 'whatsappLid' => $identifier,
            ])->limit(0, 2)->find() as $identity) if ($identity->get('contactId')) $ids[] = $identity->get('contactId');
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) > 1) throw new BadRequest('ambiguous_identity');
        if (!$bridge || $bridge->get('deleted') || !$bridge->get('contactId')) {
            if (!$this->acl->checkScope($ctx['user'], 'Contact', 'create') || !$this->acl->checkScope($ctx['user'], 'Contact', 'edit')) {
                throw new Forbidden('contact_sync_forbidden');
            }
            foreach (['name', 'firstName', 'lastName', 'phoneNumber', 'emailAddress', 'teams'] as $field) {
                if (!$this->acl->checkField($ctx['user'], 'Contact', $field, 'edit')) throw new Forbidden('contact_sync_forbidden');
            }
            // Reuse the canonical reconciler/bridge/inbox synchronization path.
            // The authoritative selected address is the phone identity for this import.
            $contactData = $record['contact'];
            $contactData['phone_number'] = $phone;
            $this->sync->syncSingleContact($contactData, $ctx['account']->getId(), $ctx['account']->get('teamsIds') ?? [], $ctx['account']->get('tenantId'),
                function (?Entity $target) use ($ctx, $ids): void {
                    if ($ids && (!$target || $target->getId() !== $ids[0])) throw new BadRequest('ambiguous_identity');
                    if ($target && (!$this->access->contact($target, $ctx) || !$this->acl->checkEntity($ctx['user'], $target, 'edit') ||
                        ($target->get('deleted') && !$this->acl->checkEntity($ctx['user'], $target, 'delete')))) {
                        throw new Forbidden('contact_sync_forbidden');
                    }
                });
            $bridge = $this->em->getRDBRepository('ChatwootContact')->where([
                'chatwootAccountId' => $ctx['account']->getId(), 'chatwootContactId' => $record['contact_id'],
            ])->findOne();
        }
        $contact = $this->em->getEntityById('Contact', (string) $bridge?->get('contactId'));
        if (!$contact || !$this->access->contact($contact, $ctx)) throw new Forbidden('contact_unavailable');
        $owners = $this->owners($phone, $ctx['account']->get('tenantId'));
        if (count($owners) > 1 || ($owners && $owners[0] !== $contact->getId())) throw new BadRequest('ambiguous_identity');
        return $contact;
    }

    public function exclusion(Entity $contact, string $phone, array $ctx, ?Entity $campaign = null): ?string
    {
        if (!$this->access->contact($contact, $ctx)) return 'contact_unavailable';
        if ($contact->get('whatsAppOptedOut')) return 'opted_out';
        if ((new IdentityOwnership($this->em))->isRejected($contact, 'phone', $phone)) return 'identity_rejected';
        $owners = $this->owners($phone, $ctx['account']->get('tenantId'));
        if (count($owners) !== 1 || $owners[0] !== $contact->getId()) return 'identity_changed';
        $found = false;
        foreach ($this->em->getRepository('PhoneNumber')->getPhoneNumberData($contact) as $row) {
            if (PhoneNormalizer::normalize($row->phoneNumber ?? null) !== PhoneNormalizer::normalize($phone)) continue;
            $found = true;
            if (!empty($row->optOut)) return 'opted_out';
            if (!empty($row->invalid) || strcasecmp($row->type ?? '', 'Fax') === 0) return 'invalid_number';
        }
        if (!$found) return 'identity_changed';
        if ($campaign && !$this->hasSource($campaign, $contact, $phone)) return 'identity_changed';
        return null;
    }

    private function hasSource(Entity $campaign, Entity $contact, string $phone): bool
    {
        $after = '';
        do {
            $sources = $this->em->getRDBRepository('WhatsAppCampaignSource')->where([
                'campaignId' => $campaign->getId(), 'phoneNumber' => $phone, 'reason' => null, 'id>' => $after,
            ])->order('id')->limit(0, 500)->find();
            foreach ($sources as $source) {
                $after = $source->getId();
                $record = ManagedCampaignPayload::array($source->get('record'));
                $bridge = $this->em->getRDBRepository('ChatwootContact')->where([
                    'chatwootAccountId' => $campaign->get('chatwootAccountId'), 'chatwootContactId' => $record['contact_id'],
                    'contactId' => $contact->getId(), 'blocked' => false,
                ])->findOne();
                if ($bridge) return true;
            }
        } while (count($sources) === 500);
        return false;
    }

    private function owners(string $phone, string $tenant): array
    {
        $ids = [];
        $normalized = PhoneNormalizer::normalize($phone);
        $variants = array_values(array_unique(array_filter([$phone, $normalized, ltrim($phone, '+'), ltrim($normalized ?? '', '+')])));
        foreach ($this->em->getRDBRepository('Contact')->join('phoneNumbers', 'selectedPhone')
            ->where(['tenantId' => $tenant, 'selectedPhone.name' => $variants])
            ->distinct()->limit(0, 2)->find() as $contact) {
            $ids[] = $contact->getId();
        }
        foreach ($this->em->getRDBRepository('ContactChannelIdentity')->where([
            'tenantId' => $tenant, 'channelType' => ['whatsapp', 'sms'],
            'sourceId' => array_values(array_unique(array_filter([$phone, $normalized]))), 'ownershipStatus!=' => 'rejected',
        ])->limit(0, 2)->find() as $identity) {
            if ($identity->get('contactId')) $ids[] = $identity->get('contactId');
        }
        return array_values(array_unique($ids));
    }
}
