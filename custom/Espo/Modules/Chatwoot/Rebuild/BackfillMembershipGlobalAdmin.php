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
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

/**
 * One-shot backfill of `chatwoot_account_user_membership.global_admin` for
 * existing administrator memberships.
 *
 * Mirrors the Chatwoot migration `20260710000000_add_global_admin_to_account_users`,
 * which backfilled `global_admin = TRUE` for all pre-existing administrators so
 * they keep account-wide inbox visibility. Without this backfill, existing CRM
 * administrator memberships would default to `global_admin = false` and lose
 * access to non-member inboxes until the next SyncAccountUserMembershipsFromChatwoot
 * run — or permanently, for accounts whose sync is disabled.
 *
 * MUST run only ONCE: after the initial backfill, Chatwoot (via the minute-cron
 * membership sync) is the source of truth. Re-running the UPDATE on every rebuild
 * would re-grant global visibility to admins that were intentionally scoped.
 * A config flag guards one-shot semantics.
 */
class BackfillMembershipGlobalAdmin implements RebuildAction
{
    private const CONFIG_FLAG = 'chatwootMembershipGlobalAdminBackfilled';

    private const TABLE = 'chatwoot_account_user_membership';
    private const COLUMN = 'global_admin';

    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private ConfigWriter $configWriter,
        private Log $log
    ) {}

    public function process(): void
    {
        if ($this->config->get(self::CONFIG_FLAG)) {
            return;
        }

        $pdo = $this->entityManager->getPDO();

        // Defensive: the column is created by the schema rebuild, which should
        // run before rebuild actions — but do not mark the backfill as done if
        // it hasn't happened yet.
        if (!$this->columnExists($pdo)) {
            $this->log->info(
                'BackfillMembershipGlobalAdmin: ' . self::TABLE . '.' . self::COLUMN .
                ' column not found yet, skipping (will retry on next rebuild)'
            );
            return;
        }

        try {
            $sql = sprintf(
                "UPDATE %s SET %s = 1 WHERE role = 'administrator' AND deleted = 0",
                self::TABLE,
                self::COLUMN
            );

            $count = $pdo->exec($sql);

            $this->log->info(
                "BackfillMembershipGlobalAdmin: backfilled {$count} administrator " .
                "membership row(s) with " . self::COLUMN . " = 1"
            );
        } catch (\Throwable $e) {
            $this->log->error(
                'BackfillMembershipGlobalAdmin: failed to backfill: ' . $e->getMessage()
            );
            return;
        }

        $this->configWriter->set(self::CONFIG_FLAG, true);
        $this->configWriter->save();
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
}
