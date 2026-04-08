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

            // --- Safety check: refuse to treat an empty API response as "delete all" ---
            $existingMembershipCount = $this->entityManager
                ->getRDBRepository('ChatwootAccountUserMembership')
                ->where(['chatwootAccountId' => $espoAccountId])
                ->count();

            if (count($remoteAccountUsers) === 0 && $existingMembershipCount > 0) {
                $this->log->warning(
                    "SyncAccountMembersFromChatwoot: API returned 0 account_users but " .
                    "{$existingMembershipCount} local memberships exist for account {$accountName}. " .
                    "Skipping destructive cleanup to prevent data loss."
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
            // SAFETY: We pass ['skipChatwootSync' => true] to removeEntity() so the
            // DeleteFromChatwoot hook does NOT call back to Chatwoot. The membership is
            // already gone on the Chatwoot side — calling back would be pointless and
            // could cause cascading 401 failures if the API token was invalidated.
            $this->removeStaleMembers($espoAccountId, $platformId, $remoteUserIds);

            $this->log->debug(
                "SyncAccountMembersFromChatwoot: Account {$accountName} - " .
                "created={$stats['created']}, updated={$stats['updated']}, " .
                "skipped={$stats['skipped']}, stale={$stats['stale']}"
            );

        } catch (\Exception $e) {
            $message = $e->getMessage();

            // SAFETY: Never perform destructive cleanup from a sync job error handler.
            // A 404 during a Chatwoot redeployment is transient — the ingress returns 404
            // while the new pod starts, but the Platform API may already be reachable,
            // causing isConfirmedAccountGone() to produce false positives.
            // If an account is truly deleted from Chatwoot, an admin should clean it up
            // manually via the CRM UI.
            if ($this->isAccountGoneError($message)) {
                $this->log->warning(
                    "SyncAccountMembersFromChatwoot: Account {$accountName} returned 404. " .
                    "This may be a transient error during Chatwoot redeployment. " .
                    "No destructive cleanup will be performed automatically."
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
     * Remove memberships whose Chatwoot user is no longer in the remote response.
     *
     * Uses ['skipChatwootSync' => true] so the DeleteFromChatwoot hook does NOT
     * call back to Chatwoot — the membership is already gone on their side.
     *
     * @param array<int> $remoteUserIds Chatwoot user IDs seen in the current sync
     */
    private function removeStaleMembers(string $espoAccountId, string $platformId, array $remoteUserIds): void
    {
        $allLocalMemberships = $this->entityManager
            ->getRDBRepository('ChatwootAccountUserMembership')
            ->where(['chatwootAccountId' => $espoAccountId])
            ->find();

        foreach ($allLocalMemberships as $membership) {
            $userId = $membership->get('chatwootUserId');
            if (!$userId) {
                continue;
            }

            $localUser = $this->entityManager->getEntityById('ChatwootUser', $userId);
            if (!$localUser) {
                continue;
            }

            $chatwootUserId = $localUser->get('chatwootUserId');
            if (!$chatwootUserId) {
                continue;
            }

            if (!in_array($chatwootUserId, $remoteUserIds, true)) {
                $membershipName = $membership->get('name');

                try {
                    $this->entityManager->removeEntity($membership, ['skipChatwootSync' => true]);
                    $this->log->info(
                        "SyncAccountMembersFromChatwoot: Removed stale membership '{$membershipName}' " .
                        "(chatwootUserId={$chatwootUserId} no longer in remote response)"
                    );
                } catch (\Exception $e) {
                    $this->log->error(
                        "SyncAccountMembersFromChatwoot: Failed to remove membership {$membership->getId()}: " .
                        $e->getMessage()
                    );
                }
            }
        }
    }

    /**
     * Check if the error message indicates the Chatwoot account is gone (404).
     */
    private function isAccountGoneError(string $message): bool
    {
        return (bool) preg_match('/HTTP\s+404\b/', $message);
    }

}
