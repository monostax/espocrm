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

/**
 * Backfills `opportunity.version_number` and pins the column to NOT NULL.
 *
 * Pre-flight gating for the per-opportunity follow-up workflow
 * (see `plan-followup-v2.md`, Pre-flight Gating step 1 and Decision #16).
 *
 * EspoCRM auto-creates `version_number` (BIGINT) on entities with
 * `optimisticConcurrencyControl: true`, but the ORM definition does NOT
 * set `notNull` or a default. Legacy rows therefore carry `NULL`, which
 * breaks the follow-up workflow's optimistic-concurrency PUTs:
 *
 *   - MySQL `NULL + 1` stays `NULL`, so `versionNumber = versionNumber + 1`
 *     never advances.
 *   - `WHERE version_number = ?` does not match `NULL` rows (three-valued
 *     logic), so the conditional PUTs in Decision #1 step (d) and
 *     Decision #1g Branch A would silently match zero rows on legacy data.
 *
 * Idempotent across repeated `php rebuild` invocations: the UPDATE only
 * touches NULL rows, and the ALTER is a no-op once the column is already
 * NOT NULL DEFAULT 1.
 *
 * MySQL and PostgreSQL syntax branches are handled separately. Other
 * platforms are logged and skipped.
 */
class BackfillOpportunityVersionNumber implements RebuildAction
{
    private const TABLE = 'opportunity';
    private const COLUMN = 'version_number';
    private const DEFAULT_VERSION = 1;

    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    public function process(): void
    {
        $pdo = $this->entityManager->getPDO();

        // Ensure the opportunity table exists before doing anything else.
        try {
            $pdo->query('SELECT 1 FROM ' . self::TABLE . ' LIMIT 1');
        } catch (\Throwable $e) {
            $this->log->info(
                'BackfillOpportunityVersionNumber: opportunity table not found, skipping'
            );
            return;
        }

        // The column may not exist yet on a brand-new install where the
        // schema rebuild hasn't run before us (it should, per DataManager
        // ordering, but be defensive).
        if (!$this->columnExists($pdo)) {
            $this->log->info(
                'BackfillOpportunityVersionNumber: opportunity.version_number column not found, skipping'
            );
            return;
        }

        $this->backfillNulls($pdo);
        $this->enforceNotNull($pdo);
    }

    private function columnExists(\PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT ' . self::COLUMN . ' FROM ' . self::TABLE . ' LIMIT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * UPDATE opportunity SET version_number = 1 WHERE version_number IS NULL.
     */
    private function backfillNulls(\PDO $pdo): void
    {
        try {
            $sql = sprintf(
                'UPDATE %s SET %s = %d WHERE %s IS NULL',
                self::TABLE,
                self::COLUMN,
                self::DEFAULT_VERSION,
                self::COLUMN
            );

            $count = $pdo->exec($sql);

            $this->log->info(
                "BackfillOpportunityVersionNumber: backfilled {$count} opportunity row(s) " .
                "with version_number = " . self::DEFAULT_VERSION
            );
        } catch (\Throwable $e) {
            $this->log->error(
                'BackfillOpportunityVersionNumber: failed to backfill NULL version_number rows: ' .
                $e->getMessage()
            );
        }
    }

    /**
     * Pin opportunity.version_number to NOT NULL DEFAULT 1.
     *
     * EspoCRM's schema rebuild runs in SOFT mode and does not revert
     * column-level NOT NULL/default tightening that the ORM definition
     * lacks. The ALTER therefore persists across subsequent rebuilds.
     */
    private function enforceNotNull(\PDO $pdo): void
    {
        $driver = $this->getDriverName($pdo);

        try {
            $sql = match ($driver) {
                'mysql' => sprintf(
                    'ALTER TABLE %s MODIFY %s BIGINT NOT NULL DEFAULT %d',
                    self::TABLE,
                    self::COLUMN,
                    self::DEFAULT_VERSION
                ),
                'pgsql' => sprintf(
                    'ALTER TABLE %s ALTER COLUMN %s SET DEFAULT %d, ALTER COLUMN %s SET NOT NULL',
                    self::TABLE,
                    self::COLUMN,
                    self::DEFAULT_VERSION,
                    self::COLUMN
                ),
                default => null,
            };

            if ($sql === null) {
                $this->log->warning(
                    "BackfillOpportunityVersionNumber: unsupported database driver '{$driver}', " .
                    "skipping ALTER. Manually pin opportunity.version_number to NOT NULL DEFAULT " .
                    self::DEFAULT_VERSION . '.'
                );
                return;
            }

            $pdo->exec($sql);

            $this->log->info(
                "BackfillOpportunityVersionNumber: pinned opportunity.version_number to " .
                "NOT NULL DEFAULT " . self::DEFAULT_VERSION . " ({$driver})"
            );
        } catch (\Throwable $e) {
            // ALTER on an already-correct column is a no-op on both engines.
            // Surface the error but do not throw — the rebuild pipeline
            // should continue.
            $this->log->error(
                'BackfillOpportunityVersionNumber: failed to enforce NOT NULL on opportunity.version_number: ' .
                $e->getMessage()
            );
        }
    }

    private function getDriverName(\PDO $pdo): string
    {
        try {
            return strtolower((string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
        } catch (\Throwable $e) {
            return '';
        }
    }
}
