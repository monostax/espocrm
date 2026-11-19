<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureSelfService\Tools\Workspace;

use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\Preview\PreviewCarry;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\Provisioner;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\WorkspaceStarter;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use RuntimeException;

/**
 * The AI agent a self-service workspace starts with (WorkspaceStarter): silent, with no inbox, until the owner turns
 * it on. A signup from the site's agent funnel (signupJob `agente`) is offered that right after the import:
 * "Ligar a IA neste número" (site preview doc §11).
 *
 * Turning it on adds the agent to the workspace's connected WhatsApp inboxes with Chatwoot's add-only call
 * (POST inbox_members), so nobody already on the inbox is dropped, then mirrors the link on the CRM inbox without
 * hooks: the replace-all sync (SyncInboxMembership) would push the CRM's list, which may not have caught up with a
 * number connected minutes ago.
 */
class WorkspaceAgent
{
    private const WHATSAPP_CHANNELS = ['whatsappQrcode', 'whatsappCloudApi', 'whatsappCoexistence'];

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
    ) {}

    /**
     * The agent and its name for the onboarding: the one the site preview gave it (it lives in the instructions),
     * else its own; `active` once it is on an inbox.
     *
     * @return array{name: string, active: bool}|null
     */
    public function state(Entity $tenant): ?array
    {
        [$membership, $record] = $this->find($tenant);

        if (!$membership) {
            return null;
        }

        $name = PreviewCarry::fromStored($record?->get('previewCarry'))?->agentName ?: (string) $membership->get('name');

        return [
            'name' => $name,
            'active' => $this->entityManager
                ->getRDBRepository('ChatwootAccountUserMembership')
                ->getRelation($membership, 'chatwootInboxes')
                ->findOne() !== null,
        ];
    }

    /**
     * Puts the agent on every connected WhatsApp number of the account. Running it again adds nothing.
     *
     * @throws RuntimeException No agent, no connected number, or Chatwoot refused.
     */
    public function activate(Entity $tenant, Entity $account): void
    {
        [$membership] = $this->find($tenant);

        if (!$membership || $membership->get('chatwootAccountId') !== $account->getId()) {
            throw new RuntimeException('This workspace has no AI agent to turn on.');
        }

        $remoteUserId = (int) $this->entityManager
            ->getEntityById('ChatwootUser', (string) $membership->get('chatwootUserId'))
            ?->get('chatwootUserId');
        $platform = $this->entityManager->getEntityById('ChatwootPlatform', (string) $account->get('platformId'));
        $inboxes = $this->whatsappInboxes($account);

        if ($remoteUserId <= 0 || !$platform?->get('backendUrl') || !$account->get('apiKey')) {
            throw new RuntimeException('The AI agent or the workspace is not linked to Chatwoot.');
        }

        if ($inboxes === []) {
            throw new RuntimeException('The workspace has no connected WhatsApp number.');
        }

        foreach ($inboxes as $inbox) {
            $this->apiClient->addInboxMembers(
                (string) $platform->get('backendUrl'),
                (string) $account->get('apiKey'),
                (int) $account->get('chatwootAccountId'),
                (int) $inbox->get('chatwootInboxId'),
                [$remoteUserId],
            );

            $members = $this->entityManager->getRDBRepository('ChatwootInbox')->getRelation($inbox, 'accountUserMemberships');

            if (!$members->isRelatedById((string) $membership->getId())) {
                $members->relateById((string) $membership->getId(), null, ['skipHooks' => true]);
            }
        }
    }

    /**
     * The agent the workspace's provisioning made: its operation id is derived from the provisioning record, and
     * the machine identity reserved under it names the membership.
     *
     * @return array{0: ?Entity, 1: ?Entity} The membership and the provisioning record.
     */
    private function find(Entity $tenant): array
    {
        $records = $this->entityManager
            ->getRDBRepository(Provisioner::ENTITY_TYPE)
            ->where(['tenantId' => $tenant->getId()])
            ->order('createdAt', 'DESC')
            ->find();

        foreach ($records as $record) {
            $identity = $this->entityManager
                ->getRDBRepository('ChatwootMachineIdentity')
                ->where(['operationId' => WorkspaceStarter::operationId((string) $record->getId())])
                ->findOne();
            $membership = $identity
                ? $this->entityManager->getEntityById('ChatwootAccountUserMembership', (string) $identity->get('membershipId'))
                : null;

            if ($membership && $membership->get('isAI')) {
                return [$membership, $record];
            }
        }

        return [null, null];
    }

    /**
     * @return list<Entity> The account's CRM inboxes behind an active WhatsApp channel, with their Chatwoot id.
     */
    private function whatsappInboxes(Entity $account): array
    {
        return iterator_to_array($this->entityManager
            ->getRDBRepository('ChatwootInbox')
            ->join('chatwootInboxIntegration')
            ->where([
                'chatwootAccountId' => $account->getId(),
                'chatwootInboxId>' => 0,
                'chatwootInboxIntegration.channelType' => self::WHATSAPP_CHANNELS,
                'chatwootInboxIntegration.status' => 'ACTIVE',
            ])
            ->find(), false);
    }
}
