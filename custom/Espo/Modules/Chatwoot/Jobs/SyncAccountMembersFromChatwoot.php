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
 * Runs every 5 minutes (Decision #7).
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

            // Decision #12: falsy guard to catch both null and '' (empty string)
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

            // --- Build remote-truth key set FIRST (Decision #10) ---
            $remoteUserIds = [];
            foreach ($remoteAccountUsers as $remoteAccountUser) {
                $remoteUserIds[] = (int) $remoteAccountUser['user_id'];
            }

            // --- Empty-response guard ---
            $localMemberships = $this->entityManager
                ->getRDBRepository('ChatwootAccountUserMembership')
                ->where(['chatwootAccountId' => $espoAccountId])
                ->find();

            $localMembershipList = iterator_to_array($localMemberships);

            if (empty($remoteAccountUsers) && !empty($localMembershipList)) {
                $this->log->warning(
                    "SyncAccountMembersFromChatwoot: Account {$accountName} - " .
                    'remote account_users list is empty but local memberships exist. ' .
                    'Skipping stale-marking to protect against API errors.'
                );
                return;
            }

            // --- Process each remote account_user ---
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

                // Optionally resolve ChatwootAgent to get agentId
                $agentId = null;
                $agent = $this->entityManager
                    ->getRDBRepository('ChatwootAgent')
                    ->where([
                        'chatwootAccountId' => $espoAccountId,
                        'chatwootUserId' => $localUser->getId(),
                    ])
                    ->findOne();

                if ($agent) {
                    $agentId = $agent->getId();
                }

                // Upsert membership with 5 params (Decision #8)
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
                    $agentId,
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

            // --- Stale detection + removal (Decision #10 redesigned) ---
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

            $this->log->debug(
                "SyncAccountMembersFromChatwoot: Account {$accountName} - " .
                "created={$stats['created']}, updated={$stats['updated']}, " .
                "skipped={$stats['skipped']}, stale={$stats['stale']}"
            );

        } catch (\Exception $e) {
            $this->log->error(
                "SyncAccountMembersFromChatwoot: Sync failed for account {$accountName}: " .
                $e->getMessage()
            );
        }
    }

    /**
     * Remove a stale membership and its linked agent.
     *
     * If the underlying ChatwootUser has no remaining memberships after
     * removal, the user record is deleted too (platform user was deleted).
     */
    private function removeStaleMembership(Entity $membership): void
    {
        $membershipId = $membership->getId();
        $chatwootUserId = $membership->get('chatwootUserId');
        $agentId = $membership->get('chatwootAgentId');

        // Remove linked agent first (if any)
        if ($agentId) {
            $agent = $this->entityManager->getEntityById('ChatwootAgent', $agentId);

            if ($agent) {
                try {
                    $this->entityManager->removeEntity($agent);
                    $this->log->info(
                        "SyncAccountMembersFromChatwoot: Removed stale agent {$agentId} " .
                        "linked to membership {$membershipId}"
                    );
                } catch (\Exception $e) {
                    $this->log->error(
                        "SyncAccountMembersFromChatwoot: Failed to remove agent {$agentId}: " .
                        $e->getMessage()
                    );
                }
            }
        }

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
}
