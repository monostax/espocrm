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

namespace Espo\Modules\Global\Hooks\Tenant;

use Espo\Entities\Preferences;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Applies tenant regional defaults (language, timeZone) to the Preferences
 * of a user newly linked to the tenant (Tenant.users relation).
 *
 * Rules:
 * - Only fills preferences that are currently EMPTY — an explicit choice a
 *   user already made is never overwritten.
 * - Empty tenant fields are skipped (empty = inherit instance default, so
 *   the user's empty preference already resolves to the same thing).
 * - Fires only on relate; unlinking a tenant does not revert preferences
 *   (they are the user's own settings from that point on).
 *
 * Runs after SyncUserTeams (order 20) — independent concerns, order only
 * matters for log readability.
 */
class ApplyRegionalDefaultsToUser
{
    public static int $order = 25;

    /** Tenant field => Preferences field. */
    private const FIELD_MAP = [
        'language' => 'language',
        'timeZone' => 'timeZone',
    ];

    public function __construct(
        private EntityManager $entityManager
    ) {}

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $relationParams
     */
    public function afterRelate(Entity $entity, array $options, array $relationParams): void
    {
        if (($relationParams['relationName'] ?? null) !== 'users') {
            return;
        }

        $userId = $relationParams['foreignId'] ?? null;

        if (!is_string($userId) || $userId === '') {
            return;
        }

        // Preferences record id == user id.
        $preferences = $this->entityManager->getEntityById(Preferences::ENTITY_TYPE, $userId);

        if (!$preferences) {
            return;
        }

        $changed = false;

        foreach (self::FIELD_MAP as $tenantField => $preferencesField) {
            $tenantValue = $entity->get($tenantField);

            if (!is_string($tenantValue) || $tenantValue === '') {
                continue;
            }

            $currentValue = $preferences->get($preferencesField);

            if (is_string($currentValue) && $currentValue !== '') {
                // User already made an explicit choice - keep it.
                continue;
            }

            $preferences->set($preferencesField, $tenantValue);

            $changed = true;
        }

        if (!$changed) {
            return;
        }

        $this->entityManager->saveEntity($preferences);
    }
}
