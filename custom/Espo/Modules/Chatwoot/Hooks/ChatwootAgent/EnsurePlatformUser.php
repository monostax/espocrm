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
 * Hook to ensure platform user linkage on ChatwootAgent.
 *
 * Phase 5: Assignment propagation (agent→user) has been removed. The authoritative
 * assignment now lives on ChatwootUser and flows user→agent via LinkToUser/LinkToAgents.
 * This hook only handles platform user discovery/linking (finding a ChatwootUser by email
 * when agent has no linked user).
 *
 * To change the assigned user, update the Assigned User on the linked ChatwootUser record.
 *
 * Runs after SyncWithChatwoot (10) and LinkToUser (20) so the agent is fully synced and linked.
 *
 * @deprecated Phase 8 — this hook will be removed when agent-side fields are cleaned up.
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
     * After a ChatwootAgent is saved, try to discover and link a matching platform user
     * if no ChatwootUser is linked yet.
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

        // Nothing to do if no assignedUser is set
        if (!$assignedUserId) {
            return;
        }

        // If agent already has a linked ChatwootUser, nothing to discover
        if ($chatwootUserId) {
            return;
        }

        // No ChatwootUser linked yet - try to ensure platform user is attached
        $this->ensurePlatformUserAttached($entity, $assignedUserId);
    }

    /**
     * Ensure the agent's platform user is attached to the account and tracked in EspoCRM.
     * This handles the case where an agent exists but wasn't created through EspoCRM
     * (e.g., synced from Chatwoot directly).
     *
     * Note: This method links the agent to a discovered ChatwootUser but does NOT
     * propagate assignedUserId onto the ChatwootUser. The assignment must be set
     * on the ChatwootUser record by an admin (Phase 5 source-of-truth change).
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
                // Link agent to this user (agent→user link only, no assignment propagation)
                $agent->set('chatwootUserId', $chatwootUser->getId());

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
