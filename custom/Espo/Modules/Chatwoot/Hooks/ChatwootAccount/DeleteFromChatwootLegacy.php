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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootAccount;

use Espo\Core\Exceptions\Error;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Legacy hook to delete ChatwootAccount from Chatwoot Platform API.
 * This hook works for BOTH regular delete AND mass delete.
 * Deletes the account from Chatwoot BEFORE deleting from EspoCRM.
 */
class DeleteFromChatwootLegacy
{
    public static int $order = 10;

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    /**
     * Delete account from Chatwoot BEFORE entity is removed from database.
     * 
     * @param Entity $entity
     * @param array<string, mixed> $options
     * @throws Error
     */
    public function beforeRemove(Entity $entity, array $options)
    {
        // Write to file to be absolutely sure this is being called
        file_put_contents('/tmp/chatwoot-delete-hook-test.log', 
            date('Y-m-d H:i:s') . " - LEGACY DELETE HOOK CALLED for ChatwootAccount: " . $entity->getId() . "\n", 
            FILE_APPEND
        );
        
        $this->log->info('LEGACY DELETE HOOK CALLED for ChatwootAccount: ' . $entity->getId());
        error_log('LEGACY DELETE HOOK CALLED for ChatwootAccount: ' . $entity->getId());
        
        $chatwootAccountId = $entity->get('chatwootAccountId');
        
        // If there's no chatwootAccountId, this account was never synced to Chatwoot
        // Allow deletion from EspoCRM
        if (!$chatwootAccountId) {
            $this->log->info('ChatwootAccount ' . $entity->getId() . ' has no chatwootAccountId, skipping Chatwoot deletion');
            return;
        }
        
        $this->log->info('Attempting to delete Chatwoot account with ID: ' . $chatwootAccountId);

        $platformId = $entity->get('platformId');
        if (!$platformId) {
            $this->log->warning('ChatwootAccount ' . $entity->getId() . ' has no platformId, cannot delete from Chatwoot');
            throw new Error('Cannot delete account from Chatwoot: platform not configured.');
        }

        try {
            // Get the platform entity
            $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
            
            if (!$platform) {
                throw new Error('ChatwootPlatform not found: ' . $platformId);
            }

            $platformUrl = $platform->get('url');
            $accessToken = $platform->get('accessToken');

            if (!$platformUrl || !$accessToken) {
                throw new Error('ChatwootPlatform missing URL or access token.');
            }

            // Delete account from Chatwoot FIRST
            $this->log->info('Deleting Chatwoot account: ' . $chatwootAccountId);
            
            $this->apiClient->deleteAccount($platformUrl, $accessToken, $chatwootAccountId);
            
            $this->log->info('Successfully deleted Chatwoot account: ' . $chatwootAccountId);

        } catch (\Exception $e) {
            // If the resource doesn't exist (404), allow deletion from EspoCRM
            // The account is already gone from Chatwoot
            if (str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), 'not found')) {
                $this->log->warning(
                    'Chatwoot account ' . $chatwootAccountId . ' not found in Chatwoot (already deleted?). ' .
                    'Allowing deletion from EspoCRM.'
                );
                return;
            }
            
            $this->log->error(
                'Failed to delete Chatwoot account ' . $chatwootAccountId . ': ' . $e->getMessage()
            );
            
            // Re-throw - this will prevent the database DELETE from happening
            throw new Error(
                'Failed to delete account from Chatwoot: ' . $e->getMessage() . 
                '. The account was not deleted from EspoCRM to maintain synchronization. ' .
                'Please check if the account still exists in Chatwoot or try again.'
            );
        }
    }
}

