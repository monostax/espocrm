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
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Chatwoot\Services\ChatwootAccountUserMembershipService;

/**
 * Scheduled job to sync account user memberships from Chatwoot's
 * authoritative account_users endpoint (Platform API) to the local
 * ChatwootAccountUserMembership table.
 *
 * Uses platform accessToken (not account apiKey) — novel credential path.
 * Runs every 5 minutes.
 */
class SyncAccountMembersFromChatwoot implements JobDataLess
{
    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log,
        private ChatwootAccountUserMembershipService $membershipService
    ) {}

    public function run(): void
    {
        $this->log->debug('SyncAccountMembersFromChatwoot: Job started');

        try {
            $accounts = $this->getEnabledAccounts();
            $accountList = iterator_to_array($accounts);
            $accountCount = count($accountList);

            $this->log->debug("SyncAccountMembersFromChatwoot: Found {$accountCount} account(s) to sync");

            foreach ($accountList as $account) {
                $this->syncAccountMembers($account);
            }

            $this->log->debug("SyncAccountMembersFromChatwoot: Job completed - processed {$accountCount} account(s)");
        } catch (\Throwable $e) {
            $this->log->error(
                'SyncAccountMembersFromChatwoot: Job failed - ' . $e->getMessage() .
                ' at ' . $e->getFile() . ':' . $e->getLine()
            );
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
     * Sync account user memberships for a single ChatwootAccount.
     */
    private function syncAccountMembers(Entity $account): void
    {
        $accountName = $account->get('name');

        try {
            // --- Credential loading (novel path: platform accessToken) ---
            $platform = $this->entityManager->getEntityById(
                'ChatwootPlatform',
                $account->get('platformId')
            );

            if (!$platform) {
                throw new \Exception('ChatwootPlatform not found');
            }

            $platformUrl = $platform->get('backendUrl');
            $accessToken = $platform->get('accessToken');
            $chatwootAccountId = $account->get('chatwootAccountId');

            // Falsy guard to catch both null and '' (empty string)
            if (!$platformUrl || !$accessToken || !$chatwootAccountId) {
                $this->log->warning(
                    "SyncAccountMembersFromChatwoot: Skipping account {$accountName} - " .
                    'missing platform URL, access token, or Chatwoot account ID'
                );
                return;
            }

            $espoAccountId = $account->getId();
            $platformId = $account->get('platformId');

            // --- API call ---
            $remoteAccountUsers = $this->apiClient->listAccountUsers(
                $platformUrl,
                $accessToken,
                $chatwootAccountId
            );

            // --- Build remote-truth key set FIRST ---
            $remoteUserIds = [];
            foreach ($remoteAccountUsers as $remoteAccountUser) {
                $remoteUserIds[] = (int) $remoteAccountUser['user_id'];
            }

            // --- Process each remote account_user ---
            // Note: an empty remote list is valid (all users removed from Chatwoot account).
            // The stale detection pass below will handle cleanup.
            $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'stale' => 0];

            foreach ($remoteAccountUsers as $remoteAccountUser) {
                $remoteUserId = (int) $remoteAccountUser['user_id'];
                $remoteRole = $remoteAccountUser['role'] ?? 'agent';
                $remoteAccountUserId = (int) $remoteAccountUser['id'];

                // Resolve local ChatwootUser by (chatwootUserId, platformId)
                $localUser = $this->entityManager
                    ->getRDBRepository('ChatwootUser')
                    ->where([
                        'chatwootUserId' => $remoteUserId,
                        'platformId' => $platformId,
                    ])
                    ->findOne();

                if (!$localUser) {
                    // User hasn't been synced to CRM yet — skip, don't error-mark
                    $this->log->debug(
                        "SyncAccountMembersFromChatwoot: No local ChatwootUser for " .
                        "chatwootUserId={$remoteUserId} platformId={$platformId} - skipping"
                    );
                    $stats['skipped']++;
                    continue;
                }

                // Upsert membership
                $existingMembership = $this->entityManager
                    ->getRDBRepository('ChatwootAccountUserMembership')
                    ->where([
                        'chatwootAccountId' => $espoAccountId,
                        'chatwootUserId' => $localUser->getId(),
                    ])
                    ->findOne();

                $isNew = !$existingMembership;

                $membership = $this->membershipService->upsertMembership(
                    $espoAccountId,
                    $localUser->getId(),
                    $remoteRole,
                    $remoteAccountUserId
                );

                // Update sync status
                $this->membershipService->updateSyncStatus($membership, 'synced');

                if ($isNew) {
                    $stats['created']++;
                } else {
                    $stats['updated']++;
                }
            }

            // --- Stale detection + removal ---
            // Re-fetch local memberships (may have been created/updated above)
            $allLocalMemberships = $this->entityManager
                ->getRDBRepository('ChatwootAccountUserMembership')
                ->where(['chatwootAccountId' => $espoAccountId])
                ->find();

            foreach ($allLocalMemberships as $membership) {
                $userId = $membership->get('chatwootUserId');
                $shouldRemove = false;

                if (!$userId) {
                    // Orphan membership — no linked user
                    $shouldRemove = true;
                } else {
                    $localUser = $this->entityManager->getEntityById('ChatwootUser', $userId);

                    if (!$localUser) {
                        // Orphan — ChatwootUser entity doesn't exist
                        $shouldRemove = true;
                    } else {
                        $chatwootUserId = $localUser->get('chatwootUserId');

                        if (!$chatwootUserId) {
                            // Local user has no external Chatwoot user ID — can't verify
                            continue;
                        }

                        if (!in_array($chatwootUserId, $remoteUserIds, true)) {
                            // User was removed from the Chatwoot account
                            $shouldRemove = true;
                        }
                    }
                }

                if ($shouldRemove) {
                    $this->removeStaleMembership($membership);
                    $stats['stale']++;
                }
            }

            // --- Orphaned ChatwootUser cleanup ---
            // Clean up ChatwootUsers in the platform that have zero memberships.
            // Handles the case where memberships were deleted through a different path.
            $this->cleanupOrphanedUsersForAccount($espoAccountId);

            $this->log->debug(
                "SyncAccountMembersFromChatwoot: Account {$accountName} - " .
                "created={$stats['created']}, updated={$stats['updated']}, " .
                "skipped={$stats['skipped']}, stale={$stats['stale']}"
            );

        } catch (\Exception $e) {
            $message = $e->getMessage();

            // Destructive cleanup requires a confirmed 404:
            // 1) account endpoint returned 404; and
            // 2) users API is reachable (to avoid proxy fallback 404s).
            if ($this->isConfirmedAccountGone($account, $message)) {
                $this->log->warning(
                    "SyncAccountMembersFromChatwoot: Account {$accountName} returned confirmed 404 — " .
                    "account likely deleted from Chatwoot (source of truth). " .
                    "Cleaning up local memberships and orphaned users."
                );
                $this->cleanupAccountMembershipsAndUsers($account);
            } elseif ($this->isAccountGoneError($message)) {
                $this->log->warning(
                    "SyncAccountMembersFromChatwoot: Account {$accountName} returned 404 but Users API probe failed. " .
                    "Skipping destructive cleanup to avoid false positives."
                );
            } else {
                $this->log->error(
                    "SyncAccountMembersFromChatwoot: Sync failed for account {$accountName}: " .
                    $message
                );
            }
        }
    }

    /**
     * Remove a stale membership.
     *
     * If the underlying ChatwootUser has no remaining memberships after
     * removal, the user record is deleted too (platform user was deleted).
     */
    private function removeStaleMembership(Entity $membership): void
    {
        $membershipId = $membership->getId();
        $chatwootUserId = $membership->get('chatwootUserId');

        // Remove the membership
        try {
            $this->entityManager->removeEntity($membership);
            $this->log->info(
                "SyncAccountMembersFromChatwoot: Removed stale membership {$membershipId}"
            );
        } catch (\Exception $e) {
            $this->log->error(
                "SyncAccountMembersFromChatwoot: Failed to remove membership {$membershipId}: " .
                $e->getMessage()
            );
        }

        // If the ChatwootUser has no remaining memberships, remove it too
        if ($chatwootUserId) {
            $this->removeOrphanedUser($chatwootUserId);
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
                "SyncAccountMembersFromChatwoot: Removed orphaned ChatwootUser {$chatwootUserId} " .
                "(chatwootUserId={$user->get('chatwootUserId')}, no remaining memberships)"
            );
        } catch (\Exception $e) {
            $this->log->error(
                "SyncAccountMembersFromChatwoot: Failed to remove orphaned ChatwootUser {$chatwootUserId}: " .
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
     * Removes all memberships for the account, then cleans up any ChatwootUsers
     * that have zero remaining memberships across all accounts in the platform.
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
        foreach ($memberships as $membership) {
            $this->removeStaleMembership($membership);
            $removedCount++;
        }

        if ($removedCount > 0) {
            $this->log->info(
                "SyncAccountMembersFromChatwoot: Removed {$removedCount} membership(s) for gone account '{$accountName}'"
            );
        }

        // Clean up orphaned ChatwootUsers in this platform
        $this->cleanupOrphanedUsersForAccount($espoAccountId);
    }

    /**
     * Clean up orphaned memberships and ChatwootUsers in the account's platform.
     *
     * First removes memberships that reference deleted/gone ChatwootAccounts in
     * this platform (dangling FKs from old accounts). Then removes any
     * ChatwootUsers with zero remaining memberships.
     */
    private function cleanupOrphanedUsersForAccount(string $espoAccountId): void
    {
        $account = $this->entityManager->getEntityById('ChatwootAccount', $espoAccountId);
        if (!$account) {
            return;
        }

        $platformId = $account->get('platformId');
        if (!$platformId) {
            return;
        }

        // Phase 1: Remove memberships on deleted accounts in this platform.
        $this->cleanupMembershipsOnDeletedAccounts($platformId);

        // Phase 2: Remove ChatwootUsers with zero remaining memberships.
        $users = $this->entityManager
            ->getRDBRepository('ChatwootUser')
            ->where(['platformId' => $platformId])
            ->find();

        foreach ($users as $user) {
            $remainingMemberships = $this->entityManager
                ->getRDBRepository('ChatwootAccountUserMembership')
                ->where(['chatwootUserId' => $user->getId()])
                ->count();

            if ($remainingMemberships === 0) {
                if ($this->isAutomationUser($user)) {
                    continue;
                }

                try {
                    $userName = $user->get('name');
                    $this->entityManager->removeEntity($user);
                    $this->log->info(
                        "SyncAccountMembersFromChatwoot: Cleaned up orphaned ChatwootUser '{$userName}' " .
                        "({$user->getId()}) — zero memberships in platform {$platformId}"
                    );
                } catch (\Exception $e) {
                    $this->log->error(
                        "SyncAccountMembersFromChatwoot: Failed to clean up ChatwootUser {$user->getId()}: " .
                        $e->getMessage()
                    );
                }
            }
        }
    }

    /**
     * Remove memberships that belong to soft-deleted ChatwootAccounts in a platform.
     *
     * Uses raw SQL because EspoCRM's ORM `find()` on ChatwootAccount already
     * filters `deleted = 0`, so we can't join to deleted accounts with the ORM.
     */
    private function cleanupMembershipsOnDeletedAccounts(string $platformId): void
    {
        $pdo = $this->entityManager->getPDO();

        $stmt = $pdo->prepare("
            SELECT m.id
            FROM chatwoot_account_user_membership m
            INNER JOIN chatwoot_account a ON a.id = m.chatwoot_account_id
            WHERE a.platform_id = ?
              AND a.deleted = 1
              AND m.deleted = 0
        ");
        $stmt->execute([$platformId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($rows)) {
            return;
        }

        $count = 0;
        foreach ($rows as $membershipId) {
            $membership = $this->entityManager->getEntityById(
                'ChatwootAccountUserMembership',
                $membershipId
            );
            if ($membership) {
                $this->removeStaleMembership($membership);
                $count++;
            }
        }

        if ($count > 0) {
            $this->log->info(
                "SyncAccountMembersFromChatwoot: Removed {$count} dangling membership(s) on deleted accounts in platform {$platformId}"
            );
        }
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
