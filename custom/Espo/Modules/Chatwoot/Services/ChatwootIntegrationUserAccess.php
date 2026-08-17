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

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Maintains the access required by the account-level Chatwoot integration user.
 */
class ChatwootIntegrationUserAccess
{
    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private IntegrationUserNameResolver $nameResolver,
        private Log $log,
    ) {}

    /**
     * Ensure the account token owner can access a WAHA inbox but cannot be assigned work.
     */
    public function ensureInboxAccess(Entity $account, int $inboxId): void
    {
        $conciergeUserId = $account->get('conciergeUserId');
        $chatwootAccountId = (int) $account->get('chatwootAccountId');
        $accountApiKey = $account->get('apiKey');
        $platformId = $account->get('platformId');

        if (!$conciergeUserId || !$chatwootAccountId || !$accountApiKey || !$platformId) {
            throw new \RuntimeException('Chatwoot integration user or account credentials are missing.');
        }

        $integrationUser = $this->entityManager->getEntityById('ChatwootUser', $conciergeUserId);
        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
        $platformUserId = (int) ($integrationUser?->get('chatwootUserId') ?? 0);
        $platformUrl = $platform?->get('backendUrl');
        $platformAccessToken = $platform?->get('accessToken');

        if (!$platformUserId || !$platformUrl || !$platformAccessToken) {
            throw new \RuntimeException('Could not resolve the Chatwoot integration user or platform credentials.');
        }

        $name = $this->nameResolver->resolve($account);

        if ($integrationUser->get('name') !== $name || $integrationUser->get('displayName') !== $name) {
            $this->apiClient->updateUser(
                $platformUrl,
                $platformAccessToken,
                $platformUserId,
                ['name' => $name, 'display_name' => $name]
            );
            $integrationUser->set('name', $name);
            $integrationUser->set('displayName', $name);
            $this->entityManager->saveEntity($integrationUser, ['silent' => true]);
        }

        // Account-level non-assignable is authoritative across every inbox.
        $this->apiClient->attachUserToAccount(
            $platformUrl,
            $platformAccessToken,
            $chatwootAccountId,
            $platformUserId,
            'administrator',
            true,
            false
        );

        // WAHA calls conversation endpoints with this user's token, so global-admin
        // settings access alone is insufficient: the user must be a direct member.
        $this->apiClient->addInboxMembers(
            $platformUrl,
            $accountApiKey,
            $chatwootAccountId,
            $inboxId,
            [$platformUserId]
        );
        $this->apiClient->updateInboxMemberSettings(
            $platformUrl,
            $accountApiKey,
            $chatwootAccountId,
            $inboxId,
            $platformUserId,
            ['assignable' => false]
        );

        $this->log->info(
            "ChatwootIntegrationUserAccess: Ensured non-assignable user {$platformUserId} " .
            "has access to inbox {$inboxId} in account {$chatwootAccountId}"
        );
    }
}
