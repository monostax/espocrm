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

namespace Espo\Modules\Global\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Enforce Report.applyAcl = true on every existing Report row.
 *
 * Rationale: in a multi-tenant deployment a Report with applyAcl = false
 * runs its underlying query without the user's row-level ACL filter,
 * which means a user from Tenant A could see data belonging to Tenant B
 * if they have access to the Report itself. The Report.applyAcl field
 * defaults to false in the Advanced module's stock entityDefs, so reports
 * created prior to the Global module override may still have it disabled.
 *
 * This rebuild action flips every NULL/0 row to 1, guaranteeing tenant
 * isolation on the data layer regardless of who created the Report.
 *
 * Idempotent: only touches Reports where apply_acl IS NULL or apply_acl = 0.
 */
class EnforceReportApplyAcl implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function process(): void
    {
        $this->log->info('Global Module: Enforcing Report.applyAcl = true on existing reports...');

        $pdo = $this->entityManager->getPDO();

        $countStmt = $pdo->query(
            "SELECT COUNT(*) FROM report " .
            "WHERE (apply_acl IS NULL OR apply_acl = false) AND deleted = false"
        );
        $remaining = (int) $countStmt->fetchColumn();

        if ($remaining === 0) {
            $this->log->info('Global Module: All Reports already have applyAcl = true. Nothing to do.');

            return;
        }

        $this->log->info("Global Module: {$remaining} Report(s) need applyAcl enforcement.");

        try {
            // Raw UPDATE to bypass entity hooks. Pure data migration; the
            // field is non-admin read-only via entityAcl override, so the
            // semantics of the change are admin-scoped by design.
            $stmt = $pdo->prepare(
                "UPDATE report SET apply_acl = true " .
                "WHERE (apply_acl IS NULL OR apply_acl = false) AND deleted = false"
            );
            $stmt->execute();

            $updated = $stmt->rowCount();

            $this->log->info(
                "Global Module: Report.applyAcl enforcement complete. Updated: {$updated}."
            );
        } catch (Throwable $e) {
            $this->log->error(
                'Global Module: Failed to enforce Report.applyAcl: ' . $e->getMessage()
            );
        }
    }
}
