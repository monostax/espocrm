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
 * Hook to attach a user to a Chatwoot account via Platform API when
 * a ChatwootAccountUserMembership is created from the UI (non-silent path).
 *
 * Phase 7: Deferred from Phase 3 Decision #4 — "No consumer exists for
 * non-silent membership create/update until Phase 7 UI work."
 *
 * Only fires on NEW memberships created non-silently (manual UI creation).
 * Silent saves from sync jobs, backfill operations, and LinkToUser bypass this hook.
 *
 * Credential chain: membership → account → platform (Platform API accessToken).
 * Same pattern as DeleteFromChatwoot.php.
 */
class SyncWithChatwoot
{
    public static int $order = 10; // After CascadeTeamsFromAccount (order=1), after ValidateBeforeSync (order=9)

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    /**
     * Attach user to Chatwoot account BEFORE membership is saved to database.
     *
     * @param Entity $entity
     * @param array<string, mixed> $options
     * @throws Error
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        // Skip if this is a silent save (from sync job, backfill, LinkToUser, etc.)
        if (!empty($options['silent'])) {
            return;
        }

        // Only fire on NEW memberships — update path doesn't need API sync
        // (role changes are propagated by agent sync jobs)
        if (!$entity->isNew()) {
            return;
        }

        // --- Credential loading chain (Platform API path) ---

        // Step 1: Get the ChatwootAccount entity
        $accountId = $entity->get('chatwootAccountId');
        if (!$accountId) {
            return;
        }

        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
        if (!$account) {
            throw new Error('ChatwootAccount not found: ' . $accountId);
        }

        $externalAccountId = $account->get('chatwootAccountId');
        if (!$externalAccountId) {
            throw new Error('ChatwootAccount has not been synchronized with Chatwoot.');
        }

        // Step 2: Get the ChatwootPlatform entity (for accessToken)
        $platformId = $account->get('platformId');
        if (!$platformId) {
            throw new Error('ChatwootAccount does not have a platform configured.');
        }

        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
        if (!$platform) {
            throw new Error('ChatwootPlatform not found: ' . $platformId);
        }

        $platformUrl = $platform->get('backendUrl');
        if (!$platformUrl) {
            throw new Error('ChatwootPlatform does not have a URL configured.');
        }

        $accessToken = $platform->get('accessToken');
        if (!$accessToken) {
            throw new Error('ChatwootPlatform does not have an access token configured.');
        }

        // Step 3: Resolve external user ID from ChatwootUser
        $userId = $entity->get('chatwootUserId');
        if (!$userId) {
            return;
        }

        $chatwootUser = $this->entityManager->getEntityById('ChatwootUser', $userId);
        if (!$chatwootUser) {
            throw new Error('ChatwootUser not found: ' . $userId);
        }

        $externalUserId = $chatwootUser->get('chatwootUserId');
        if (!$externalUserId) {
            throw new Error('ChatwootUser has no external chatwootUserId. The user may not have been synced to Chatwoot yet.');
        }

        $role = $entity->get('role') ?? 'agent';

        // --- API call ---
        try {
            $this->log->info(
                'SyncWithChatwoot: Attaching user ' . $externalUserId .
                ' to Chatwoot account ' . $externalAccountId . ' with role ' . $role
            );

            $this->apiClient->attachUserToAccount(
                $platformUrl,
                $accessToken,
                $externalAccountId,
                $externalUserId,
                $role
            );

            // Mark as synced
            $entity->set('syncStatus', 'synced');
            $entity->set('lastSyncedAt', date('Y-m-d H:i:s'));
            $entity->set('lastSyncError', null);

            $this->log->info(
                'SyncWithChatwoot: Successfully attached user ' . $externalUserId .
                ' to Chatwoot account ' . $externalAccountId
            );

        } catch (\Exception $e) {
            // Treat 409/conflict (already attached) as success — idempotent
            if (str_contains($e->getMessage(), '409') || str_contains($e->getMessage(), 'already')) {
                $this->log->info(
                    'SyncWithChatwoot: User ' . $externalUserId .
                    ' already attached to account ' . $externalAccountId . '. Treating as success.'
                );

                $entity->set('syncStatus', 'synced');
                $entity->set('lastSyncedAt', date('Y-m-d H:i:s'));
                $entity->set('lastSyncError', null);

                return;
            }

            $this->log->error(
                'SyncWithChatwoot: Failed to attach user ' . $externalUserId .
                ' to Chatwoot account ' . $externalAccountId . ': ' . $e->getMessage()
            );

            // Set error status and throw to prevent DB save (maintain sync)
            $entity->set('syncStatus', 'error');
            $entity->set('lastSyncError', $e->getMessage());
            $entity->set('lastSyncedAt', date('Y-m-d H:i:s'));

            throw new Error(
                'Failed to attach user to Chatwoot account: ' . $e->getMessage() .
                '. The membership was not created in EspoCRM to maintain synchronization.'
            );
        }
    }
}
