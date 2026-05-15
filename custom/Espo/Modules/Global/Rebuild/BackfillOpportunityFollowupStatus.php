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
 * Backfills Opportunity.followupStatus = 'Ended' for opportunities that were
 * already closed (status IN ('Won', 'Lost')) before the followupStatus field
 * was introduced.
 *
 * The new field declares default = 'ActionNeeded', which is semantically
 * wrong for historical closed opportunities. The AutoSetFollowupStatusOnClose
 * hook only fires on future status transitions, so a one-shot backfill is
 * required to normalise existing data.
 *
 * Idempotent: only touches rows where followup_status != 'Ended' AND
 * status IN ('Won', 'Lost').
 */
class BackfillOpportunityFollowupStatus implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function process(): void
    {
        $this->log->info('Global Module: Starting Opportunity.followupStatus backfill...');

        $pdo = $this->entityManager->getPDO();

        try {
            $countStmt = $pdo->query(
                "SELECT COUNT(*) FROM `opportunity` "
                . "WHERE `status` IN ('Won', 'Lost') "
                . "AND (`followup_status` IS NULL OR `followup_status` != 'Ended') "
                . "AND `deleted` = 0"
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
            $this->log->info('Global Module: No Opportunities need followupStatus backfill.');

            return;
        }

        $this->log->info("Global Module: {$remaining} Opportunity(ies) need followupStatus backfill.");

        try {
            // Raw UPDATE to skip hooks — this is pure data migration, not a
            // user-driven change. The AutoSetFollowupStatusOnClose hook would
            // produce the same result, but firing it per-row is wasteful here.
            $stmt = $pdo->prepare(
                "UPDATE `opportunity` "
                . "SET `followup_status` = 'Ended' "
                . "WHERE `status` IN ('Won', 'Lost') "
                . "AND (`followup_status` IS NULL OR `followup_status` != 'Ended') "
                . "AND `deleted` = 0"
            );
            $stmt->execute();

            $updated = $stmt->rowCount();

            $this->log->info(
                "Global Module: Opportunity.followupStatus backfill complete. Updated: {$updated}."
            );
        } catch (Throwable $e) {
            $this->log->error(
                'Global Module: Opportunity.followupStatus backfill failed: ' . $e->getMessage()
            );
        }
    }
}
