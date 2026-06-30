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
use Espo\Modules\Chatwoot\Services\ChatwootAccountUserMembershipService;

/**
 * Scheduled job for ongoing invariant enforcement of ChatwootAccountUserMembership.
 * Runs after the initial BackfillAccountUserMemberships rebuild action has seeded data.
 *
 * Two checks per enabled account:
 *   Check 3 — Inbox↔membership FK integrity
 *   Check 4 — Orphaned memberships (log-only, no auto-delete)
 */
class RepairAccountUserMembershipInvariants implements JobDataLess
{
    /**
     * Safety ceiling for Check 4 auto-deletion. If the share of orphaned
     * memberships in an account exceeds this ratio, deletion is skipped and a
     * warning is logged instead. Protects against a transient state where many
     * ChatwootUser rows are temporarily unreadable from wiping a whole account.
     */
    private const ORPHAN_REMOVAL_MAX_RATIO = 0.5;

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
        private ChatwootAccountUserMembershipService $membershipService
    ) {}

    public function run(): void
    {
        $this->log->debug('RepairAccountUserMembershipInvariants: Job started');

        try {
            $accounts = $this->getEnabledAccounts();
            $accountList = iterator_to_array($accounts);
            $accountCount = count($accountList);

            $this->log->debug(
                "RepairAccountUserMembershipInvariants: Found {$accountCount} account(s) to check"
            );

            foreach ($accountList as $account) {
                $this->repairAccount($account);
            }

            $this->log->debug(
                "RepairAccountUserMembershipInvariants: Job completed — processed {$accountCount} account(s)"
            );
        } catch (\Throwable $e) {
            $this->log->error(
                'RepairAccountUserMembershipInvariants: Job failed — ' .
                $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine()
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
     * Run all repair checks for a single account.
     */
    private function repairAccount(Entity $account): void
    {
        $accountName = $account->get('name');
        $accountId = $account->getId();

        try {
            $this->check3InboxMembershipDrift($accountId);
            $this->check4OrphanedMemberships($accountId);
        } catch (\Throwable $e) {
            $this->log->error(
                "RepairAccountUserMembershipInvariants: Failed for account {$accountName}: " .
                $e->getMessage()
            );
        }
    }

    /**
     * Check 3 — Membership FK integrity on inbox-linked memberships.
     *
     * For each inbox in the account, loads accountUserMemberships and verifies:
     *   - chatwootUserId references an existing ChatwootUser entity
     *   - chatwootAccountId references an existing ChatwootAccount entity
     *
     * No add/remove reconciliation — that is solely owned by SyncInboxMembersFromChatwoot.
     */
    private function check3InboxMembershipDrift(string $accountId): void
    {
        $inboxes = $this->entityManager
            ->getRDBRepository('ChatwootInbox')
            ->where(['chatwootAccountId' => $accountId])
            ->find();

        $orphanedUsers = 0;
        $orphanedAccounts = 0;

        foreach ($inboxes as $inbox) {
            try {
                $memberships = $this->entityManager
                    ->getRDBRepository('ChatwootInbox')
                    ->getRelation($inbox, 'accountUserMemberships')
                    ->find();

                foreach ($memberships as $membership) {
                    // Verify chatwootUserId references an existing ChatwootUser
                    $userId = $membership->get('chatwootUserId');

                    if ($userId) {
                        $user = $this->entityManager->getEntityById('ChatwootUser', $userId);

                        if (!$user) {
                            $orphanedUsers++;
                            $this->log->warning(
                                "RepairAccountUserMembershipInvariants: Check 3 — membership {$membership->getId()} " .
                                "on inbox {$inbox->getId()} references non-existent ChatwootUser {$userId}"
                            );
                        }
                    }

                    // Verify chatwootAccountId references an existing ChatwootAccount
                    $mAccountId = $membership->get('chatwootAccountId');

                    if ($mAccountId) {
                        $account = $this->entityManager->getEntityById('ChatwootAccount', $mAccountId);

                        if (!$account) {
                            $orphanedAccounts++;
                            $this->log->warning(
                                "RepairAccountUserMembershipInvariants: Check 3 — membership {$membership->getId()} " .
                                "on inbox {$inbox->getId()} references non-existent ChatwootAccount {$mAccountId}"
                            );
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->log->warning(
                    "RepairAccountUserMembershipInvariants: Check 3 error for inbox {$inbox->getId()}: " .
                    $e->getMessage()
                );
            }
        }

        if ($orphanedUsers > 0 || $orphanedAccounts > 0) {
            $this->log->info(
                "RepairAccountUserMembershipInvariants: Check 3 — " .
                "orphanedUsers={$orphanedUsers} orphanedAccounts={$orphanedAccounts} for account {$accountId}"
            );
        }
    }

    /**
     * Check 4 — Orphaned memberships (auto-delete, threshold-guarded).
     *
     * A membership whose linked ChatwootUser no longer exists is an orphan:
     * its `chatwootUser` link is `required: true`, so it can never be enriched
     * or matched against the authoritative Platform API user list. Such records
     * surface in the UI as bogus "Unknown" agents. The
     * SyncAccountUserMembershipsFromChatwoot stale-removal pass deliberately
     * skips them (it can't resolve the user to compare against the remote list),
     * so they would otherwise persist forever.
     *
     * This check removes them, guarded by ORPHAN_REMOVAL_MAX_RATIO so a
     * transient state where many ChatwootUser rows are momentarily unreadable
     * cannot wipe an entire account. Deletion uses ['skipChatwootSync' => true]
     * since there is no resolvable remote agent to call back to.
     */
    private function check4OrphanedMemberships(string $accountId): void
    {
        $memberships = $this->entityManager
            ->getRDBRepository('ChatwootAccountUserMembership')
            ->where(['chatwootAccountId' => $accountId])
            ->find();

        $all = iterator_to_array($memberships);
        $totalLocal = count($all);

        if ($totalLocal === 0) {
            return;
        }

        // ── First pass: identify orphan candidates ──
        $orphans = [];

        foreach ($all as $membership) {
            try {
                $userId = $membership->get('chatwootUserId');

                // No user id at all is a different (and rarer) invariant break;
                // treat it as an orphan candidate too since it can't be valid.
                if (!$userId) {
                    $orphans[] = $membership;
                    continue;
                }

                $user = $this->entityManager->getEntityById('ChatwootUser', $userId);

                if (!$user) {
                    $orphans[] = $membership;
                }
            } catch (\Throwable $e) {
                $this->log->warning(
                    "RepairAccountUserMembershipInvariants: Check 4 error for membership {$membership->getId()}: " .
                    $e->getMessage()
                );
            }
        }

        if (empty($orphans)) {
            return;
        }

        // ── Suspicious-drop threshold guard ──
        $orphanCount = count($orphans);
        $dropRatio = $orphanCount / $totalLocal;

        if ($dropRatio > self::ORPHAN_REMOVAL_MAX_RATIO) {
            $pct = round($dropRatio * 100);
            $this->log->warning(
                "RepairAccountUserMembershipInvariants: Check 4 — orphan removal BLOCKED for account {$accountId} — " .
                "{$orphanCount}/{$totalLocal} ({$pct}%) memberships are orphaned, exceeding the " .
                round(self::ORPHAN_REMOVAL_MAX_RATIO * 100) . "% safety threshold. " .
                "This likely indicates a transient ChatwootUser read failure. Skipping deletion."
            );
            return;
        }

        // ── Second pass: delete confirmed orphans ──
        $deleted = 0;

        foreach ($orphans as $membership) {
            $membershipName = $membership->get('name');
            $userId = $membership->get('chatwootUserId');

            try {
                $this->entityManager->removeEntity($membership, ['skipChatwootSync' => true]);
                $deleted++;
                $this->log->info(
                    "RepairAccountUserMembershipInvariants: Check 4 — removed orphaned membership " .
                    "'{$membershipName}' ({$membership->getId()}; linked ChatwootUser '{$userId}' no longer exists)"
                );
            } catch (\Throwable $e) {
                $this->log->error(
                    "RepairAccountUserMembershipInvariants: Check 4 — failed to remove orphaned membership " .
                    "{$membership->getId()}: " . $e->getMessage()
                );
            }
        }

        if ($deleted > 0) {
            $this->log->info(
                "RepairAccountUserMembershipInvariants: Check 4 — removed {$deleted} orphaned membership(s) for account {$accountId}"
            );
        }
    }
}
