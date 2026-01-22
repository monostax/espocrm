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
 * Hook to delete ChatwootUser from Chatwoot Platform API.
 * This hook works for BOTH regular delete AND mass delete.
 * Deletes the user from Chatwoot BEFORE deleting from EspoCRM.
 * Also cascade deletes all linked ChatwootAgent records.
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
     * Delete user from Chatwoot and cascade delete linked agents BEFORE entity is removed from database.
     * 
     * @param Entity $entity
     * @param array<string, mixed> $options
     * @throws Error
     */
    public function beforeRemove(Entity $entity, array $options)
    {
        $this->log->info('DELETE HOOK CALLED for ChatwootUser: ' . $entity->getId());
        
        // First, cascade delete all linked ChatwootAgents
        $this->cascadeDeleteAgents($entity);
        
        $chatwootUserId = $entity->get('chatwootUserId');
        
        // If there's no chatwootUserId, this user was never synced to Chatwoot
        // Allow deletion from EspoCRM
        if (!$chatwootUserId) {
            $this->log->info('ChatwootUser ' . $entity->getId() . ' has no chatwootUserId, skipping Chatwoot deletion');
            return;
        }
        
        $this->log->info('Attempting to delete Chatwoot user with ID: ' . $chatwootUserId);

        $platformId = $entity->get('platformId');
        if (!$platformId) {
            $this->log->warning('ChatwootUser ' . $entity->getId() . ' has no platformId, cannot delete from Chatwoot');
            // Allow deletion from EspoCRM anyway
            return;
        }

        try {
            $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
            
            if (!$platform) {
                $this->log->warning('ChatwootPlatform not found: ' . $platformId . '. Allowing local deletion.');
                return;
            }

            $platformUrl = $platform->get('backendUrl');
            $accessToken = $platform->get('accessToken');

            if (!$platformUrl || !$accessToken) {
                $this->log->warning('ChatwootPlatform missing URL or access token. Allowing local deletion.');
                return;
            }

            // Delete user from Chatwoot Platform API
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

    /**
     * Cascade delete all ChatwootAgents linked to this ChatwootUser.
     * This will trigger the ChatwootAgent delete hooks which will also
     * remove the agents from Chatwoot.
     */
    private function cascadeDeleteAgents(Entity $entity): void
    {
        $userId = $entity->getId();
        
        // Find all ChatwootAgents linked to this user
        $agents = $this->entityManager
            ->getRDBRepository('ChatwootAgent')
            ->where(['chatwootUserId' => $userId])
            ->find();

        $count = 0;
        foreach ($agents as $agent) {
            try {
                $this->log->info(
                    'Cascade deleting ChatwootAgent ' . $agent->getId() . 
                    ' (linked to ChatwootUser ' . $userId . ')'
                );
                
                // This will trigger the ChatwootAgent delete hook
                // which handles deleting from Chatwoot
                $this->entityManager->removeEntity($agent);
                $count++;
            } catch (\Exception $e) {
                $this->log->error(
                    'Failed to cascade delete ChatwootAgent ' . $agent->getId() . ': ' . $e->getMessage()
                );
                // Continue with other agents even if one fails
            }
        }

        if ($count > 0) {
            $this->log->info(
                'Cascade deleted ' . $count . ' ChatwootAgent(s) linked to ChatwootUser ' . $userId
            );
        }
    }
}
