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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootAgent;

use Espo\Core\Exceptions\Error;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Hook to delete ChatwootAgent from Chatwoot Account API.
 * This hook works for BOTH regular delete AND mass delete.
 * Deletes the agent from Chatwoot BEFORE deleting from EspoCRM.
 *
 * Also clears the agent link on the associated membership (Decision #11).
 * The membership itself is NOT deleted — the user is still a member of
 * the account; only the agent reference is cleared.
 *
 * The Chatwoot platform user ID is resolved from the linked ChatwootUser
 * entity rather than stored directly on the agent.
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
     * Delete agent from Chatwoot BEFORE entity is removed from database.
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

        // Clear dangling chatwootAgentId on membership (Decision #11)
        $this->clearAgentIdFromMembership($entity);

        $this->log->info('DELETE HOOK CALLED for ChatwootAgent: ' . $entity->getId());
        
        $platformUserId = $this->resolvePlatformUserId($entity);
        
        // If there's no platform user ID, this agent was never synced to Chatwoot
        // Allow deletion from EspoCRM
        if (!$platformUserId) {
            $this->log->info('ChatwootAgent ' . $entity->getId() . ' has no linked ChatwootUser with chatwootUserId, skipping Chatwoot deletion');
            return;
        }
        
        $this->log->info('Attempting to delete Chatwoot agent with platformUserId: ' . $platformUserId);

        $accountId = $entity->get('chatwootAccountId');
        if (!$accountId) {
            $this->log->warning('ChatwootAgent ' . $entity->getId() . ' has no chatwootAccountId, cannot delete from Chatwoot');
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

            // Delete agent from Chatwoot
            $this->log->info('Deleting Chatwoot agent (platformUserId=' . $platformUserId . ') from account ' . $chatwootAccountId);
            
            $this->apiClient->deleteAgent(
                $platformUrl,
                $apiKey,
                $chatwootAccountId,
                $platformUserId
            );
            
            $this->log->info('Successfully deleted Chatwoot agent (platformUserId=' . $platformUserId . ')');

        } catch (\Exception $e) {
            // If the resource doesn't exist (404), allow deletion from EspoCRM
            // The agent is already gone from Chatwoot
            if (str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), 'not found')) {
                $this->log->warning(
                    'Chatwoot agent (platformUserId=' . $platformUserId . ') not found in Chatwoot (already deleted?). ' .
                    'Allowing deletion from EspoCRM.'
                );
                return;
            }
            
            $this->log->error(
                'Failed to delete Chatwoot agent (platformUserId=' . $platformUserId . '): ' . $e->getMessage()
            );
            
            // Re-throw - this will prevent the database DELETE from happening
            throw new Error(
                'Failed to delete agent from Chatwoot: ' . $e->getMessage() . 
                '. The agent was not deleted from EspoCRM to maintain synchronization. ' .
                'Please check if the agent still exists in Chatwoot or try again.'
            );
        }
    }

    /**
     * Resolve the Chatwoot platform user ID from the agent's linked ChatwootUser.
     */
    private function resolvePlatformUserId(Entity $entity): ?int
    {
        $chatwootUserId = $entity->get('chatwootUserId');
        if (!$chatwootUserId) {
            return null;
        }

        $chatwootUser = $this->entityManager->getEntityById('ChatwootUser', $chatwootUserId);
        if (!$chatwootUser) {
            return null;
        }

        $platformUserId = $chatwootUser->get('chatwootUserId');
        return $platformUserId ? (int) $platformUserId : null;
    }

    /**
     * Clear the chatwootAgentId reference on the associated membership.
     * The membership itself is NOT deleted — the user is still a member of the account.
     */
    private function clearAgentIdFromMembership(Entity $entity): void
    {
        $accountId = $entity->get('chatwootAccountId');
        $userId = $entity->get('chatwootUserId');
        $agentId = $entity->getId();

        if (!$accountId || !$userId) {
            return;
        }

        try {
            $membership = $this->entityManager
                ->getRDBRepository('ChatwootAccountUserMembership')
                ->where([
                    'chatwootAccountId' => $accountId,
                    'chatwootUserId' => $userId,
                    'chatwootAgentId' => $agentId,
                ])
                ->findOne();

            if ($membership) {
                $membership->set('chatwootAgentId', null);
                $this->entityManager->saveEntity($membership, ['silent' => true]);

                $this->log->info(
                    "DeleteFromChatwoot: Cleared chatwootAgentId on membership {$membership->getId()} for deleted agent {$agentId}"
                );
            }
        } catch (\Throwable $e) {
            $this->log->warning(
                "DeleteFromChatwoot: Failed to clear agentId from membership for agent {$agentId}: " .
                $e->getMessage()
            );
        }
    }
}
