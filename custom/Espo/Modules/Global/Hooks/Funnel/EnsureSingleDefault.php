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

namespace Espo\Modules\Global\Hooks\Funnel;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Ensures only one Funnel per Team is marked as default.
 * When a Funnel is set as default, all other funnels for the same team are set to not default.
 *
 * @implements BeforeSave<Entity>
 */
class EnsureSingleDefault implements BeforeSave
{
    public static int $order = 5;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        // Only process if isDefault is being set to true
        if (!$entity->get('isDefault')) {
            return;
        }

        // Only process if this is a new entity or isDefault has changed
        if (!$entity->isNew() && !$entity->isAttributeChanged('isDefault')) {
            return;
        }

        $teamId = $entity->get('teamId');

        if (!$teamId) {
            return;
        }

        // Find all other funnels for the same team that are marked as default
        $otherDefaults = $this->entityManager
            ->getRDBRepository('Funnel')
            ->where([
                'teamId' => $teamId,
                'isDefault' => true,
                'id!=' => $entity->getId(),
            ])
            ->find();

        // Set them to not default
        foreach ($otherDefaults as $otherFunnel) {
            $otherFunnel->set('isDefault', false);
            $this->entityManager->saveEntity($otherFunnel, ['skipHooks' => true]);
        }
    }
}
