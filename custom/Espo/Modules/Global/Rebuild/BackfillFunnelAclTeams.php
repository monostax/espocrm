<?php

declare(strict_types=1);

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
use PDO;
use Throwable;

/**
 * Seed `entity_team` for Funnel and OpportunityStage from the retired
 * Funnel.team ownership link.
 *
 * Funnel used to carry a singular required `team` link that drove its ACL, its
 * default-funnel uniqueness scope and its tenant derivation, while the `teams`
 * linkMultiple it also declared was required but never populated. Funnel is now
 * teams-only (matching Contact), so the historical team_id has to be migrated
 * into the polymorphic entity_team table or existing funnels become invisible.
 *
 * OpportunityStage rows are seeded from their parent funnel, since stage teams
 * mirror the funnel's (see Hooks/OpportunityStage/InheritFunnelTeams).
 *
 * Reads funnel.team_id with raw SQL because the field no longer exists in
 * entityDefs. A normal rebuild is RebuildMode::SOFT and never drops columns
 * (Utils\Database\Schema\DiffModifier: `$tableDiff->removedColumns = []`), so
 * the column is still present and readable at this point. Dropping it is left
 * as a deliberate manual step once this backfill has been confirmed.
 *
 * Must run before BackfillEntityTenants, which derives Funnel.tenantId from
 * `teams`.
 *
 * Idempotent: inserts only missing rows, restores soft-deleted ones, and never
 * removes teams that were added independently.
 */
class BackfillFunnelAclTeams implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function process(): void
    {
        $pdo = $this->entityManager->getPDO();

        if (!$this->columnExists($pdo)) {
            $this->log->info(
                'Global Module: Funnel ACL teams backfill skipped — funnel.team_id no longer exists.'
            );

            return;
        }

        $this->log->info('Global Module: Starting Funnel ACL teams backfill...');

        $funnelStmt = $pdo->query(
            "SELECT id, team_id FROM funnel
             WHERE deleted = false AND team_id IS NOT NULL"
        );

        if ($funnelStmt === false) {
            $this->log->error('Global Module: Funnel ACL teams backfill could not read funnel rows.');

            return;
        }

        $rows = $funnelStmt->fetchAll(PDO::FETCH_ASSOC);

        $funnels = 0;
        $stages = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $funnelId = (string) $row['id'];
            $teamId = (string) $row['team_id'];

            if ($teamId === '') {
                continue;
            }

            try {
                if ($this->ensureEntityTeam($pdo, 'Funnel', $funnelId, $teamId)) {
                    $funnels++;
                }

                $stages += $this->seedStages($pdo, $funnelId, $teamId);
            } catch (Throwable $e) {
                $this->log->error(
                    "Global Module: Failed to backfill ACL teams for Funnel '{$funnelId}': "
                    . $e->getMessage()
                );
                $skipped++;
            }
        }

        $orphans = $this->countTeamlessFunnels($pdo);

        if ($orphans > 0) {
            $this->log->warning(
                "Global Module: {$orphans} Funnel(s) have neither team_id nor teams; "
                . 'they will be inaccessible and cannot derive a tenant until teams are assigned.'
            );
        }

        $this->log->info(
            "Global Module: Funnel ACL teams backfill complete. "
            . "Funnels seeded: {$funnels}, Stages seeded: {$stages}, Skipped: {$skipped}."
        );
    }

    private function columnExists(PDO $pdo): bool
    {
        try {
            $pdo->query("SELECT team_id FROM funnel LIMIT 1");

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Seed every stage of the funnel with the funnel's team.
     */
    private function seedStages(PDO $pdo, string $funnelId, string $teamId): int
    {
        $stmt = $pdo->prepare(
            "SELECT id FROM opportunity_stage
             WHERE deleted = false AND funnel_id = :funnelId"
        );
        $stmt->execute(['funnelId' => $funnelId]);

        $count = 0;

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $stageId) {
            if ($this->ensureEntityTeam($pdo, 'OpportunityStage', (string) $stageId, $teamId)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Ensure one live entity_team row exists for the given triple.
     *
     * @return bool True when a row was inserted or restored.
     */
    private function ensureEntityTeam(PDO $pdo, string $entityType, string $entityId, string $teamId): bool
    {
        $check = $pdo->prepare(
            "SELECT 1 FROM entity_team
             WHERE entity_type = :entityType
               AND entity_id = :entityId
               AND team_id = :teamId
               AND deleted = false
             LIMIT 1"
        );
        $check->execute([
            'entityType' => $entityType,
            'entityId' => $entityId,
            'teamId' => $teamId,
        ]);

        if ($check->fetchColumn()) {
            return false;
        }

        // Restore a previously soft-deleted row rather than duplicating it.
        $restore = $pdo->prepare(
            "UPDATE entity_team
             SET deleted = false
             WHERE entity_type = :entityType
               AND entity_id = :entityId
               AND team_id = :teamId
               AND deleted = true"
        );
        $restore->execute([
            'entityType' => $entityType,
            'entityId' => $entityId,
            'teamId' => $teamId,
        ]);

        if ($restore->rowCount() > 0) {
            return true;
        }

        $insert = $pdo->prepare(
            "INSERT INTO entity_team (entity_id, entity_type, team_id, deleted)
             VALUES (:entityId, :entityType, :teamId, false)"
        );
        $insert->execute([
            'entityId' => $entityId,
            'entityType' => $entityType,
            'teamId' => $teamId,
        ]);

        return true;
    }

    /**
     * Funnels that end up with no teams at all — these need manual attention.
     */
    private function countTeamlessFunnels(PDO $pdo): int
    {
        $sql =
            "SELECT COUNT(*) FROM funnel f
             WHERE f.deleted = false
               AND NOT EXISTS (
                   SELECT 1 FROM entity_team et
                   WHERE et.entity_type = 'Funnel'
                     AND et.entity_id = f.id
                     AND et.deleted = false
               )";

        $stmt = $pdo->query($sql);

        if ($stmt === false) {
            return 0;
        }

        return (int) $stmt->fetchColumn();
    }
}
