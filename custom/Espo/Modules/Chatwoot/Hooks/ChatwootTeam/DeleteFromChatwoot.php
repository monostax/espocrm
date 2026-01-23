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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootTeam;

use Espo\Core\Exceptions\Error;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Hook to delete ChatwootTeam from Chatwoot Account API.
 * This hook works for BOTH regular delete AND mass delete.
 * Deletes the team from Chatwoot BEFORE deleting from EspoCRM.
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
     * Delete team from Chatwoot BEFORE entity is removed from database.
     * 
     * @param Entity $entity
     * @param array<string, mixed> $options
     * @throws Error
     */
    public function beforeRemove(Entity $entity, array $options)
    {
        // Skip if this is a cascade delete from parent (remote cleanup already handled by parent)
        if (!empty($options['cascadeParent'])) {
            return;
        }

        $this->log->info('DELETE HOOK CALLED for ChatwootTeam: ' . $entity->getId());
        
        $chatwootTeamId = $entity->get('chatwootTeamId');
        
        // If there's no chatwootTeamId, this team was never synced to Chatwoot
        // Allow deletion from EspoCRM
        if (!$chatwootTeamId) {
            $this->log->info('ChatwootTeam ' . $entity->getId() . ' has no chatwootTeamId, skipping Chatwoot deletion');
            return;
        }
        
        $this->log->info('Attempting to delete Chatwoot team with ID: ' . $chatwootTeamId);

        $accountId = $entity->get('accountId');
        if (!$accountId) {
            $this->log->warning('ChatwootTeam ' . $entity->getId() . ' has no accountId, cannot delete from Chatwoot');
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

            // Delete team from Chatwoot
            $this->log->info('Deleting Chatwoot team: ' . $chatwootTeamId . ' from account ' . $chatwootAccountId);
            
            $this->apiClient->deleteTeam(
                $platformUrl,
                $apiKey,
                $chatwootAccountId,
                $chatwootTeamId
            );
            
            $this->log->info('Successfully deleted Chatwoot team: ' . $chatwootTeamId);

        } catch (\Exception $e) {
            // If the resource doesn't exist (404), allow deletion from EspoCRM
            // The team is already gone from Chatwoot
            if (str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), 'not found')) {
                $this->log->warning(
                    'Chatwoot team ' . $chatwootTeamId . ' not found in Chatwoot (already deleted?). ' .
                    'Allowing deletion from EspoCRM.'
                );
                return;
            }
            
            $this->log->error(
                'Failed to delete Chatwoot team ' . $chatwootTeamId . ': ' . $e->getMessage()
            );
            
            // Re-throw - this will prevent the database DELETE from happening
            throw new Error(
                'Failed to delete team from Chatwoot: ' . $e->getMessage() . 
                '. The team was not deleted from EspoCRM to maintain synchronization. ' .
                'Please check if the team still exists in Chatwoot or try again.'
            );
        }
    }
}
