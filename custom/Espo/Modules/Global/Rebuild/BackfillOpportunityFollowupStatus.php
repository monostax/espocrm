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
 * Normalizes closed (Won/Lost) opportunities to the binary timer-driven
 * follow-up model: clears `followup_timer = NULL` and derives
 * `followup_status = 'FollowupInactive'` while preserving `followup_count`.
 *
 * Idempotent: only touches non-deleted Won/Lost rows where `followup_timer`
 * is non-null or `followup_status` is null / not `'FollowupInactive'`.
 */
class BackfillOpportunityFollowupStatus implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function process(): void
    {
        $this->log->info('Global Module: Starting Opportunity.followupStatus closed-deal normalization...');

        $pdo = $this->entityManager->getPDO();

        try {
            $countStmt = $pdo->query(
                "SELECT COUNT(*) FROM opportunity "
                . "WHERE status IN ('Won', 'Lost') "
                . "AND (followup_timer IS NOT NULL OR followup_status IS NULL OR followup_status != 'FollowupInactive') "
                . "AND deleted = false"
            );
            $remaining = (int) $countStmt->fetchColumn();
        } catch (Throwable $e) {
            // Column may not yet exist on a fresh install before rebuild applies
            // the schema diff. Skip silently — there is nothing to backfill.
            $this->log->info(
                'Global Module: Opportunity.followup_status column not ready; skipping backfill. '
                . $e->getMessage()
            );

            return;
        }

        if ($remaining === 0) {
            $this->log->info('Global Module: No closed Opportunities need followupStatus normalization.');

            return;
        }

        $this->log->info("Global Module: {$remaining} closed Opportunity(ies) need followupStatus normalization.");

        try {
            // Raw UPDATE to skip hooks — pure data migration. Preserves followup_count.
            $stmt = $pdo->prepare(
                "UPDATE opportunity "
                . "SET followup_timer = NULL, "
                . "    followup_status = 'FollowupInactive' "
                . "WHERE status IN ('Won', 'Lost') "
                . "AND (followup_timer IS NOT NULL OR followup_status IS NULL OR followup_status != 'FollowupInactive') "
                . "AND deleted = false"
            );
            $stmt->execute();

            $updated = $stmt->rowCount();

            $this->log->info(
                "Global Module: Opportunity.followupStatus closed-deal normalization complete. Updated: {$updated}."
            );
        } catch (Throwable $e) {
            $this->log->error(
                'Global Module: Opportunity.followupStatus closed-deal normalization failed: ' . $e->getMessage()
            );
        }
    }
}
