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

namespace Espo\Modules\FeatureClinica\Hooks\Appointment;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Bridges profissionalId (Profissional entity) to professionals (User linkMultiple)
 * for calendar visibility. Runs before SyncAssignedUsers (order 9).
 */
class SyncProfissionalToAssignedUsers
{
    public static int $order = 5;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    public function beforeSave(Entity $entity, array $options): void
    {
        $profissionalId = $entity->has('profissionalId')
            ? $entity->get('profissionalId')
            : $entity->getFetched('profissionalId');

        if (!$profissionalId) {
            return;
        }

        if (
            !$entity->isNew()
            && !$entity->isAttributeChanged('profissionalId')
        ) {
            return;
        }

        $profissional = $this->entityManager->getEntityById('Profissional', $profissionalId);
        if (!$profissional) {
            return;
        }

        $userId = $profissional->get('userId');
        if (!$userId) {
            return;
        }

        $professionalsIds = $entity->has('professionalsIds')
            ? $entity->get('professionalsIds')
            : ($entity->getFetched('professionalsIds') ?: []);

        if (!is_array($professionalsIds)) {
            $professionalsIds = [];
        }

        if (!in_array($userId, $professionalsIds)) {
            $professionalsIds[] = $userId;
            $entity->set('professionalsIds', $professionalsIds);
        }
    }
}
