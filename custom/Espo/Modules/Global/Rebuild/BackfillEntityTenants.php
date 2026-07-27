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

use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Backfill tenantId on Account / Lead / Opportunity / Funnel from team membership.
 *
 * Mirrors BackfillContactTenant. Idempotent: only rows with null tenantId.
 * Funnel prefers the singular team field, then teams linkMultiple.
 *
 * Uses raw SQL UPDATE to avoid firing business hooks during migration.
 * DB-agnostic literals (no backticks; deleted = false for Postgres/MySQL).
 */
class BackfillEntityTenants implements RebuildAction
{
    private const BATCH_SIZE = 500;

    /**
     * entityType => [tableName, preferSingularTeamField?]
     *
     * @var array<string, array{0: string, 1: bool}>
     */
    private const ENTITIES = [
        'Account' => ['account', false],
        'Lead' => ['lead', false],
        'Opportunity' => ['opportunity', false],
        'Funnel' => ['funnel', true],
    ];

    public function __construct(
        private EntityManager $entityManager,
        private TenantResolver $tenantResolver,
        private Log $log,
    ) {}

    public function process(): void
    {
        foreach (self::ENTITIES as $entityType => [$table, $preferSingularTeam]) {
            $this->backfillEntityType($entityType, $table, $preferSingularTeam);
        }
    }

    private function backfillEntityType(
        string $entityType,
        string $table,
        bool $preferSingularTeam,
    ): void {
        $this->log->info("Global Module: Starting {$entityType}.tenantId backfill...");

        $pdo = $this->entityManager->getPDO();

        $countStmt = $pdo->query(
            "SELECT COUNT(*) FROM {$table} WHERE tenant_id IS NULL AND deleted = false"
        );
        $remaining = (int) $countStmt->fetchColumn();

        if ($remaining === 0) {
            $this->log->info("Global Module: No {$entityType}s need tenantId backfill.");

            return;
        }

        $this->log->info("Global Module: {$remaining} {$entityType}(s) need tenantId backfill.");

        $updated = 0;
        $skipped = 0;

        $collection = $this->entityManager
            ->getRDBRepository($entityType)
            ->where(['tenantId' => null])
            ->sth()
            ->find();

        foreach ($collection as $entity) {
            $teamIds = [];

            if ($preferSingularTeam) {
                $teamId = $entity->get('teamId');

                if (is_string($teamId) && $teamId !== '') {
                    $teamIds[] = $teamId;
                }
            }

            if ($entity instanceof CoreEntity && $entity->hasLinkMultipleField('teams')) {
                foreach ($entity->getLinkMultipleIdList('teams') as $teamId) {
                    if (is_string($teamId) && $teamId !== '' && !in_array($teamId, $teamIds, true)) {
                        $teamIds[] = $teamId;
                    }
                }
            }

            if ($teamIds === []) {
                $this->log->warning(
                    "Global Module: {$entityType} '{$entity->getId()}' has no teams; cannot derive tenantId."
                );
                $skipped++;

                continue;
            }

            $tenantIds = $this->tenantResolver->resolveAllFromTeamIds($teamIds);

            if (count($tenantIds) > 1) {
                // Skip rather than guess: a backfill that stamps an arbitrary
                // one of several tenants writes an unrecoverable wrong answer
                // across the tenancy boundary. Leaving tenant_id NULL is
                // fail-closed on read paths and stays visible for repair.
                $this->log->warning(
                    "Global Module: {$entityType} '{$entity->getId()}' — teams span multiple Tenants ("
                    . implode(', ', $tenantIds) . '); refusing to guess. Fix the team assignment.'
                );
                $skipped++;

                continue;
            }

            $tenantId = $tenantIds[0] ?? null;

            if (!$tenantId) {
                $this->log->warning(
                    "Global Module: {$entityType} '{$entity->getId()}' — none of its teams resolve to a Tenant."
                );
                $skipped++;

                continue;
            }

            try {
                $stmt = $pdo->prepare(
                    "UPDATE {$table} SET tenant_id = :tenantId WHERE id = :id AND tenant_id IS NULL"
                );
                $stmt->execute([
                    'tenantId' => $tenantId,
                    'id' => $entity->getId(),
                ]);

                if ($stmt->rowCount() > 0) {
                    $updated++;
                }
            } catch (Throwable $e) {
                $this->log->error(
                    "Global Module: Failed to backfill tenantId on {$entityType} '{$entity->getId()}': "
                    . $e->getMessage()
                );
                $skipped++;
            }

            if ($updated > 0 && $updated % self::BATCH_SIZE === 0) {
                $this->log->info("Global Module: Backfilled {$updated} {$entityType}(s) so far...");
            }
        }

        $this->log->info(
            "Global Module: {$entityType}.tenantId backfill complete. Updated: {$updated}, Skipped: {$skipped}."
        );
    }
}
