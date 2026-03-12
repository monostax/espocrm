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

namespace Espo\Modules\FeatureClinica\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

/**
 * One-time migration of Appointment status values from the old English enum
 * to the new FeatureClinica enum. Idempotent -- safe to re-run.
 *
 * Mapping: Planned -> Scheduled, Held -> Realized, Not Held -> NoShow.
 * "Canceled" is unchanged.
 */
class MigrateAppointmentStatus implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    public function process(): void
    {
        $this->log->info('FeatureClinica Module: Starting Appointment status migration...');

        $mapping = [
            'Planned' => 'Scheduled',
            'Held' => 'Realized',
            'Not Held' => 'NoShow',
        ];

        $pdo = $this->entityManager->getPDO();
        $totalUpdated = 0;

        foreach ($mapping as $oldStatus => $newStatus) {
            $sql = "UPDATE `appointment` SET `status` = :newStatus WHERE `status` = :oldStatus AND `deleted` = 0";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'newStatus' => $newStatus,
                'oldStatus' => $oldStatus,
            ]);

            $count = $stmt->rowCount();
            $totalUpdated += $count;

            if ($count > 0) {
                $this->log->info(
                    "FeatureClinica Module: Migrated {$count} Appointment(s) from '{$oldStatus}' to '{$newStatus}'"
                );
            }
        }

        $this->log->info(
            "FeatureClinica Module: Appointment status migration complete. Total updated: {$totalUpdated}"
        );
    }
}
