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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootUser;

use Espo\Core\Exceptions\Error;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Legacy hook to delete ChatwootUser from Chatwoot Platform API.
 * This hook works for BOTH regular delete AND mass delete.
 * Deletes the user from Chatwoot BEFORE deleting from EspoCRM.
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
     * Delete user from Chatwoot BEFORE entity is removed from database.
     * 
     * @param Entity $entity
     * @param array<string, mixed> $options
     * @throws Error
     */
    public function beforeRemove(Entity $entity, array $options)
    {
        file_put_contents('/tmp/chatwoot-delete-hook-test.log', 
            date('Y-m-d H:i:s') . " - LEGACY DELETE HOOK CALLED for ChatwootUser: " . $entity->getId() . "\n", 
            FILE_APPEND
        );
        

        $this->log->info('LEGACY DELETE HOOK CALLED for ChatwootUser: ' . $entity->getId());
        
        $chatwootUserId = $entity->get('chatwootUserId');
        
        // If there's no chatwootUserId, this user was never synced to Chatwoot
        // Allow deletion from EspoCRM
        if (!$chatwootUserId) {
            $this->log->info('ChatwootUser ' . $entity->getId() . ' has no chatwootUserId, skipping Chatwoot deletion');
            return;
        }
        
        $this->log->info('Attempting to delete Chatwoot user with ID: ' . $chatwootUserId);

        $accountId = $entity->get('accountId');
        if (!$accountId) {
            $this->log->warning('ChatwootUser ' . $entity->getId() . ' has no accountId, cannot delete from Chatwoot');
            throw new Error('Cannot delete user from Chatwoot: account not configured.');
        }

        try {
            // Get the account entity
            $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
            
            if (!$account) {
                throw new Error('ChatwootAccount not found: ' . $accountId);
            }

            $chatwootAccountId = $account->get('chatwootAccountId');
            if (!$chatwootAccountId) {
                throw new Error('ChatwootAccount has no chatwootAccountId.');
            }

            $platformId = $account->get('platformId');
            if (!$platformId) {
                throw new Error('ChatwootAccount has no platformId.');
            }

            $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
            
            if (!$platform) {
                throw new Error('ChatwootPlatform not found: ' . $platformId);
            }

            $platformUrl = $platform->get('url');
            $accessToken = $platform->get('accessToken');

            if (!$platformUrl || !$accessToken) {
                throw new Error('ChatwootPlatform missing URL or access token.');
            }

            // STEP 1: Detach user from account (optional, but good practice)
            $this->log->info("Detaching user $chatwootUserId from account $chatwootAccountId");
            
            try {
                $this->apiClient->detachUserFromAccount(
                    $platformUrl,
                    $accessToken,
                    $chatwootAccountId,
                    $chatwootUserId
                );
            } catch (\Exception $e) {
                // Detach might fail if user is already detached or doesn't exist
                // Log but continue to deletion
                $this->log->warning('Failed to detach user from account: ' . $e->getMessage());
            }

            // STEP 2: Delete user from Chatwoot
            $this->log->info('Deleting Chatwoot user: ' . $chatwootUserId);
            
            $this->apiClient->deleteUser($platformUrl, $accessToken, $chatwootUserId);
            
            $this->log->info('Successfully deleted Chatwoot user: ' . $chatwootUserId);

        } catch (\Exception $e) {
            // If the resource doesn't exist (404), allow deletion from EspoCRM
            // The user is already gone from Chatwoot
            if (str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), 'not found')) {
                $this->log->warning(
                    'Chatwoot user ' . $chatwootUserId . ' not found in Chatwoot (already deleted?). ' .
                    'Allowing deletion from EspoCRM.'
                );
                return;
            }
            
            $this->log->error(
                'Failed to delete Chatwoot user ' . $chatwootUserId . ': ' . $e->getMessage()
            );
            
            // Re-throw - this will prevent the database DELETE from happening
            throw new Error(
                'Failed to delete user from Chatwoot: ' . $e->getMessage() . 
                '. The user was not deleted from EspoCRM to maintain synchronization. ' .
                'Please check if the user still exists in Chatwoot or try again.'
            );
        }
    }
}

