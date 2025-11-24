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

namespace Espo\Modules\ClinicaMedica\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Metadata;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

/**
 * Rebuild action to seed Teams with predictable IDs.
 * Runs automatically during system rebuild.
 */
class SeedTeams implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
        private Log $log
    ) {}

    public function process(): void
    {
        $this->log->info('ClinicaMedica Module: Starting to seed Teams with predictable IDs...');

        // Check if UUID mode is enabled
        $toHash = $this->metadata->get(['app', 'recordId', 'type']) === 'uuid4' ||
                  $this->metadata->get(['app', 'recordId', 'dbType']) === 'uuid';

        $teams = [
            [
                'id' => $this->prepareId('cm-medico', $toHash),
                'name' => 'Clínica Médica - Médicos',
            ],
            [
                'id' => $this->prepareId('cm-administrativo', $toHash),
                'name' => 'Clínica Médica - Recepcionistas',
            ],
            [
                'id' => $this->prepareId('cm-pacientes', $toHash),
                'name' => 'Clínica Médica - Pacientes',
            ],
            [
                'id' => $this->prepareId('cm-gestores', $toHash),
                'name' => 'Clínica Médica - Gestores',
            ]
        ];

        $createdCount = 0;
        $skippedCount = 0;

        foreach ($teams as $teamData) {
            // Check if team already exists
            $existingTeam = $this->entityManager->getEntityById('Team', $teamData['id']);
            
            if (!$existingTeam) {
                try {
                    $this->entityManager->createEntity('Team', $teamData, [
                        'createdById' => 'system',
                        'skipWorkflow' => true,
                    ]);
                    $createdCount++;
                    $this->log->info("ClinicaMedica Module: Created team '{$teamData['name']}' with ID '{$teamData['id']}'");
                } catch (\Exception $e) {
                    $this->log->error("ClinicaMedica Module: Failed to create team '{$teamData['name']}': " . $e->getMessage());
                }
            } else {
                $skippedCount++;
                $this->log->debug("ClinicaMedica Module: Team '{$teamData['name']}' already exists, skipping.");
            }
        }

        $this->log->info("ClinicaMedica Module: Team seeding complete. Created: {$createdCount}, Skipped: {$skippedCount}");
    }

    /**
     * Prepare ID for entity.
     * If UUID mode is enabled, returns MD5 hash of the ID.
     * Otherwise, returns the ID as-is.
     */
    private function prepareId(string $id, bool $toHash): string
    {
        if ($toHash) {
            return md5($id);
        }

        return $id;
    }
}

