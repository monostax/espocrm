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

namespace Espo\Modules\Chatwoot\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use Espo\Modules\Chatwoot\Services\ChatwootAccountUserMembershipService;

/**
 * RebuildAction that backfills ChatwootAccountUserMembership records from existing
 * CRM data and creates the new inbox↔membership many-to-many links.
 *
 * Runs during `php rebuild`. Idempotent across repeated runs.
 *
 * Three sequential passes:
 *   Pass 1 — Backfill memberships from Agent→User links
 *   Pass 2 — Repair orphan agents (agent exists without user link)
 *   Pass 3 — Backfill inbox↔membership links using array_diff() reconciliation
 */
class BackfillAccountUserMemberships implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
        private ChatwootAccountUserMembershipService $membershipService
    ) {}

    public function process(): void
    {
        $this->log->info('BackfillAccountUserMemberships: Starting backfill');

        $this->pass1BackfillFromLinkedAgents();
        $this->pass2RepairOrphanAgents();
        $this->pass3BackfillInboxLinks();

        $this->log->info('BackfillAccountUserMemberships: Backfill complete');
    }

    /**
     * Pass 1 — Backfill memberships from agents that already have a user link.
     *
     * Queries all ChatwootAgent records where chatwootUserId IS NOT NULL AND
     * chatwootAccountId IS NOT NULL (ORM auto-filters soft-deleted; do NOT add
     * explicit deleted = false).
     */
    private function pass1BackfillFromLinkedAgents(): void
    {
        $agents = $this->entityManager
            ->getRDBRepository('ChatwootAgent')
            ->where([
                'chatwootUserId!=' => [null, ''],
                'chatwootAccountId!=' => null,
            ])
            ->find();

        $processed = 0;
        $errors = 0;

        foreach ($agents as $agent) {
            try {
                $accountId = $agent->get('chatwootAccountId');
                $userId = $agent->get('chatwootUserId');
                $role = $agent->get('role') ?? 'agent';
                $agentId = $agent->getId();

                $this->membershipService->upsertMembership(
                    $accountId,
                    $userId,
                    $role,
                    $agentId
                );

                $processed++;
            } catch (\Throwable $e) {
                $errors++;
                $this->log->warning(
                    "BackfillAccountUserMemberships: Pass 1 error for agent {$agent->getId()}: " .
                    $e->getMessage()
                );
            }
        }

        $this->log->info(
            "BackfillAccountUserMemberships: Pass 1 complete — processed={$processed} errors={$errors}"
        );
    }

    /**
     * Pass 2 — Repair orphan agents (agent exists without user link).
     *
     * For each orphan, resolves the account's platformId, then searches ChatwootUser
     * by email + platformId (same lookup as LinkToUser.php). If found, links the agent
     * and creates the membership.
     */
    private function pass2RepairOrphanAgents(): void
    {
        $agents = $this->entityManager
            ->getRDBRepository('ChatwootAgent')
            ->where([
                'OR' => [
                    ['chatwootUserId' => null],
                    ['chatwootUserId' => ''],
                ],
                'chatwootAccountId!=' => null,
            ])
            ->find();

        $linked = 0;
        $unresolvable = 0;
        $errors = 0;

        foreach ($agents as $agent) {
            try {
                $email = $agent->get('email');
                $accountId = $agent->get('chatwootAccountId');

                if (!$email || !$accountId) {
                    $unresolvable++;
                    continue;
                }

                $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);

                if (!$account) {
                    $unresolvable++;
                    continue;
                }

                $platformId = $account->get('platformId');

                if (!$platformId) {
                    $unresolvable++;
                    continue;
                }

                // Same lookup as LinkToUser.php
                $chatwootUser = $this->entityManager
                    ->getRDBRepository('ChatwootUser')
                    ->where([
                        'email' => $email,
                        'platformId' => $platformId,
                    ])
                    ->findOne();

                if (!$chatwootUser) {
                    $unresolvable++;
                    $this->log->debug(
                        "BackfillAccountUserMemberships: Pass 2 — unresolvable orphan agent {$agent->getId()} (email={$email})"
                    );
                    continue;
                }

                // Link agent to user
                $agent->set('chatwootUserId', $chatwootUser->getId());
                $agent->set('confirmed', true);
                $this->entityManager->saveEntity($agent, ['silent' => true]);

                // Create/update membership
                $this->membershipService->upsertMembership(
                    $accountId,
                    $chatwootUser->getId(),
                    $agent->get('role') ?? 'agent',
                    $agent->getId()
                );

                $linked++;
            } catch (\Throwable $e) {
                $errors++;
                $this->log->warning(
                    "BackfillAccountUserMemberships: Pass 2 error for agent {$agent->getId()}: " .
                    $e->getMessage()
                );
            }
        }

        $this->log->info(
            "BackfillAccountUserMemberships: Pass 2 complete — linked={$linked} unresolvable={$unresolvable} errors={$errors}"
        );
    }

    /**
     * Pass 3 — Retired in Phase 4: inbox↔agent relation removed from metadata.
     *
     * SyncInboxMembersFromChatwoot now writes directly to accountUserMemberships.
     * The next sync job run will establish correct inbox↔membership relations.
     */
    private function pass3BackfillInboxLinks(): void
    {
        // Pass 3 retired in Phase 4: inbox↔agent relation removed from metadata.
        // SyncInboxMembersFromChatwoot now writes directly to accountUserMemberships.
        // The next sync job run will establish correct inbox↔membership relations.
        $this->log->info('BackfillAccountUserMemberships: Pass 3 retired (Phase 4) — inbox membership links now managed by SyncInboxMembersFromChatwoot');
    }
}
