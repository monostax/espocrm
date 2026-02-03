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

namespace Espo\Modules\Chatwoot\Hooks\WahaPlatform;

use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Enforces that only one WahaPlatform can have isDefault = true.
 * When a record is set as default, all other records have their isDefault cleared.
 */
class EnforceUniqueDefault
{
    private const ENTITY_TYPE = 'WahaPlatform';

    public function __construct(
        private EntityManager $entityManager
    ) {}

    /**
     * Before saving, if this record is being set as default,
     * clear isDefault on all other records.
     *
     * @param Entity $entity
     * @param array<string, mixed> $options
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        // Only proceed if isDefault is being set to true
        if (!$entity->get('isDefault')) {
            return;
        }

        // Only proceed if isDefault actually changed (or this is a new entity with isDefault=true)
        if (!$entity->isNew() && !$entity->isAttributeChanged('isDefault')) {
            return;
        }

        // Find all other records that have isDefault = true
        $query = $this->entityManager
            ->getRDBRepository(self::ENTITY_TYPE)
            ->where(['isDefault' => true]);

        // Exclude current entity if it has an ID (not new)
        if ($entity->getId()) {
            $query->where(['id!=' => $entity->getId()]);
        }

        $others = $query->find();

        // Clear isDefault on all other records
        foreach ($others as $other) {
            $other->set('isDefault', false);
            $this->entityManager->saveEntity($other, [SaveOption::SKIP_ALL => true]);
        }
    }
}
