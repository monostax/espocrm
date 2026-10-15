<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\AclManager;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Tools\WhatsAppChannel;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class ManagedCampaignAccess
{
    public function __construct(private EntityManager $em, private AclManager $acl, private UserTenantResolver $tenants) {}

    public function context(Entity $platform, array $data, bool $launch = false): array
    {
        $account = $this->one('ChatwootAccount', ['platformId' => $platform->getId(), 'chatwootAccountId' => (int) ($data['account_id'] ?? 0)]);
        $identity = $this->one('ChatwootUser', ['platformId' => $platform->getId(), 'chatwootUserId' => (int) ($data['actor_id'] ?? 0)]);
        $user = $this->em->getEntityById('User', (string) $identity->get('assignedUserId'));
        $this->one('ChatwootAccountUserMembership', ['chatwootAccountId' => $account->getId(), 'chatwootUserId' => $identity->getId()]);
        if (!$user instanceof User || !$user->isActive() || (!$user->isRegular() && !$user->isAdmin()) ||
            !$account->get('tenantId') || $account->get('status') !== 'active' ||
            (!$user->isAdmin() && !$this->tenants->canActForTenant($user, $account->get('tenantId'))) ||
            !$this->acl->checkEntityRead($user, $account) || !$this->acl->checkScope($user, 'WhatsAppCampaign', 'read')) {
            throw new Forbidden('Campaign actor unavailable.');
        }
        if ($launch && (!$this->acl->checkScope($user, 'WhatsAppCampaign', 'create') ||
            !$this->acl->checkScope($user, 'WhatsAppCampaign', 'edit'))) {
            throw new Forbidden('Campaign launch unavailable.');
        }
        $inbox = null;
        if (!empty($data['inbox_id'])) {
            $inbox = $this->one('ChatwootInbox', ['chatwootAccountId' => $account->getId(), 'chatwootInboxId' => (int) $data['inbox_id']]);
            if (!$this->acl->checkEntityRead($user, $inbox)) throw new Forbidden('Inbox unavailable.');
            if ($launch) $this->mode($inbox);
        }
        return compact('platform', 'account', 'inbox', 'user');
    }

    public function campaign(Entity $campaign, bool $launch = true): array
    {
        $platform = $this->em->getEntityById('ChatwootPlatform', $campaign->get('managedPlatformId'));
        $account = $this->em->getEntityById('ChatwootAccount', $campaign->get('chatwootAccountId'));
        $inbox = $this->em->getEntityById('ChatwootInbox', $campaign->get('chatwootInboxId'));
        if (!$platform || !$account || !$inbox) throw new Forbidden();
        $ctx = $this->context($platform, [
            'account_id' => $account->get('chatwootAccountId'), 'actor_id' => $campaign->get('managedActorId'),
            'inbox_id' => $inbox->get('chatwootInboxId'),
        ], $launch);
        if ($ctx['account']->getId() !== $account->getId() || $ctx['inbox']->getId() !== $inbox->getId() ||
            $ctx['user']->getId() !== $campaign->get('managedUserId') ||
            $campaign->get('tenantId') !== $account->get('tenantId') ||
            !$this->acl->checkEntity($ctx['user'], $campaign, $launch ? 'edit' : 'read')) throw new Forbidden();
        return $ctx;
    }

    public function mode(Entity $inbox): string
    {
        $integration = $this->em->getEntityById('ChatwootInboxIntegration', (string) $inbox->get('chatwootInboxIntegrationId'));
        $type = $inbox->get('channelType');
        if (!$integration || $integration->get('chatwootAccountId') !== $inbox->get('chatwootAccountId') ||
            $integration->get('status') !== 'ACTIVE' || $integration->get('channelType') !== $type ||
            !WhatsAppChannel::isSendable($type)) throw new Forbidden('Inbox configuration unavailable.');
        return WhatsAppChannel::isQrcode($type) ? 'FreeText' : 'Template';
    }

    public function contact(Entity $contact, array $ctx): bool
    {
        return $contact->get('tenantId') === $ctx['account']->get('tenantId') && $this->acl->checkEntityRead($ctx['user'], $contact);
    }

    /** Resolve the integration identity already provisioned for this account. */
    public function integrationSenderId(Entity $account): ?int
    {
        $identity = $this->em->getEntityById('ChatwootUser', (string) $account->get('conciergeUserId'));
        if (!$identity || $identity->get('platformId') !== $account->get('platformId') ||
            (int) $identity->get('chatwootUserId') < 1) return null;
        $membership = $this->em->getRDBRepository('ChatwootAccountUserMembership')->where([
            'chatwootAccountId' => $account->getId(), 'chatwootUserId' => $identity->getId(),
        ])->findOne();
        return $membership ? (int) $identity->get('chatwootUserId') : null;
    }

    public function assertCancellation(Entity $campaign, array $ctx): void
    {
        if (!$this->acl->checkEntity($ctx['user'], $campaign, 'edit')) throw new Forbidden('Campaign cancellation unavailable.');
    }

    private function one(string $type, array $where): Entity
    {
        $rows = iterator_to_array($this->em->getRDBRepository($type)->where($where)->limit(0, 2)->find(), false);
        if (count($rows) !== 1) throw new Forbidden('Campaign mapping unavailable.');
        return $rows[0];
    }
}
