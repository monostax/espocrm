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
 * Backfills Opportunity.closeDate for Won/Lost rows from FeatureTrackingEvent.
 *
 * Source of truth (only):
 *   tracking_event.code = opportunity_won  → status/probability Won
 *   tracking_event.code = opportunity_lost → status/probability Lost
 *   closeDate = DATE of MIN(occurred_at) for that parent Opportunity.
 *
 * Leaves closeDate NULL when no matching tracking event exists
 * (no modifiedAt / createdAt fallback).
 *
 * Idempotent: only rows with close_date IS NULL + matching event.
 * Raw SQL skips hooks (data migration only).
 */
class BackfillOpportunityCloseDate implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function process(): void
    {
        $this->log->info('Global Module: Starting Opportunity.closeDate backfill from TrackingEvent...');

        $pdo = $this->entityManager->getPDO();

        try {
            $countStmt = $pdo->query(
                "SELECT COUNT(*) FROM opportunity o
                 INNER JOIN (
                     SELECT parent_id, code, MIN(occurred_at) AS first_at
                     FROM tracking_event
                     WHERE deleted = 0
                       AND parent_type = 'Opportunity'
                       AND code IN ('opportunity_won', 'opportunity_lost')
                       AND parent_id IS NOT NULL
                       AND occurred_at IS NOT NULL
                     GROUP BY parent_id, code
                 ) te ON te.parent_id = o.id
                 WHERE o.deleted = 0
                   AND o.close_date IS NULL
                   AND (
                       (te.code = 'opportunity_won'
                           AND (o.status = 'Won' OR o.probability = 100))
                       OR
                       (te.code = 'opportunity_lost'
                           AND (o.status = 'Lost' OR o.probability = 0))
                   )"
            );
            $remaining = (int) $countStmt->fetchColumn();
        } catch (Throwable $e) {
            $this->log->info(
                'Global Module: Opportunity.closeDate backfill skipped (schema not ready): '
                . $e->getMessage()
            );

            return;
        }

        if ($remaining === 0) {
            $this->log->info(
                'Global Module: No Opportunities need closeDate backfill from TrackingEvent.'
            );

            return;
        }

        $this->log->info(
            "Global Module: {$remaining} Opportunity(ies) with TrackingEvent but null closeDate."
        );

        try {
            // DATE(occurred_at): stores the calendar day of the won/lost event
            // in DB session timezone (Espo system datetime). No modifiedAt fallback.
            $stmt = $pdo->prepare(
                "UPDATE opportunity o
                 INNER JOIN (
                     SELECT parent_id, code, MIN(occurred_at) AS first_at
                     FROM tracking_event
                     WHERE deleted = 0
                       AND parent_type = 'Opportunity'
                       AND code IN ('opportunity_won', 'opportunity_lost')
                       AND parent_id IS NOT NULL
                       AND occurred_at IS NOT NULL
                     GROUP BY parent_id, code
                 ) te ON te.parent_id = o.id
                 SET o.close_date = DATE(te.first_at)
                 WHERE o.deleted = 0
                   AND o.close_date IS NULL
                   AND (
                       (te.code = 'opportunity_won'
                           AND (o.status = 'Won' OR o.probability = 100))
                       OR
                       (te.code = 'opportunity_lost'
                           AND (o.status = 'Lost' OR o.probability = 0))
                   )"
            );
            $stmt->execute();
            $updated = $stmt->rowCount();

            $this->log->info(
                "Global Module: Opportunity.closeDate backfill complete. Updated: {$updated}."
            );
        } catch (Throwable $e) {
            $this->log->error(
                'Global Module: Opportunity.closeDate backfill failed: ' . $e->getMessage()
            );
        }
    }
}
