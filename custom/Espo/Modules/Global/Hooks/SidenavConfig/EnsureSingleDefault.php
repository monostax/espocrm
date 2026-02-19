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

namespace Espo\Modules\Global\Hooks\SidenavConfig;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Ensures only one SidenavConfig per overlapping Team set is marked as default.
 * When a config is set as default, all other default configs that share any
 * team with this config are unset.
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
        if (!$entity->get('isDefault')) {
            return;
        }

        if (!$entity->isNew() && !$entity->isAttributeChanged('isDefault')) {
            return;
        }

        $teamIds = $entity->getLinkMultipleIdList('teams');

        if (empty($teamIds)) {
            return;
        }

        $otherDefaults = $this->entityManager
            ->getRDBRepository('SidenavConfig')
            ->distinct()
            ->join('teams')
            ->where([
                'teams.id' => $teamIds,
                'isDefault' => true,
                'id!=' => $entity->getId(),
            ])
            ->find();

        foreach ($otherDefaults as $otherConfig) {
            $otherConfig->set('isDefault', false);
            $this->entityManager->saveEntity($otherConfig, ['skipHooks' => true]);
        }
    }
}
