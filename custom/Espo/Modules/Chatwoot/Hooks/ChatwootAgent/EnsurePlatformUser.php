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

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Hook to ensure platform user linkage when assignedUser is set on ChatwootAgent.
 * 
 * When an EspoCRM User is assigned to a ChatwootAgent:
 * 1. Propagates the assignedUserId to the linked ChatwootUser entity
 * 2. Ensures the platform user is attached to the Chatwoot account
 * 
 * This enables SSO to work by ensuring the ChatwootUser knows which EspoCRM User it belongs to.
 * 
 * Runs after SyncWithChatwoot (10) and LinkToUser (20) so the agent is fully synced and linked.
 */
class EnsurePlatformUser
{
    public static int $order = 25;

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    /**
     * After a ChatwootAgent is saved with assignedUser, ensure ChatwootUser is properly linked.
     * 
     * @param Entity $entity
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        // Skip for silent saves (from sync jobs)
        if (!empty($options['silent'])) {
            return;
        }

        // Skip if this is a recursive call
        if (!empty($options['skipEnsurePlatformUser'])) {
            return;
        }

        $assignedUserId = $entity->get('assignedUserId');
        $chatwootUserId = $entity->get('chatwootUserId');

        // If assignedUser was cleared, also clear it from ChatwootUser
        if (!$assignedUserId && $chatwootUserId) {
            $this->clearAssignedUserFromChatwootUser($chatwootUserId);
            return;
        }

        // Nothing to do if no assignedUser is set
        if (!$assignedUserId) {
            return;
        }

        // If agent has a linked ChatwootUser, update its assignedUserId
        if ($chatwootUserId) {
            $this->updateChatwootUserAssignment($chatwootUserId, $assignedUserId);
            return;
        }

        // No ChatwootUser linked yet - try to ensure platform user is attached
        $this->ensurePlatformUserAttached($entity, $assignedUserId);
    }

    /**
     * Clear assignedUser from ChatwootUser when it's cleared from agent.
     */
    private function clearAssignedUserFromChatwootUser(string $chatwootUserId): void
    {
        $chatwootUser = $this->entityManager->getEntityById('ChatwootUser', $chatwootUserId);
        
        if (!$chatwootUser) {
            return;
        }

        // Only clear if this agent was the one that set it
        // (In multi-agent scenarios, another agent might have the same user assigned)
        $hasOtherAgentsWithSameUser = $this->entityManager
            ->getRDBRepository('ChatwootAgent')
            ->where([
                'chatwootUserId' => $chatwootUserId,
                'assignedUserId!=' => null,
            ])
            ->count() > 0;

        if (!$hasOtherAgentsWithSameUser) {
            $chatwootUser->set('assignedUserId', null);
            $this->entityManager->saveEntity($chatwootUser, ['silent' => true]);
            
            $this->log->info(
                "EnsurePlatformUser: Cleared assignedUser from ChatwootUser {$chatwootUserId}"
            );
        }
    }

    /**
     * Update ChatwootUser's assignedUserId to match the agent's.
     */
    private function updateChatwootUserAssignment(string $chatwootUserId, string $assignedUserId): void
    {
        $chatwootUser = $this->entityManager->getEntityById('ChatwootUser', $chatwootUserId);
        
        if (!$chatwootUser) {
            $this->log->warning(
                "EnsurePlatformUser: ChatwootUser {$chatwootUserId} not found"
            );
            return;
        }

        // Only update if different
        if ($chatwootUser->get('assignedUserId') === $assignedUserId) {
            return;
        }

        $chatwootUser->set('assignedUserId', $assignedUserId);
        $this->entityManager->saveEntity($chatwootUser, ['silent' => true]);

        $this->log->info(
            "EnsurePlatformUser: Updated ChatwootUser {$chatwootUserId} assignedUserId to {$assignedUserId}"
        );
    }

    /**
     * Ensure the agent's platform user is attached to the account and tracked in EspoCRM.
     * This handles the case where an agent exists but wasn't created through EspoCRM
     * (e.g., synced from Chatwoot directly).
     */
    private function ensurePlatformUserAttached(Entity $agent, string $assignedUserId): void
    {
        $accountId = $agent->get('chatwootAccountId');
        $chatwootAgentId = $agent->get('chatwootAgentId');
        
        if (!$accountId || !$chatwootAgentId) {
            return;
        }

        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
        if (!$account) {
            return;
        }

        $platformId = $account->get('platformId');
        $chatwootAccountId = $account->get('chatwootAccountId');
        
        if (!$platformId || !$chatwootAccountId) {
            return;
        }

        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
        if (!$platform) {
            return;
        }

        $platformUrl = $platform->get('backendUrl');
        $platformAccessToken = $platform->get('accessToken');
        
        if (!$platformUrl || !$platformAccessToken) {
            return;
        }

        // Try to get the agent's user from Chatwoot API
        try {
            $apiKey = $account->get('apiKey');
            if (!$apiKey) {
                return;
            }

            // List agents to find the one with this chatwootAgentId
            $agents = $this->apiClient->listAgents($platformUrl, $apiKey, $chatwootAccountId);
            
            $agentData = null;
            foreach ($agents as $a) {
                if (isset($a['id']) && $a['id'] == $chatwootAgentId) {
                    $agentData = $a;
                    break;
                }
            }

            if (!$agentData) {
                $this->log->warning(
                    "EnsurePlatformUser: Agent {$chatwootAgentId} not found in Chatwoot account {$chatwootAccountId}"
                );
                return;
            }

            // Check if agent is confirmed (has a platform user)
            if (empty($agentData['confirmed'])) {
                $this->log->info(
                    "EnsurePlatformUser: Agent {$chatwootAgentId} is not confirmed yet (no platform user)"
                );
                return;
            }

            // Find or create ChatwootUser by email
            $email = $agentData['email'] ?? $agent->get('email');
            if (!$email) {
                return;
            }

            $chatwootUser = $this->entityManager
                ->getRDBRepository('ChatwootUser')
                ->where([
                    'email' => $email,
                    'platformId' => $platformId,
                ])
                ->findOne();

            if ($chatwootUser) {
                // Link agent to this user
                $agent->set('chatwootUserId', $chatwootUser->getId());
                $chatwootUser->set('assignedUserId', $assignedUserId);
                
                $this->entityManager->saveEntity($chatwootUser, ['silent' => true]);
                $this->entityManager->saveEntity($agent, ['silent' => true, 'skipEnsurePlatformUser' => true]);

                $this->log->info(
                    "EnsurePlatformUser: Linked ChatwootAgent {$agent->getId()} to existing ChatwootUser {$chatwootUser->getId()}"
                );
            }
            // Note: We don't create ChatwootUser here because we don't have the chatwootUserId
            // The user would need to be synced first via platform sync job

        } catch (\Exception $e) {
            $this->log->warning(
                "EnsurePlatformUser: Failed to ensure platform user for agent: " . $e->getMessage()
            );
        }
    }
}
