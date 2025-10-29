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

namespace Espo\Modules\Google\Hooks\Common;

use Espo\Core\Templates\Entities\Event;
use Espo\Modules\Google\Repositories\GoogleCalendar as GoogleCalendarRepo;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class GoogleCalendar
{
    public static $order = 8;

    private EntityManager $entityManager;

    public function __construct(
        EntityManager $entityManager
    ) {
        $this->entityManager = $entityManager;
    }

    protected function isValidEntityType(Entity $entity): bool
    {
        return $entity instanceof Event;
    }

    public function beforeSave(Entity $entity): void
    {
        if (!empty($options['silent'])) {
            return;
        }

        if (!$this->isValidEntityType($entity)) {
            return;
        }

        /** @var GoogleCalendarRepo $repository */
        $repository = $this->entityManager->getRepository('GoogleCalendar');

        if ($entity->isNew()) {
            return;
        }

        $googleData = $repository->getEventEntityGoogleData($entity->getEntityType(), $entity->get('id'));

        if (empty($googleData['googleCalendarEventId'])) {
            return;
        }

        $googleCalendarEventId = $googleData['googleCalendarEventId'];
        $googleCalendarId = $googleData['googleCalendarId'];

        if ($googleCalendarEventId == 'FAIL') {
            $repository->storeEventRelation(
                $entity->getEntityType(),
                $entity->get('id'),
                $googleData['googleCalendarId'],
                ''
            );
        }
        else if ($entity->isAttributeChanged('assignedUserId')) {
            $newEntity = $this->entityManager->getNewEntity($entity->getEntityType());

            $copyFields = [
                'name',
                'assignedUserId',
                'dateStart',
                'dateEnd',
                'location',
            ];

            foreach ($copyFields as $field) {
                if (!$newEntity->hasAttribute($field)) {
                    continue;
                }

                $newEntity->set($field, $entity->getFetched($field));
            }

            $this->entityManager->saveEntity($newEntity, ['skipHooks' => true]);

            $repository->storeEventRelation(
                $newEntity->getEntityType(), $newEntity->get('id'), $googleCalendarId, $googleCalendarEventId);

            $this->entityManager->removeEntity($newEntity, ['skipHooks' => true]);

            $repository->resetEventRelation($entity->getEntityType(), $entity->get('id'));
        }
    }
}
