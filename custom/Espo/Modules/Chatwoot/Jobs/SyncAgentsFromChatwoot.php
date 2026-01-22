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

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use Espo\ORM\Entity;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Scheduled job to sync agents from Chatwoot to EspoCRM.
 * Iterates through all ChatwootAccount records with contactSyncEnabled = true
 * and pulls agents from Chatwoot.
 */
class SyncAgentsFromChatwoot implements JobDataLess
{
    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    public function run(): void
    {
        $this->log->warning('SyncAgentsFromChatwoot: Job started');

        try {
            $accounts = $this->getEnabledAccounts();
            $accountList = iterator_to_array($accounts);
            $accountCount = count($accountList);

            $this->log->warning("SyncAgentsFromChatwoot: Found {$accountCount} account(s) to sync");

            foreach ($accountList as $account) {
                $this->syncAccountAgents($account);
            }

            $this->log->warning("SyncAgentsFromChatwoot: Job completed - processed {$accountCount} account(s)");
        } catch (\Throwable $e) {
            $this->log->error('SyncAgentsFromChatwoot: Job failed - ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    /**
     * Get all ChatwootAccounts with contact sync enabled.
     *
     * @return iterable<Entity>
     */
    private function getEnabledAccounts(): iterable
    {
        return $this->entityManager
            ->getRDBRepository('ChatwootAccount')
            ->where([
                'contactSyncEnabled' => true,
                'status' => 'active',
            ])
            ->find();
    }

    /**
     * Sync agents for a single ChatwootAccount.
     */
    private function syncAccountAgents(Entity $account): void
    {
        $accountName = $account->get('name');

        try {
            $platform = $this->entityManager->getEntityById(
                'ChatwootPlatform',
                $account->get('platformId')
            );

            if (!$platform) {
                throw new \Exception('ChatwootPlatform not found');
            }

            $platformUrl = $platform->get('backendUrl');
            $apiKey = $account->get('apiKey');
            $chatwootAccountId = $account->get('chatwootAccountId');

            if (!$platformUrl || !$apiKey || !$chatwootAccountId) {
                throw new \Exception('Missing platform URL, API key, or Chatwoot account ID');
            }

            // Get team from the ChatwootAccount
            $teamId = $this->getAccountTeamId($account);
            $teamsIds = $teamId ? [$teamId] : [];

            // Sync agents
            $stats = $this->syncAgents(
                $platformUrl,
                $apiKey,
                $chatwootAccountId,
                $account->getId(),
                $account->get('platformId'),
                $teamsIds
            );

            $this->log->warning(
                "SyncAgentsFromChatwoot: Account {$accountName} - " .
                "{$stats['synced']} synced, {$stats['errors']} errors"
            );

        } catch (\Exception $e) {
            $this->log->error(
                "SyncAgentsFromChatwoot: Sync failed for account {$accountName}: " . $e->getMessage()
            );
        }
    }

    /**
     * Sync agents from Chatwoot to EspoCRM.
     *
     * @param array<string> $teamsIds Team IDs to assign to synced entities
     * @return array{synced: int, errors: int}
     */
    private function syncAgents(
        string $platformUrl,
        string $apiKey,
        int $chatwootAccountId,
        string $espoAccountId,
        string $platformId,
        array $teamsIds = []
    ): array {
        $stats = ['synced' => 0, 'errors' => 0];

        $agents = $this->apiClient->listAgents(
            $platformUrl,
            $apiKey,
            $chatwootAccountId
        );

        $this->log->warning(
            "SyncAgentsFromChatwoot: Found " . count($agents) . " agents"
        );

        // Track which agent IDs we've seen for cleanup
        $seenAgentIds = [];

        foreach ($agents as $chatwootAgent) {
            try {
                $this->syncSingleAgent($chatwootAgent, $espoAccountId, $platformId, $teamsIds);
                $stats['synced']++;
                $seenAgentIds[] = (int) $chatwootAgent['id'];
            } catch (\Exception $e) {
                $stats['errors']++;
                $agentId = $chatwootAgent['id'] ?? 'unknown';
                $this->log->warning(
                    "SyncAgentsFromChatwoot: Failed to sync agent {$agentId}: " . $e->getMessage()
                );
            }
        }

        // Mark agents not in response as deleted/removed
        $this->markRemovedAgents($espoAccountId, $seenAgentIds);

        return $stats;
    }

    /**
     * Sync a single agent from Chatwoot to EspoCRM.
     *
     * @param array<string> $teamsIds Team IDs to assign to synced entities
     */
    private function syncSingleAgent(array $chatwootAgent, string $espoAccountId, string $platformId, array $teamsIds = []): void
    {
        $chatwootAgentId = (int) $chatwootAgent['id'];

        // Check if ChatwootAgent already exists
        $existingAgent = $this->entityManager
            ->getRDBRepository('ChatwootAgent')
            ->where([
                'chatwootAgentId' => $chatwootAgentId,
                'chatwootAccountId' => $espoAccountId,
            ])
            ->findOne();

        if ($existingAgent) {
            $this->updateExistingAgent($existingAgent, $chatwootAgent, $platformId, $teamsIds);
        } else {
            $this->createNewAgent($chatwootAgent, $espoAccountId, $platformId, $teamsIds);
        }
    }

    private const AI_PREFIX = '✦ ';

    /**
     * Update an existing ChatwootAgent from Chatwoot data.
     *
     * @param array<string> $teamsIds Team IDs to assign to synced entities
     */
    private function updateExistingAgent(Entity $agent, array $chatwootAgent, string $platformId, array $teamsIds = []): void
    {
        $name = $chatwootAgent['name'] ?? 'Agent #' . $chatwootAgent['id'];
        
        // Preserve AI prefix if agent is configured as AI
        if ($agent->get('isAI')) {
            $name = self::AI_PREFIX . $name;
        }
        
        $agent->set('name', $name);
        $agent->set('email', $chatwootAgent['email'] ?? null);
        $agent->set('availableName', $chatwootAgent['available_name'] ?? null);
        $agent->set('role', $chatwootAgent['role'] ?? 'agent');
        $agent->set('availabilityStatus', $chatwootAgent['availability_status'] ?? 'offline');
        $agent->set('autoOffline', $chatwootAgent['auto_offline'] ?? true);
        $agent->set('confirmed', $chatwootAgent['confirmed'] ?? false);
        $agent->set('avatarUrl', $chatwootAgent['thumbnail'] ?? null);
        $agent->set('customRoleId', $chatwootAgent['custom_role_id'] ?? null);
        $agent->set('syncStatus', 'synced');
        $agent->set('lastSyncedAt', date('Y-m-d H:i:s'));
        $agent->set('lastSyncError', null);

        // Try to link to ChatwootUser by email (in the same platform)
        $this->linkToChatwootUser($agent, $chatwootAgent['email'] ?? null, $platformId);

        // Assign teams from ChatwootAccount
        if (!empty($teamsIds)) {
            $agent->set('teamsIds', $teamsIds);
        }

        $this->entityManager->saveEntity($agent, ['silent' => true]);
    }

    /**
     * Create a new ChatwootAgent from Chatwoot data.
     *
     * @param array<string> $teamsIds Team IDs to assign to synced entities
     */
    private function createNewAgent(array $chatwootAgent, string $espoAccountId, string $platformId, array $teamsIds = []): void
    {
        $data = [
            'name' => $chatwootAgent['name'] ?? 'Agent #' . $chatwootAgent['id'],
            'email' => $chatwootAgent['email'] ?? null,
            'availableName' => $chatwootAgent['available_name'] ?? null,
            'role' => $chatwootAgent['role'] ?? 'agent',
            'availabilityStatus' => $chatwootAgent['availability_status'] ?? 'offline',
            'autoOffline' => $chatwootAgent['auto_offline'] ?? true,
            'confirmed' => $chatwootAgent['confirmed'] ?? false,
            'avatarUrl' => $chatwootAgent['thumbnail'] ?? null,
            'customRoleId' => $chatwootAgent['custom_role_id'] ?? null,
            'chatwootAgentId' => $chatwootAgent['id'],
            'chatwootAccountId' => $espoAccountId,
            'syncStatus' => 'synced',
            'lastSyncedAt' => date('Y-m-d H:i:s'),
        ];

        // Assign teams from ChatwootAccount
        if (!empty($teamsIds)) {
            $data['teamsIds'] = $teamsIds;
        }

        $agent = $this->entityManager->createEntity('ChatwootAgent', $data, ['silent' => true]);

        // Try to link to ChatwootUser by email (in the same platform)
        $this->linkToChatwootUser($agent, $chatwootAgent['email'] ?? null, $platformId);
        
        if ($agent->get('chatwootUserId')) {
            $this->entityManager->saveEntity($agent, ['silent' => true]);
        }
    }

    /**
     * Try to link a ChatwootAgent to an existing ChatwootUser by email in the same platform.
     */
    private function linkToChatwootUser(Entity $agent, ?string $email, string $platformId): void
    {
        if (!$email) {
            return;
        }

        // Find ChatwootUser by email in the same platform
        $chatwootUser = $this->entityManager
            ->getRDBRepository('ChatwootUser')
            ->where([
                'email' => $email,
                'platformId' => $platformId,
            ])
            ->findOne();

        if ($chatwootUser) {
            $agent->set('chatwootUserId', $chatwootUser->getId());
            $agent->set('confirmed', true); // User exists, so agent is confirmed
        }
    }

    /**
     * Mark agents that are no longer in Chatwoot as removed.
     *
     * @param array<int> $seenAgentIds Chatwoot agent IDs that were seen in the sync
     */
    private function markRemovedAgents(string $espoAccountId, array $seenAgentIds): void
    {
        if (empty($seenAgentIds)) {
            return;
        }

        // Find agents that weren't in the API response
        $removedAgents = $this->entityManager
            ->getRDBRepository('ChatwootAgent')
            ->where([
                'chatwootAccountId' => $espoAccountId,
                'chatwootAgentId!=' => $seenAgentIds,
                'syncStatus!=' => 'error',
            ])
            ->find();

        foreach ($removedAgents as $agent) {
            $agent->set('syncStatus', 'error');
            $agent->set('lastSyncError', 'Agent no longer exists in Chatwoot');
            $agent->set('lastSyncedAt', date('Y-m-d H:i:s'));
            $this->entityManager->saveEntity($agent, ['silent' => true]);

            $this->log->info(
                "SyncAgentsFromChatwoot: Marked agent {$agent->get('chatwootAgentId')} as removed"
            );
        }
    }

    /**
     * Get team ID from a ChatwootAccount.
     *
     * @return string|null
     */
    private function getAccountTeamId(Entity $account): ?string
    {
        return $account->get('teamId');
    }
}
