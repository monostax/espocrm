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
use Espo\Entities\User;
use Espo\Entities\Preferences;

/**
 * Rebuild action to seed Calendar Shared Views with predictable IDs.
 * Adds shared calendar views to admin user's preferences.
 * Runs automatically during system rebuild.
 */
class SeedCalendarViews implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
        private Log $log
    ) {}

    public function process(): void
    {
        $this->log->info('ClinicaMedica Module: Starting to seed Calendar Shared Views...');

        // Check if UUID mode is enabled
        $toHash = $this->metadata->get(['app', 'recordId', 'type']) === 'uuid4' ||
                  $this->metadata->get(['app', 'recordId', 'dbType']) === 'uuid';

        // Get all active users (excluding system, portal, api users)
        $users = $this->entityManager
            ->getRDBRepository(User::ENTITY_TYPE)
            ->where([
                'isActive' => true,
                'type' => ['regular', 'admin'], // Only regular and admin users
            ])
            ->find();

        if (!count($users)) {
            $this->log->warning('ClinicaMedica Module: No active users found, skipping calendar view seeding.');
            return;
        }

        $this->log->info("ClinicaMedica Module: Found " . count($users) . " active users to seed calendar views.");
        
        $totalAdded = 0;
        $totalSkipped = 0;
        $userCount = 0;

        // Define calendar views to seed (with all supported shared view types: Month, Week, Day)
        // Note: EspoCRM sharedViewModeList supports: month, basicWeek, basicDay
        // Timeline is not supported for shared views and must be used as a separate mode
        $calendarViews = [
            // Calendário dos Médicos - Month view
            [
                'id' => 'cm-medico-month',
                'name' => 'Calendário dos Médicos (Mês)',
                'mode' => 'month',
                'teamIdList' => [$this->prepareId('cm-medico', $toHash)],
                'teamNames' => (object)[$this->prepareId('cm-medico', $toHash) => 'Clínica Médica - Médicos'],
            ],
            // Calendário dos Médicos - Week view
            [
                'id' => 'cm-medico-week',
                'name' => 'Calendário dos Médicos (Semana)',
                'mode' => 'agendaWeek',
                'teamIdList' => [$this->prepareId('cm-medico', $toHash)],
                'teamNames' => (object)[$this->prepareId('cm-medico', $toHash) => 'Clínica Médica - Médicos'],
            ],
            // Calendário dos Médicos - Day view
            [
                'id' => 'cm-medico-day',
                'name' => 'Calendário dos Médicos (Dia)',
                'mode' => 'agendaDay',
                'teamIdList' => [$this->prepareId('cm-medico', $toHash)],
                'teamNames' => (object)[$this->prepareId('cm-medico', $toHash) => 'Clínica Médica - Médicos'],
            ],
            // Calendário Geral - Month view
            [
                'id' => 'cm-all-month',
                'name' => 'Calendário Geral (Mês)',
                'mode' => 'month',
                'teamIdList' => [
                    $this->prepareId('cm-medico', $toHash),
                    $this->prepareId('cm-administrativo', $toHash),
                    $this->prepareId('cm-pacientes', $toHash),
                    $this->prepareId('cm-gestores', $toHash),
                ],
                'teamNames' => (object)[
                    $this->prepareId('cm-medico', $toHash) => 'Clínica Médica - Médicos',
                    $this->prepareId('cm-administrativo', $toHash) => 'Clínica Médica - Administrativo',
                    $this->prepareId('cm-pacientes', $toHash) => 'Clínica Médica - Pacientes',
                    $this->prepareId('cm-gestores', $toHash) => 'Clínica Médica - Gestores',
                ],
            ],
            // Calendário Geral - Week view
            [
                'id' => 'cm-all-week',
                'name' => 'Calendário Geral (Semana)',
                'mode' => 'agendaWeek',
                'teamIdList' => [
                    $this->prepareId('cm-medico', $toHash),
                    $this->prepareId('cm-administrativo', $toHash),
                    $this->prepareId('cm-pacientes', $toHash),
                    $this->prepareId('cm-gestores', $toHash),
                ],
                'teamNames' => (object)[
                    $this->prepareId('cm-medico', $toHash) => 'Clínica Médica - Médicos',
                    $this->prepareId('cm-administrativo', $toHash) => 'Clínica Médica - Administrativo',
                    $this->prepareId('cm-pacientes', $toHash) => 'Clínica Médica - Pacientes',
                    $this->prepareId('cm-gestores', $toHash) => 'Clínica Médica - Gestores',
                ],
            ],
            // Calendário Geral - Day view
            [
                'id' => 'cm-all-day',
                'name' => 'Calendário Geral (Dia)',
                'mode' => 'agendaDay',
                'teamIdList' => [
                    $this->prepareId('cm-medico', $toHash),
                    $this->prepareId('cm-administrativo', $toHash),
                    $this->prepareId('cm-pacientes', $toHash),
                    $this->prepareId('cm-gestores', $toHash),
                ],
                'teamNames' => (object)[
                    $this->prepareId('cm-medico', $toHash) => 'Clínica Médica - Médicos',
                    $this->prepareId('cm-administrativo', $toHash) => 'Clínica Médica - Administrativo',
                    $this->prepareId('cm-pacientes', $toHash) => 'Clínica Médica - Pacientes',
                    $this->prepareId('cm-gestores', $toHash) => 'Clínica Médica - Gestores',
                ],
            ],
        ];

        // Loop through all users and add calendar views
        foreach ($users as $user) {
            $preferences = $this->entityManager->getEntityById(Preferences::ENTITY_TYPE, $user->getId());
            
            if (!$preferences) {
                $this->log->warning("ClinicaMedica Module: Could not load preferences for user '{$user->getUserName()}', skipping.");
                continue;
            }

            // Get existing calendar views
            $existingViews = $preferences->get('calendarViewDataList') ?? [];
            
            if (!is_array($existingViews)) {
                $existingViews = [];
            }

            // Track existing view IDs
            $existingIds = array_column($existingViews, 'id');

            $addedCount = 0;

            foreach ($calendarViews as $viewData) {
                if (!in_array($viewData['id'], $existingIds)) {
                    $existingViews[] = $viewData;
                    $addedCount++;
                    $totalAdded++;
                } else {
                    $totalSkipped++;
                }
            }

            // Save preferences if any views were added
            if ($addedCount > 0) {
                try {
                    $preferences->set('calendarViewDataList', $existingViews);
                    $this->entityManager->saveEntity($preferences);
                    $userCount++;
                    $this->log->info("ClinicaMedica Module: Added {$addedCount} calendar view(s) to user '{$user->getUserName()}'");
                } catch (\Exception $e) {
                    $this->log->error("ClinicaMedica Module: Failed to save calendar views for user '{$user->getUserName()}': " . $e->getMessage());
                }
            }
        }

        $this->log->info("ClinicaMedica Module: Calendar view seeding complete. Updated {$userCount} users, Added {$totalAdded} views total, Skipped {$totalSkipped} existing views.");
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

