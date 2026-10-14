<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Global\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Binary timer-driven follow-up state normalization.
 *
 * Idempotently normalizes all non-deleted opportunities so `followup_status`
 * is strictly derived from `followup_timer` nullability while preserving
 * `followup_count`:
 *
 *   1. Clear timers on closed Won/Lost opportunities and set `FollowupInactive`.
 *   2. With `followup_timer IS NULL`, derive `FollowupInactive` (including legacy
 *      `ActionNeeded`, `Ended`, `WaitingReply`, `FollowupScheduled`, and NULL).
 *   3. With `followup_timer IS NOT NULL`, derive `FollowupActive`.
 *   4. Initialize `followup_lifetime_count` to `COALESCE(followup_count, 0)`
 *      when NULL or smaller than `followup_count`. (Durable per-opportunity
 *      send records across prior reset cycles do not exist — `chatwoot_ai_agent_run`
 *      only links `opportunity_id` on `opportunity-mention` runs and
 *      `sent_followup_message` is a tool-invocation boolean — so lifetime
 *      tracking starts from the introduction of `followup_lifetime_count`,
 *      seeded with any surviving current-cycle count.)
 */
class BackfillOpportunityFollowupActiveCollapse implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function process(): void
    {
        $this->log->info('Global Module: Starting Opportunity binary follow-up state normalization...');

        $pdo = $this->entityManager->getPDO();

        try {
            $countStmt = $pdo->query(
                "SELECT COUNT(*) FROM opportunity "
                . "WHERE deleted = false AND ("
                . "  (status IN ('Won', 'Lost') AND (followup_timer IS NOT NULL OR followup_status IS NULL OR followup_status != 'FollowupInactive')) "
                . "  OR (followup_timer IS NULL AND (followup_status IS NULL OR followup_status != 'FollowupInactive')) "
                . "  OR (status NOT IN ('Won', 'Lost') AND followup_timer IS NOT NULL AND (followup_status IS NULL OR followup_status != 'FollowupActive'))"
                . ")"
            );
            $remaining = (int) $countStmt->fetchColumn();
        } catch (Throwable $e) {
            $this->log->info(
                'Global Module: Opportunity follow-up columns not ready for binary normalization; skipping. '
                . $e->getMessage()
            );

            return;
        }

        if ($remaining > 0) {
            $this->log->info(
                "Global Module: {$remaining} Opportunity(ies) need binary follow-up state normalization."
            );

            try {
                $closedStmt = $pdo->prepare(
                    "UPDATE opportunity "
                    . "SET followup_timer = NULL, "
                    . "    followup_status = 'FollowupInactive' "
                    . "WHERE status IN ('Won', 'Lost') "
                    . "AND (followup_timer IS NOT NULL OR followup_status IS NULL OR followup_status != 'FollowupInactive') "
                    . "AND deleted = false"
                );
                $closedStmt->execute();
                $closedUpdated = $closedStmt->rowCount();

                $inactiveStmt = $pdo->prepare(
                    "UPDATE opportunity "
                    . "SET followup_status = 'FollowupInactive' "
                    . "WHERE followup_timer IS NULL "
                    . "AND (followup_status IS NULL OR followup_status != 'FollowupInactive') "
                    . "AND deleted = false"
                );
                $inactiveStmt->execute();
                $inactiveUpdated = $inactiveStmt->rowCount();

                $activeStmt = $pdo->prepare(
                    "UPDATE opportunity "
                    . "SET followup_status = 'FollowupActive' "
                    . "WHERE followup_timer IS NOT NULL "
                    . "AND (followup_status IS NULL OR followup_status != 'FollowupActive') "
                    . "AND deleted = false"
                );
                $activeStmt->execute();
                $activeUpdated = $activeStmt->rowCount();

                $this->log->info(
                    "Global Module: Opportunity binary follow-up state normalization complete. "
                    . "Closed cleared: {$closedUpdated}, Inactive derived: {$inactiveUpdated}, Active derived: {$activeUpdated}."
                );
            } catch (Throwable $e) {
                $this->log->error(
                    'Global Module: Opportunity binary follow-up state normalization failed: '
                    . $e->getMessage()
                );
            }
        } else {
            $this->log->info(
                'Global Module: No Opportunities need binary follow-up state normalization.'
            );
        }

        try {
            $lifetimeStmt = $pdo->prepare(
                "UPDATE opportunity "
                . "SET followup_lifetime_count = COALESCE(followup_count, 0) "
                . "WHERE (followup_lifetime_count IS NULL OR followup_lifetime_count < COALESCE(followup_count, 0)) "
                . "AND deleted = false"
            );
            $lifetimeStmt->execute();
        } catch (Throwable $e) {
            $this->log->info(
                'Global Module: Opportunity.followup_lifetime_count column not ready; skipping initialization. '
                . $e->getMessage()
            );
        }
    }
}
