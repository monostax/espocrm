<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Hooks\ChatwootAccountUserMembership;

use Espo\Core\Exceptions\Error;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Hook to detach a user from a Chatwoot account via Platform API when
 * a ChatwootAccountUserMembership is deleted directly.
 *
 * First consumer of the existing detachUserFromAccount() API method (Decision #3).
 * Uses Platform API credentials (accessToken), not Account-level (apiKey).
 *
 * Cascade safety:
 * - ChatwootAccount cascade → passes cascadeParent → hook skips
 * - ChatwootUser cascade → passes cascadeParent → hook skips
 * - Direct deletion (admin UI, future Phase 7) → hook fires → calls detachUserFromAccount()
 */
class DeleteFromChatwoot
{
    public static int $order = 10;

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    /**
     * Detach user from Chatwoot account BEFORE membership is removed from database.
     *
     * @param Entity $entity
     * @param array<string, mixed> $options
     * @throws Error
     */
    public function beforeRemove(Entity $entity, array $options): void
    {
        // Skip if this is a cascade delete from parent (remote cleanup already handled by parent)
        if (!empty($options['cascadeParent'])) {
            return;
        }

        $this->log->info('DELETE HOOK CALLED for ChatwootAccountUserMembership: ' . $entity->getId());

        // --- Credential loading chain (Platform API path) ---

        // Step 1: Get the ChatwootAccount entity
        $accountId = $entity->get('chatwootAccountId');
        if (!$accountId) {
            $this->log->warning(
                'ChatwootAccountUserMembership ' . $entity->getId() .
                ' has no chatwootAccountId, cannot detach from Chatwoot'
            );
            return;
        }

        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
        if (!$account) {
            $this->log->warning('ChatwootAccount not found: ' . $accountId . '. Allowing local deletion.');
            return;
        }

        $externalAccountId = $account->get('chatwootAccountId');
        if (!$externalAccountId) {
            $this->log->warning('ChatwootAccount has no chatwootAccountId. Allowing local deletion.');
            return;
        }

        // Step 2: Get the ChatwootPlatform entity (for accessToken, not apiKey)
        $platformId = $account->get('platformId');
        if (!$platformId) {
            $this->log->warning('ChatwootAccount has no platformId. Allowing local deletion.');
            return;
        }

        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
        if (!$platform) {
            $this->log->warning('ChatwootPlatform not found: ' . $platformId . '. Allowing local deletion.');
            return;
        }

        $platformUrl = $platform->get('backendUrl');
        if (!$platformUrl) {
            $this->log->warning('ChatwootPlatform missing URL. Allowing local deletion.');
            return;
        }

        $accessToken = $platform->get('accessToken');
        if (!$accessToken) {
            $this->log->warning('ChatwootPlatform missing accessToken. Allowing local deletion.');
            return;
        }

        // Step 3: Resolve external user ID from ChatwootUser
        $userId = $entity->get('chatwootUserId');
        if (!$userId) {
            $this->log->warning(
                'ChatwootAccountUserMembership ' . $entity->getId() .
                ' has no chatwootUserId, cannot detach from Chatwoot'
            );
            return;
        }

        $chatwootUser = $this->entityManager->getEntityById('ChatwootUser', $userId);
        if (!$chatwootUser) {
            $this->log->warning(
                'ChatwootUser not found: ' . $userId . '. Allowing local deletion.'
            );
            return;
        }

        $externalUserId = $chatwootUser->get('chatwootUserId');
        if (!$externalUserId) {
            $this->log->warning(
                'ChatwootUser ' . $userId . ' has no external chatwootUserId. Allowing local deletion.'
            );
            return;
        }

        // --- API call ---
        try {
            $this->log->info(
                'Detaching user ' . $externalUserId . ' from Chatwoot account ' . $externalAccountId
            );

            $this->apiClient->detachUserFromAccount(
                $platformUrl,
                $accessToken,
                $externalAccountId,
                $externalUserId
            );

            $this->log->info(
                'Successfully detached user ' . $externalUserId . ' from Chatwoot account ' . $externalAccountId
            );

        } catch (\Exception $e) {
            // If the resource doesn't exist (404), allow deletion from EspoCRM
            if (str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), 'not found')) {
                $this->log->warning(
                    'User ' . $externalUserId . ' already detached from Chatwoot account ' .
                    $externalAccountId . ' (not found). Allowing deletion from EspoCRM.'
                );
                return;
            }

            $this->log->error(
                'Failed to detach user ' . $externalUserId . ' from Chatwoot account ' .
                $externalAccountId . ': ' . $e->getMessage()
            );

            // Re-throw to prevent the database DELETE from happening
            throw new Error(
                'Failed to detach user from Chatwoot account: ' . $e->getMessage() .
                '. The membership was not deleted from EspoCRM to maintain synchronization. ' .
                'Please check if the user still exists in the Chatwoot account or try again.'
            );
        }
    }
}
