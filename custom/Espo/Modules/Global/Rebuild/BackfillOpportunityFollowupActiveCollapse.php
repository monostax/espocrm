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
 * FollowupActive collapse backfill.
 *
 * Collapses the legacy two-status active set (`WaitingReply`,
 * `FollowupScheduled`) onto the single `FollowupActive` value introduced by
 * the Phase 10 follow-up status collapse (see plan-followup-active-collapse.md).
 *
 * Pre-conditions:
 *   • Phase 0 has widened the `followup_status` enum/varchar column to the
 *     5-value union so `'FollowupActive'` is a writable value.
 *   • Phase B has added the `followup_ai_agent_id` FK column. This backfill
 *     does NOT touch that column — rows stay NULL until the next AI write
 *     populates the provenance (goal §1).
 *
 * The UPDATE is raw (skips the Espo Hook layer) because this is pure data
 * migration, not a user-driven change. The Phase A tolerant reads keep the
 * scheduler functioning before, during, and after this UPDATE.
 *
 * Idempotent: a second invocation finds zero rows in the legacy active set
 * and short-circuits. Phase E reruns this action inside its maintenance
 * window to mop up any drift caused by operators flipping rows back to the
 * legacy values via the still-wide UI dropdown between Phase C and Phase E.
 */
class BackfillOpportunityFollowupActiveCollapse implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function process(): void
    {
        $this->log->info('Global Module: Starting Opportunity FollowupActive collapse backfill...');

        $pdo = $this->entityManager->getPDO();

        try {
            $countStmt = $pdo->query(
                "SELECT COUNT(*) FROM `opportunity` "
                . "WHERE `followup_status` IN ('WaitingReply', 'FollowupScheduled') "
                . "AND `deleted` = 0"
            );
            $remaining = (int) $countStmt->fetchColumn();
        } catch (Throwable $e) {
            // Column may not yet exist on a fresh install before rebuild
            // applies the schema diff, or may not yet permit 'FollowupActive'
            // (Phase 0 not applied). Skip silently — there is nothing safe
            // to backfill until those preconditions hold.
            $this->log->info(
                'Global Module: Opportunity.followup_status column not ready for '
                . 'FollowupActive collapse; skipping backfill. ' . $e->getMessage()
            );

            return;
        }

        if ($remaining === 0) {
            $this->log->info(
                'Global Module: No Opportunities need FollowupActive collapse backfill.'
            );

            return;
        }

        $this->log->info(
            "Global Module: {$remaining} Opportunity(ies) need FollowupActive collapse backfill."
        );

        try {
            // Raw UPDATE to skip hooks — pure data migration. The Phase A
            // tolerant reads ensure the scheduler continues to recognise
            // these rows as "active" both before and after the collapse.
            // `followup_ai_agent_id` is intentionally NOT touched here: rows
            // stay NULL until the next AI-driven write populates provenance.
            $stmt = $pdo->prepare(
                "UPDATE `opportunity` "
                . "SET `followup_status` = 'FollowupActive' "
                . "WHERE `followup_status` IN ('WaitingReply', 'FollowupScheduled') "
                . "AND `deleted` = 0"
            );
            $stmt->execute();

            $updated = $stmt->rowCount();

            $this->log->info(
                "Global Module: Opportunity FollowupActive collapse backfill complete. "
                . "Updated: {$updated}."
            );
        } catch (Throwable $e) {
            $this->log->error(
                'Global Module: Opportunity FollowupActive collapse backfill failed: '
                . $e->getMessage()
            );
        }
    }
}
