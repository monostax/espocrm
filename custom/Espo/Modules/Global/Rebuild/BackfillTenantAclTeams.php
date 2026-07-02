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
 * Backfill the polymorphic `entity_team` rows that mirror Tenant.baseUserTeam.
 *
 * EspoCRM's "team" ACL filter requires Tenant to expose a `teams` linkMultiple
 * link via the shared `entity_team` table. The SyncAclTeamsFromBaseUserTeam
 * hook keeps it in sync going forward; this rebuild action seeds it for
 * Tenants that already exist.
 *
 * Idempotent: only inserts missing rows; does not touch unrelated entries.
 */
class BackfillTenantAclTeams implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function process(): void
    {
        $this->log->info('Global Module: Starting Tenant ACL teams backfill...');

        $pdo = $this->entityManager->getPDO();

        $inserted = 0;
        $deleted = 0;
        $skipped = 0;

        $tenants = $this->entityManager
            ->getRDBRepository('Tenant')
            ->select(['id', 'baseUserTeamId'])
            ->sth()
            ->find();

        foreach ($tenants as $tenant) {
            $tenantId = $tenant->getId();
            $baseTeamId = $tenant->get('baseUserTeamId');

            try {
                // Soft-delete entity_team rows that don't match baseUserTeam.
                $stmt = $pdo->prepare(
                    "UPDATE entity_team
                     SET deleted = true
                     WHERE entity_type = 'Tenant'
                       AND entity_id = :entityId
                       AND deleted = false"
                    . ($baseTeamId ? " AND team_id <> :keepTeamId" : "")
                );
                $params = ['entityId' => $tenantId];
                if ($baseTeamId) {
                    $params['keepTeamId'] = $baseTeamId;
                }
                $stmt->execute($params);
                $deleted += $stmt->rowCount();

                if (!$baseTeamId) {
                    $skipped++;
                    continue;
                }

                // Restore a previously-deleted row, if any.
                $restoreStmt = $pdo->prepare(
                    "UPDATE entity_team
                     SET deleted = false
                     WHERE entity_type = 'Tenant'
                       AND entity_id = :entityId
                       AND team_id = :teamId
                       AND deleted = true"
                );
                $restoreStmt->execute([
                    'entityId' => $tenantId,
                    'teamId' => $baseTeamId,
                ]);

                if ($restoreStmt->rowCount() > 0) {
                    $inserted++;
                    continue;
                }

                // Check if a live row already exists.
                $checkStmt = $pdo->prepare(
                    "SELECT 1 FROM entity_team
                     WHERE entity_type = 'Tenant'
                       AND entity_id = :entityId
                       AND team_id = :teamId
                       AND deleted = false
                     LIMIT 1"
                );
                $checkStmt->execute([
                    'entityId' => $tenantId,
                    'teamId' => $baseTeamId,
                ]);

                if ($checkStmt->fetchColumn()) {
                    continue;
                }

                $insertStmt = $pdo->prepare(
                    "INSERT INTO entity_team (entity_id, entity_type, team_id, deleted)
                     VALUES (:entityId, 'Tenant', :teamId, false)"
                );
                $insertStmt->execute([
                    'entityId' => $tenantId,
                    'teamId' => $baseTeamId,
                ]);
                $inserted++;
            } catch (Throwable $e) {
                $this->log->error(
                    "Global Module: Failed to backfill ACL teams for Tenant '{$tenantId}': "
                    . $e->getMessage()
                );
                $skipped++;
            }
        }

        $this->log->info(
            "Global Module: Tenant ACL teams backfill complete. "
            . "Inserted/restored: {$inserted}, Removed stale: {$deleted}, Skipped: {$skipped}."
        );
    }
}
