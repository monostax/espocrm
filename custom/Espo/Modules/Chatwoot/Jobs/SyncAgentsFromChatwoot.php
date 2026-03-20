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
use Espo\Modules\Chatwoot\Services\ChatwootAccountUserMembershipService;

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
        private Log $log,
        private ChatwootAccountUserMembershipService $membershipService
    ) {}

    public function run(): void
    {
        $this->log->debug('SyncAgentsFromChatwoot: Job started');

        try {
            $accounts = $this->getEnabledAccounts();
            $accountList = iterator_to_array($accounts);
            $accountCount = count($accountList);

            $this->log->debug("SyncAgentsFromChatwoot: Found {$accountCount} account(s) to sync");

            foreach ($accountList as $account) {
                $this->syncAccountAgents($account);
            }

            $this->log->debug("SyncAgentsFromChatwoot: Job completed - processed {$accountCount} account(s)");
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

            // Get teams from the ChatwootAccount
            $teamsIds = $this->getAccountTeamsIds($account);

            // Sync agents
            $stats = $this->syncAgents(
                $platformUrl,
                $apiKey,
                $chatwootAccountId,
                $account->getId(),
                $account->get('platformId'),
                $teamsIds
            );

            $this->log->debug(
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

        $this->log->debug(
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
                $this->log->debug(
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
        $platformUserId = (int) $chatwootAgent['id'];
        $email = $chatwootAgent['email'] ?? null;

        // Check if ChatwootAgent already exists by finding the ChatwootUser
        // with this platform user ID, then looking for an agent linked to that
        // user in this account.
        $existingAgent = null;
        $chatwootUser = $email
            ? $this->entityManager->getRDBRepository('ChatwootUser')
                ->where(['email' => $email, 'platformId' => $platformId])
                ->findOne()
            : null;

        if ($chatwootUser) {
            $existingAgent = $this->entityManager
                ->getRDBRepository('ChatwootAgent')
                ->where([
                    'chatwootUserId' => $chatwootUser->getId(),
                    'chatwootAccountId' => $espoAccountId,
                ])
                ->findOne();
        }

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

        // Upsert membership if agent is linked to a user
        if ($agent->get('chatwootUserId') && $agent->get('chatwootAccountId')) {
            $this->membershipService->upsertMembership(
                $agent->get('chatwootAccountId'),
                $agent->get('chatwootUserId'),
                $agent->get('role') ?? 'agent',
                $agent->getId()
            );
        }
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

            // Upsert membership for newly created agent linked to a user
            if ($agent->get('chatwootAccountId')) {
                $this->membershipService->upsertMembership(
                    $agent->get('chatwootAccountId'),
                    $agent->get('chatwootUserId'),
                    $agent->get('role') ?? 'agent',
                    $agent->getId()
                );
            }
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
     * Remove agents that are no longer in Chatwoot.
     *
     * When Chatwoot reports that an agent no longer exists for an account,
     * the local ChatwootAgent record is deleted (not just error-marked).
     * The associated membership is also removed. If the underlying
     * ChatwootUser has no remaining memberships across any account,
     * the ChatwootUser is removed as well (the platform user was deleted).
     *
     * @param array<int> $seenPlatformUserIds Platform user IDs that were seen in the sync
     */
    private function markRemovedAgents(string $espoAccountId, array $seenPlatformUserIds): void
    {
        if (empty($seenPlatformUserIds)) {
            return;
        }

        // Find all agents for this account
        $allAgents = $this->entityManager
            ->getRDBRepository('ChatwootAgent')
            ->where(['chatwootAccountId' => $espoAccountId])
            ->find();

        // Filter to agents whose linked ChatwootUser's platform ID is NOT in the seen set
        $removedAgents = [];
        foreach ($allAgents as $agent) {
            $userId = $agent->get('chatwootUserId');
            if (!$userId) {
                // Agent without a linked user is stale
                $removedAgents[] = $agent;
                continue;
            }
            $user = $this->entityManager->getEntityById('ChatwootUser', $userId);
            $platformUserId = $user ? (int) $user->get('chatwootUserId') : 0;
            if (!in_array($platformUserId, $seenPlatformUserIds, true)) {
                $removedAgents[] = $agent;
            }
        }

        foreach ($removedAgents as $agent) {
            $agentName = $agent->get('name');
            $chatwootUserId = $agent->get('chatwootUserId');

            // Remove the associated membership first
            if ($chatwootUserId && $agent->get('chatwootAccountId')) {
                $membership = $this->membershipService->resolveMembershipForAgent($agent);

                if ($membership) {
                    try {
                        $this->entityManager->removeEntity($membership);
                        $this->log->info(
                            "SyncAgentsFromChatwoot: Removed membership for agent '{$agentName}'"
                        );
                    } catch (\Exception $e) {
                        $this->log->error(
                            "SyncAgentsFromChatwoot: Failed to remove membership for agent '{$agentName}': " .
                            $e->getMessage()
                        );
                    }
                }
            }

            // Remove the agent record
            try {
                $this->entityManager->removeEntity($agent);
                $this->log->info(
                    "SyncAgentsFromChatwoot: Removed agent '{$agentName}' (no longer in Chatwoot)"
                );
            } catch (\Exception $e) {
                $this->log->error(
                    "SyncAgentsFromChatwoot: Failed to remove agent '{$agentName}': " .
                    $e->getMessage()
                );
            }

            // Check if the ChatwootUser has any remaining memberships.
            // If not, the platform user was deleted — remove the CRM record too.
            if ($chatwootUserId) {
                $this->removeOrphanedUser($chatwootUserId);
            }
        }
    }

    /**
     * Remove a ChatwootUser if it has no remaining memberships across any account.
     *
     * A user with zero memberships means the platform-level user was deleted
     * from Chatwoot, so the CRM record should be cleaned up.
     */
    private function removeOrphanedUser(string $chatwootUserId): void
    {
        $remainingMemberships = $this->entityManager
            ->getRDBRepository('ChatwootAccountUserMembership')
            ->where(['chatwootUserId' => $chatwootUserId])
            ->count();

        if ($remainingMemberships > 0) {
            return;
        }

        $user = $this->entityManager->getEntityById('ChatwootUser', $chatwootUserId);

        if (!$user) {
            return;
        }

        try {
            $this->entityManager->removeEntity($user);
            $this->log->info(
                "SyncAgentsFromChatwoot: Removed orphaned ChatwootUser {$chatwootUserId} " .
                "(no remaining memberships)"
            );
        } catch (\Exception $e) {
            $this->log->error(
                "SyncAgentsFromChatwoot: Failed to remove orphaned ChatwootUser {$chatwootUserId}: " .
                $e->getMessage()
            );
        }
    }

    /**
     * Get team IDs from a ChatwootAccount.
     *
     * @return array<string>
     */
    private function getAccountTeamsIds(Entity $account): array
    {
        return $account->getLinkMultipleIdList('teams');
    }
}
