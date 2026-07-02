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
 * Backfills chatwoot_account.tenant_id, chatwoot_ai_agent_run.tenant_id,
 * and whatsapp_campaign.tenant_id.
 *
 * Passes, run sequentially and idempotent across repeated rebuilds:
 *
 *   Pass 0 — normalizes legacy sentinel values ('', 'NULL', '0') stored in
 *            tenant_id columns (written by old Drizzle defaults) to real SQL
 *            NULL, so the subsequent passes can repair those rows.
 *
 *   Pass 1 — chatwoot_account.tenant_id ← tenant whose base_user_team_id is
 *            in the account's entity_team membership. Skips accounts whose
 *            teams resolve to more than one tenant (logged once).
 *
 *   Pass 2 — chatwoot_ai_agent_run.tenant_id ← parent account's tenant_id.
 *
 *   Pass 3 — whatsapp_campaign.tenant_id ← parent account's tenant_id.
 *
 * Passes 1-3 only touch rows whose tenant_id is currently NULL, so they
 * never clobber an explicit assignment.
 *
 * Implemented via raw SQL to stay within a single statement per pass and
 * avoid loading large result sets through the ORM.
 */
class BackfillChatwootTenant implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    public function process(): void
    {
        $pdo = $this->entityManager->getPDO();

        if (!$this->tableExists($pdo, 'chatwoot_account') || !$this->tableExists($pdo, 'tenant')) {
            $this->log->info('BackfillChatwootTenant: chatwoot_account or tenant table missing; skipping');
            return;
        }

        if (!$this->columnExists($pdo, 'chatwoot_account', 'tenant_id')) {
            $this->log->info('BackfillChatwootTenant: chatwoot_account.tenant_id column not yet created; skipping');
            return;
        }

        $this->normalizeSentinels($pdo);
        $this->backfillAccounts($pdo);
        $this->backfillRuns($pdo);
        $this->backfillCampaigns($pdo);
    }

    /**
     * Convert legacy sentinel tenant_id values (empty string, literal 'NULL',
     * '0' — written by old Drizzle defaults) to real SQL NULL so that the
     * backfill passes (which filter on IS NULL) can repair those rows and
     * downstream cascade hooks stop propagating garbage tenant ids.
     */
    private function normalizeSentinels(\PDO $pdo): void
    {
        foreach (['chatwoot_account', 'chatwoot_ai_agent_run', 'whatsapp_campaign'] as $table) {
            if (!$this->tableExists($pdo, $table) || !$this->columnExists($pdo, $table, 'tenant_id')) {
                continue;
            }

            try {
                $count = $pdo->exec(
                    "UPDATE {$table} SET tenant_id = NULL " .
                    "WHERE tenant_id IS NOT NULL AND TRIM(tenant_id) IN ('', 'NULL', '0')"
                );

                if ($count) {
                    $this->log->info(
                        "BackfillChatwootTenant: normalized {$count} sentinel tenant_id value(s) on {$table}"
                    );
                }
            } catch (\Throwable $e) {
                $this->log->error(
                    "BackfillChatwootTenant: sentinel normalization failed for {$table}: " . $e->getMessage()
                );
            }
        }
    }

    /**
     * For each ChatwootAccount without a tenant, resolve the tenant whose
     * base_user_team matches one of the account's teams. Sets tenant_id
     * only when the resolution is unambiguous.
     */
    private function backfillAccounts(\PDO $pdo): void
    {
        $driver = $this->getDriverName($pdo);

        try {
            // Subquery returns the resolved tenant_id ONLY when exactly one
            // tenant matches the account's teams. NULL otherwise (kept as-is).
            $sql = match ($driver) {
                'mysql', 'pgsql' => "
                    UPDATE chatwoot_account a
                    SET tenant_id = (
                        SELECT MIN(t.id)
                        FROM tenant t
                        INNER JOIN entity_team et
                            ON et.team_id = t.base_user_team_id
                            AND et.entity_type = 'ChatwootAccount'
                            AND et.entity_id = a.id
                            AND et.deleted = false
                        WHERE t.deleted = false
                        HAVING COUNT(DISTINCT t.id) = 1
                    )
                    WHERE a.tenant_id IS NULL
                      AND a.deleted = false
                ",
                default => null,
            };

            if ($sql === null) {
                $this->log->warning(
                    "BackfillChatwootTenant: unsupported driver '{$driver}', skipping account backfill"
                );
                return;
            }

            $count = $pdo->exec($sql);
            $this->log->info("BackfillChatwootTenant: backfilled tenant_id on {$count} ChatwootAccount row(s)");
        } catch (\Throwable $e) {
            $this->log->error('BackfillChatwootTenant: account backfill failed: ' . $e->getMessage());
        }
    }

    /**
     * Copy chatwoot_account.tenant_id to chatwoot_ai_agent_run.tenant_id
     * for every run that still has a NULL tenant.
     */
    private function backfillRuns(\PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'chatwoot_ai_agent_run')) {
            $this->log->info('BackfillChatwootTenant: chatwoot_ai_agent_run table missing; skipping run backfill');
            return;
        }

        if (!$this->columnExists($pdo, 'chatwoot_ai_agent_run', 'tenant_id')) {
            $this->log->info('BackfillChatwootTenant: chatwoot_ai_agent_run.tenant_id column not yet created; skipping run backfill');
            return;
        }

        $driver = $this->getDriverName($pdo);

        try {
            $sql = match ($driver) {
                'mysql' => "
                    UPDATE chatwoot_ai_agent_run r
                    INNER JOIN chatwoot_account a ON a.id = r.chatwoot_account_id
                    SET r.tenant_id = a.tenant_id
                    WHERE r.tenant_id IS NULL
                      AND a.tenant_id IS NOT NULL
                      AND r.deleted = 0
                ",
                'pgsql' => "
                    UPDATE chatwoot_ai_agent_run r
                    SET tenant_id = a.tenant_id
                    FROM chatwoot_account a
                    WHERE a.id = r.chatwoot_account_id
                      AND r.tenant_id IS NULL
                      AND a.tenant_id IS NOT NULL
                      AND r.deleted = false
                ",
                default => null,
            };

            if ($sql === null) {
                $this->log->warning(
                    "BackfillChatwootTenant: unsupported driver '{$driver}', skipping run backfill"
                );
                return;
            }

            $count = $pdo->exec($sql);
            $this->log->info("BackfillChatwootTenant: backfilled tenant_id on {$count} ChatwootAiAgentRun row(s)");
        } catch (\Throwable $e) {
            $this->log->error('BackfillChatwootTenant: run backfill failed: ' . $e->getMessage());
        }
    }

    /**
     * Copy chatwoot_account.tenant_id to whatsapp_campaign.tenant_id
     * for every campaign that still has a NULL tenant.
     */
    private function backfillCampaigns(\PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'whatsapp_campaign')) {
            $this->log->info('BackfillChatwootTenant: whatsapp_campaign table missing; skipping campaign backfill');
            return;
        }

        if (!$this->columnExists($pdo, 'whatsapp_campaign', 'tenant_id')) {
            $this->log->info('BackfillChatwootTenant: whatsapp_campaign.tenant_id column not yet created; skipping campaign backfill');
            return;
        }

        $driver = $this->getDriverName($pdo);

        try {
            $sql = match ($driver) {
                'mysql' => "
                    UPDATE whatsapp_campaign c
                    INNER JOIN chatwoot_account a ON a.id = c.chatwoot_account_id
                    SET c.tenant_id = a.tenant_id
                    WHERE c.tenant_id IS NULL
                      AND a.tenant_id IS NOT NULL
                      AND c.deleted = 0
                ",
                'pgsql' => "
                    UPDATE whatsapp_campaign c
                    SET tenant_id = a.tenant_id
                    FROM chatwoot_account a
                    WHERE a.id = c.chatwoot_account_id
                      AND c.tenant_id IS NULL
                      AND a.tenant_id IS NOT NULL
                      AND c.deleted = false
                ",
                default => null,
            };

            if ($sql === null) {
                $this->log->warning(
                    "BackfillChatwootTenant: unsupported driver '{$driver}', skipping campaign backfill"
                );
                return;
            }

            $count = $pdo->exec($sql);
            $this->log->info("BackfillChatwootTenant: backfilled tenant_id on {$count} WhatsAppCampaign row(s)");
        } catch (\Throwable $e) {
            $this->log->error('BackfillChatwootTenant: campaign backfill failed: ' . $e->getMessage());
        }
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        try {
            $pdo->query("SELECT 1 FROM {$table} LIMIT 1");
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        try {
            $pdo->query("SELECT {$column} FROM {$table} LIMIT 1");
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function getDriverName(\PDO $pdo): string
    {
        try {
            return strtolower((string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
        } catch (\Throwable) {
            return '';
        }
    }
}
