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

namespace Espo\Modules\Clinica\Hooks\User;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Entities\User;
use Espo\Entities\Preferences;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\Core\Utils\Metadata;

/**
 * Hook to automatically add default calendar views to newly created users.
 * Runs after a User entity is saved.
 */
class AddDefaultCalendarViews implements AfterSave
{
    public static int $order = 10;

    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        /** @var User $entity */
        
        // TEST: Log that this hook is triggered
        file_put_contents('/tmp/user-hook-test.log', date('Y-m-d H:i:s') . " - User hook triggered for ID: " . $entity->getId() . "\n", FILE_APPEND);
        error_log("USER HOOK TRIGGERED: " . $entity->getId());
        
        // Only run for new users (not updates)
        if (!$entity->isNew()) {
            return;
        }

        // Only run for regular and admin users
        $userType = $entity->getType();
        if (!in_array($userType, ['regular', 'admin'])) {
            return;
        }

        // Check if UUID mode is enabled
        $toHash = $this->metadata->get(['app', 'recordId', 'type']) === 'uuid4' ||
                  $this->metadata->get(['app', 'recordId', 'dbType']) === 'uuid';

        // Get preferences for the new user
        $preferences = $this->entityManager->getEntityById(Preferences::ENTITY_TYPE, $entity->getId());
        
        if (!$preferences) {
            return;
        }

        // Define default calendar views
        $calendarViews = [
            [
                'id' => 'view-cm-profissionais',
                'name' => 'Calendário Profissionais',
                'mode' => 'agendaWeek',
                'teamIdList' => [$this->prepareId('clinica-team', $toHash)],
                'teamNames' => (object)[$this->prepareId('clinica-team', $toHash) => 'Clínica'],
            ],
            [
                'id' => 'view-cm-all',
                'name' => 'Calendário Geral',
                'mode' => 'month',
                'teamIdList' => [
                    $this->prepareId('clinica-team', $toHash),
                    $this->prepareId('enfermagem-team', $toHash),
                    $this->prepareId('administrativo-team', $toHash),
                ],
                'teamNames' => (object)[
                    $this->prepareId('clinica-team', $toHash) => 'Clínica',
                    $this->prepareId('enfermagem-team', $toHash) => 'Enfermagem',
                    $this->prepareId('administrativo-team', $toHash) => 'Administrativo',
                ],
            ],
        ];

        // Get existing calendar views (should be empty for new users)
        $existingViews = $preferences->get('calendarViewDataList') ?? [];
        
        if (!is_array($existingViews)) {
            $existingViews = [];
        }

        // Add calendar views
        foreach ($calendarViews as $viewData) {
            $existingViews[] = $viewData;
        }

        // Save preferences
        try {
            $preferences->set('calendarViewDataList', $existingViews);
            $this->entityManager->saveEntity($preferences);
        } catch (\Exception $e) {
            // Silently fail - don't break user creation
        }
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

