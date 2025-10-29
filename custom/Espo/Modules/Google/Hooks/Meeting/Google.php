<?php
/***********************************************************************************
 * The contents of this file are subject to the Extension License Agreement
 * ("Agreement") which can be viewed at
 * https://www.espocrm.com/extension-license-agreement/.
 * By copying, installing downloading, or using this file, You have unconditionally
 * agreed to the terms and conditions of the Agreement, and You may not use this
 * file except in compliance with the Agreement. Under the terms of the Agreement,
 * You shall not license, sublicense, sell, resell, rent, lease, lend, distribute,
 * redistribute, market, publish, commercialize, or otherwise transfer rights or
 * usage to the software or any modified version or derivative work of the software
 * created by or for you.
 *
 * Copyright (C) 2015-2025 EspoCRM, Inc.
 *
 * License ID: 99e925c7f52e4853679eb7c383162336
 ************************************************************************************/

namespace Espo\Modules\Google\Hooks\Meeting;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class Google
{
    public static $order = 9;

    private EntityManager $entityManager;

    public function __construct(
        EntityManager $entityManager
    ) {
        $this->entityManager = $entityManager;
    }

    public function beforeSave(Entity $entity): void
    {
        if (
            !$entity->isNew() &&
            $entity->isAttributeChanged('assignedUserId') &&
            $entity->get('googleCalendarEventId')
        ) {
            $dummy = $this->entityManager->getNewEntity($entity->getEntityType());

            $copyList = [
                'name',
                'assignedUserId',
                'googleCalendarId',
                'googleCalendarEventId',
                'dateStart',
                'dateEnd',
            ];

            foreach ($copyList as $field) {
                $dummy->set($field, $entity->getFetched($field));
            }

            $dummy->set('deleted', true);

            $this->entityManager->saveEntity($dummy, ['skipHooks' => true]);
            $this->entityManager->removeEntity($dummy, ['skipHooks' => true]);

            $entity->set('googleCalendarEventId', '');
            $entity->set('googleCalendarId', '');
        }

        if (!$entity->isNew() && $entity->getFetched('googleCalendarEventId') == 'FAIL') {
            $entity->set('googleCalendarEventId', '');
        }
    }
}
