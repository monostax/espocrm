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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootInbox;

use Espo\Core\Exceptions\Error;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Hook to delete ChatwootInbox from Chatwoot Account API.
 * This hook works for BOTH regular delete AND mass delete.
 * Deletes the inbox from Chatwoot BEFORE deleting from EspoCRM.
 * 
 * This prevents the sync job (SyncInboxesFromChatwoot) from recreating
 * the inbox after local deletion.
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
     * Delete inbox from Chatwoot BEFORE entity is removed from database.
     * 
     * @param Entity $entity
     * @param array<string, mixed> $options
     * @throws Error
     */
    public function beforeRemove(Entity $entity, array $options): void
    {
        // Skip if this is a cascade delete from parent (remote cleanup already handled by parent)
        // When ChatwootInboxIntegration is deleted, its CleanupOnRemove hook handles the API call
        if (!empty($options['cascadeParent'])) {
            return;
        }

        $this->log->info('DELETE HOOK CALLED for ChatwootInbox: ' . $entity->getId());
        
        $chatwootInboxId = $entity->get('chatwootInboxId');
        
        // If there's no chatwootInboxId, this inbox was never synced to Chatwoot
        // Allow deletion from EspoCRM
        if (!$chatwootInboxId) {
            $this->log->info('ChatwootInbox ' . $entity->getId() . ' has no chatwootInboxId, skipping Chatwoot deletion');
            return;
        }
        
        $this->log->info('Attempting to delete Chatwoot inbox with ID: ' . $chatwootInboxId);

        $accountId = $entity->get('chatwootAccountId');
        if (!$accountId) {
            $this->log->warning('ChatwootInbox ' . $entity->getId() . ' has no chatwootAccountId, cannot delete from Chatwoot');
            // Allow deletion from EspoCRM anyway - we can't sync
            return;
        }

        try {
            // Get the account entity
            $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
            
            if (!$account) {
                $this->log->warning('ChatwootAccount not found: ' . $accountId . '. Allowing local deletion.');
                return;
            }

            $chatwootAccountId = $account->get('chatwootAccountId');
            if (!$chatwootAccountId) {
                $this->log->warning('ChatwootAccount has no chatwootAccountId. Allowing local deletion.');
                return;
            }

            $apiKey = $account->get('apiKey');
            if (!$apiKey) {
                $this->log->warning('ChatwootAccount has no API key. Allowing local deletion.');
                return;
            }

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

            // Delete inbox from Chatwoot
            $this->log->info('Deleting Chatwoot inbox: ' . $chatwootInboxId . ' from account ' . $chatwootAccountId);
            
            $this->apiClient->deleteInbox(
                $platformUrl,
                $apiKey,
                (int) $chatwootAccountId,
                (int) $chatwootInboxId
            );
            
            $this->log->info('Successfully deleted Chatwoot inbox: ' . $chatwootInboxId);

        } catch (\Exception $e) {
            // If the resource doesn't exist (404), allow deletion from EspoCRM
            // The inbox is already gone from Chatwoot
            if (str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), 'not found')) {
                $this->log->warning(
                    'Chatwoot inbox ' . $chatwootInboxId . ' not found in Chatwoot (already deleted?). ' .
                    'Allowing deletion from EspoCRM.'
                );
                return;
            }
            
            $this->log->error(
                'Failed to delete Chatwoot inbox ' . $chatwootInboxId . ': ' . $e->getMessage()
            );
            
            // Re-throw - this will prevent the database DELETE from happening
            throw new Error(
                'Failed to delete inbox from Chatwoot: ' . $e->getMessage() . 
                '. The inbox was not deleted from EspoCRM to maintain synchronization. ' .
                'Please check if the inbox still exists in Chatwoot or try again.'
            );
        }
    }
}
