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
 * Writes directly to ChatwootAccountUserMembership — no ChatwootAgent entity.
 *
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
            $message = $e->getMessage();

            // Destructive cleanup requires a confirmed 404:
            // 1) account endpoint returned 404; and
            // 2) users API is reachable (to avoid proxy fallback 404s).
            if ($this->isConfirmedAccountGone($account, $message)) {
                $this->log->warning(
                    "SyncAgentsFromChatwoot: Account {$accountName} returned confirmed 404 — " .
                    "account likely deleted from Chatwoot (source of truth). " .
                    "Cleaning up local memberships and orphaned users."
                );
                $this->cleanupAccountMembershipsAndUsers($account);
            } elseif ($this->isAccountGoneError($message)) {
                $this->log->warning(
                    "SyncAgentsFromChatwoot: Account {$accountName} returned 404 but Users API probe failed. " .
                    "Skipping destructive cleanup to avoid false positives."
                );
            } else {
                $this->log->error(
                    "SyncAgentsFromChatwoot: Sync failed for account {$accountName}: " . $message
                );
            }
        }
    }

    /**
     * Sync agents from Chatwoot to EspoCRM memberships.
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

        $agentCount = count($agents);
        $this->log->debug(
            "SyncAgentsFromChatwoot: Found {$agentCount} agents"
        );

        // Safety check: refuse to treat an empty API response as "delete all".
        // An empty list is almost always an API error (auth issue, timeout, etc.),
        // not a legitimate "all agents were removed" scenario.
        $existingMembershipCount = $this->entityManager
            ->getRDBRepository('ChatwootAccountUserMembership')
            ->where(['chatwootAccountId' => $espoAccountId])
            ->count();

        if ($agentCount === 0 && $existingMembershipCount > 0) {
            $this->log->warning(
                "SyncAgentsFromChatwoot: API returned 0 agents but {$existingMembershipCount} " .
                "local memberships exist for account {$espoAccountId}. " .
                "Skipping destructive cleanup to prevent data loss."
            );
            return $stats;
        }

        // Track which platform user IDs we've seen for cleanup
        $seenPlatformUserIds = [];

        foreach ($agents as $chatwootAgent) {
            try {
                $this->syncSingleAgent($chatwootAgent, $espoAccountId, $platformId, $teamsIds);
                $stats['synced']++;
                $seenPlatformUserIds[] = (int) $chatwootAgent['id'];
            } catch (\Exception $e) {
                $stats['errors']++;
                $agentId = $chatwootAgent['id'] ?? 'unknown';
                $this->log->debug(
                    "SyncAgentsFromChatwoot: Failed to sync agent {$agentId}: " . $e->getMessage()
                );
            }
        }

        // Mark memberships not in response as removed (only for THIS account)
        $this->markRemovedMembers($espoAccountId, $seenPlatformUserIds);

        return $stats;
    }

    private const AI_PREFIX = '✦ ';

    /**
     * Sync a single agent from Chatwoot to a membership.
     *
     * @param array<string> $teamsIds Team IDs to assign to synced entities
     */
    private function syncSingleAgent(array $chatwootAgent, string $espoAccountId, string $platformId, array $teamsIds = []): void
    {
        $platformUserId = (int) $chatwootAgent['id'];

        // Resolve local ChatwootUser by chatwootUserId (platform user ID) + platform.
        // NOTE: ChatwootUser.email is of EspoCRM type "email" which stores data in
        // the email_address junction table — NOT as a column on chatwoot_user.
        // Using ->where(['email' => ...]) on the ORM silently returns no results.
        // The chatwootUserId integer is the reliable unique identifier from the
        // Chatwoot Platform API and is stored directly on the chatwoot_user table.
        $chatwootUser = $this->entityManager->getRDBRepository('ChatwootUser')
            ->where([
                'chatwootUserId' => $platformUserId,
                'platformId' => $platformId,
            ])
            ->findOne();

        if (!$chatwootUser) {
            $this->log->debug(
                "SyncAgentsFromChatwoot: No local ChatwootUser for platformUserId={$platformUserId} platformId={$platformId}, skipping"
            );
            return;
        }

        // Find existing membership by (chatwootAccountId, chatwootUserId)
        $existingMembership = $this->entityManager
            ->getRDBRepository('ChatwootAccountUserMembership')
            ->where([
                'chatwootAccountId' => $espoAccountId,
                'chatwootUserId' => $chatwootUser->getId(),
            ])
            ->findOne();

        if ($existingMembership) {
            $this->updateMembershipFromChatwootAgent($existingMembership, $chatwootAgent, $teamsIds);
        } else {
            $this->createMembershipFromChatwootAgent($chatwootAgent, $espoAccountId, $chatwootUser, $teamsIds);
        }
    }

    /**
     * Update an existing membership from Chatwoot agent data.
     *
     * @param array<string> $teamsIds Team IDs to assign
     */
    private function updateMembershipFromChatwootAgent(Entity $membership, array $chatwootAgent, array $teamsIds = []): void
    {
        $name = $chatwootAgent['name'] ?? 'Agent #' . $chatwootAgent['id'];

        // Preserve AI prefix if membership is configured as AI
        if ($membership->get('isAI')) {
            $name = self::AI_PREFIX . $name;
        }

        $membership->set('name', $name);
        $membership->set('email', $chatwootAgent['email'] ?? null);
        $membership->set('availableName', $chatwootAgent['available_name'] ?? null);
        $membership->set('role', $chatwootAgent['role'] ?? 'agent');
        $membership->set('availabilityStatus', $chatwootAgent['availability_status'] ?? 'offline');
        $membership->set('autoOffline', $chatwootAgent['auto_offline'] ?? true);
        $membership->set('confirmed', $chatwootAgent['confirmed'] ?? false);
        $membership->set('avatarUrl', $chatwootAgent['thumbnail'] ?? null);
        $membership->set('customRoleId', $chatwootAgent['custom_role_id'] ?? null);
        $membership->set('syncStatus', 'synced');
        $membership->set('lastSyncedAt', date('Y-m-d H:i:s'));
        $membership->set('lastSyncError', null);

        // Assign teams from ChatwootAccount
        if (!empty($teamsIds)) {
            $membership->set('teamsIds', $teamsIds);
        }

        $this->entityManager->saveEntity($membership, ['silent' => true]);
    }

    /**
     * Create a new membership from Chatwoot agent data.
     *
     * @param array<string> $teamsIds Team IDs to assign
     */
    private function createMembershipFromChatwootAgent(
        array $chatwootAgent,
        string $espoAccountId,
        Entity $chatwootUser,
        array $teamsIds = []
    ): void {
        $role = $chatwootAgent['role'] ?? 'agent';

        // Upsert membership (creates the base record)
        $membership = $this->membershipService->upsertMembership(
            $espoAccountId,
            $chatwootUser->getId(),
            $role
        );

        // Set the unique agent fields on the membership
        $membership->set('name', $chatwootAgent['name'] ?? 'Agent #' . $chatwootAgent['id']);
        $membership->set('email', $chatwootAgent['email'] ?? null);
        $membership->set('availableName', $chatwootAgent['available_name'] ?? null);
        $membership->set('availabilityStatus', $chatwootAgent['availability_status'] ?? 'offline');
        $membership->set('autoOffline', $chatwootAgent['auto_offline'] ?? true);
        $membership->set('confirmed', $chatwootAgent['confirmed'] ?? false);
        $membership->set('avatarUrl', $chatwootAgent['thumbnail'] ?? null);
        $membership->set('customRoleId', $chatwootAgent['custom_role_id'] ?? null);
        $membership->set('isAI', false);
        $membership->set('syncStatus', 'synced');
        $membership->set('lastSyncedAt', date('Y-m-d H:i:s'));

        // Assign teams from ChatwootAccount
        if (!empty($teamsIds)) {
            $membership->set('teamsIds', $teamsIds);
        }

        $this->entityManager->saveEntity($membership, ['silent' => true]);
    }

    /**
     * Remove memberships whose platform user is no longer in Chatwoot.
     *
     * For each membership in the account, resolves the platformUserId via ChatwootUser.
     * If not in the seen set, removes the membership. If the underlying ChatwootUser
     * has no remaining memberships, removes the user too.
     *
     * SAFETY: The empty-response case (all agents removed) is handled by the caller
     * which bails out when the API returns 0 agents but local memberships exist.
     * This method is only called when seenPlatformUserIds is non-empty.
     *
     * @param array<int> $seenPlatformUserIds Platform user IDs that were seen in the sync
     */
    private function markRemovedMembers(string $espoAccountId, array $seenPlatformUserIds): void
    {
        // Find all memberships for this account
        $allMemberships = $this->entityManager
            ->getRDBRepository('ChatwootAccountUserMembership')
            ->where(['chatwootAccountId' => $espoAccountId])
            ->find();

        $removedUserIds = [];

        foreach ($allMemberships as $membership) {
            $userId = $membership->get('chatwootUserId');
            if (!$userId) {
                // Orphan membership — remove
                $this->removeMembership($membership);
                continue;
            }

            $user = $this->entityManager->getEntityById('ChatwootUser', $userId);
            $platformUserId = $user ? (int) $user->get('chatwootUserId') : 0;

            if (!in_array($platformUserId, $seenPlatformUserIds, true)) {
                $membershipName = $membership->get('name');

                $this->removeMembership($membership);

                $this->log->info(
                    "SyncAgentsFromChatwoot: Removed membership '{$membershipName}' (no longer in Chatwoot)"
                );

                // Track user ID for orphan cleanup (only for users affected by THIS account)
                $removedUserIds[$userId] = true;
            }
        }

        // Check only the directly affected users for orphan cleanup.
        // DO NOT scan all users in the platform — that causes cross-account
        // cascade deletions when other accounts haven't synced yet.
        foreach (array_keys($removedUserIds) as $userId) {
            $this->removeOrphanedUser($userId);
        }
    }

    /**
     * Remove a membership entity safely.
     */
    private function removeMembership(Entity $membership): void
    {
        try {
            $this->entityManager->removeEntity($membership);
        } catch (\Exception $e) {
            $this->log->error(
                "SyncAgentsFromChatwoot: Failed to remove membership {$membership->getId()}: " .
                $e->getMessage()
            );
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

        if ($this->isAutomationUser($user)) {
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
     * Check if the error message indicates the Chatwoot account is gone (404).
     */
    private function isAccountGoneError(string $message): bool
    {
        return (bool) preg_match('/HTTP\s+404\b/', $message);
    }

    /**
     * Confirm account-gone condition before destructive cleanup.
     *
     * A raw 404 is not enough because proxies can emit generic 404 responses
     * when backend services are unavailable. We require Users API reachability
     * to validate that Chatwoot platform endpoints are actually responding.
     */
    private function isConfirmedAccountGone(Entity $account, string $message): bool
    {
        if (!$this->isAccountGoneError($message)) {
            return false;
        }

        $platformId = $account->get('platformId');
        if (!$platformId) {
            return false;
        }

        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
        if (!$platform) {
            return false;
        }

        $platformUrl = $platform->get('backendUrl');
        $accessToken = $platform->get('accessToken');

        if (!$platformUrl || !$accessToken) {
            return false;
        }

        return $this->apiClient->isUsersApiReachable($platformUrl, $accessToken);
    }

    /**
     * Clean up all memberships and orphaned ChatwootUsers when a Chatwoot account
     * is gone (source of truth returned 401/404).
     *
     * Removes all memberships for the account, then cleans up ChatwootUsers
     * that were directly affected (have zero remaining memberships).
     *
     * SAFETY: Only cleans up users whose memberships were removed in THIS pass.
     * Does NOT scan all users in the platform to avoid cross-account cascade.
     */
    private function cleanupAccountMembershipsAndUsers(Entity $account): void
    {
        $espoAccountId = $account->getId();
        $accountName = $account->get('name');

        // Remove all memberships for this account
        $memberships = $this->entityManager
            ->getRDBRepository('ChatwootAccountUserMembership')
            ->where(['chatwootAccountId' => $espoAccountId])
            ->find();

        $removedCount = 0;
        $affectedUserIds = [];

        foreach ($memberships as $membership) {
            $userId = $membership->get('chatwootUserId');
            if ($userId) {
                $affectedUserIds[$userId] = true;
            }
            $this->removeMembership($membership);
            $removedCount++;
        }

        if ($removedCount > 0) {
            $this->log->info(
                "SyncAgentsFromChatwoot: Removed {$removedCount} membership(s) for gone account '{$accountName}'"
            );
        }

        // Only check directly affected users for orphan cleanup.
        // DO NOT scan all users in the platform — that causes cross-account
        // cascade deletions when other accounts haven't synced yet.
        foreach (array_keys($affectedUserIds) as $userId) {
            $this->removeOrphanedUser($userId);
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

    private function isAutomationUser(Entity $user): bool
    {
        $name = (string) ($user->get('name') ?? '');
        if (strpos($name, 'Automation User - ') === 0) {
            return true;
        }

        $email = (string) ($user->get('email') ?? '');

        return strpos($email, 'automation.') === 0
            && strpos($email, '@chatwoot.local') !== false;
    }
}
