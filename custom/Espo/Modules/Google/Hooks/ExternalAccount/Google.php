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

namespace Espo\Modules\Google\Hooks\ExternalAccount;

use Espo\Modules\Google\Repositories\GoogleCalendar;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Repositories\UserData;

class Google
{
    public static $order = 9;

    private EntityManager $entityManager;

    public function __construct(
        EntityManager $entityManager
    ) {
        $this->entityManager = $entityManager;
    }

    public function afterSave(Entity $entity, $options)
    {
        [$integration, $userId] = explode('__', $entity->get('id'));

        if (!empty($options['isTokenRenewal'])) {
            return;
        }

        if ($integration == 'Google') {
            $user = $this->entityManager->getEntityById('User', $userId);

            if (!$user) {
                return;
            }

            $userData = null;

            if ($this->entityManager->hasRepository('UserData')) {
                /** @var UserData $repo */
                $repo = $this->entityManager->getRepository('UserData');

                $userData = $repo->getByUserId($userId);
            }

            if ($userData) {
                $imapHandlerClassName = 'Espo\\Modules\\Google\\Core\\Google\\ImapHandler';
                $smtpHandlerClassName = 'Espo\\Modules\\Google\\Core\\Google\\SmtpHandler';

                $imapHandlers = $userData->get('imapHandlers') ?? (object) [];
                $smtpHandlers = $userData->get('smtpHandlers') ?? (object) [];

                foreach (get_object_vars($imapHandlers) as $emailAddress => $className) {
                    if ($className === $imapHandlerClassName) {
                        unset($imapHandlers->$emailAddress);
                    }
                }
                foreach (get_object_vars($smtpHandlers) as $emailAddress => $className) {
                    if ($className === $smtpHandlerClassName) {
                        unset($smtpHandlers->$emailAddress);
                    }
                }

                if ($entity->get('gmailEnabled')) {
                    if ($entity->get('gmailEmailAddress')) {
                        $emailAddress = strtolower($entity->get('gmailEmailAddress'));
                        $imapHandlers->$emailAddress = 'Espo\\Modules\\Google\\Core\\Google\\ImapHandler';
                        $smtpHandlers->$emailAddress = 'Espo\\Modules\\Google\\Core\\Google\\SmtpHandler';
                    }
                }

                $userData->set([
                    'imapHandlers' => $imapHandlers,
                    'smtpHandlers' => $smtpHandlers,
                ]);

                $this->entityManager->saveEntity($userData, ['silent' => true]);
            }

            /** @var GoogleCalendar $repo */
            $repo = $this->entityManager->getRepository('GoogleCalendar');

            $storedUsersCalendars = $repo->storedUsersCalendars($userId);

            $direction = $entity->get('calendarDirection');
            $monitoredCalendarIds = $entity->get('calendarMonitoredCalendarsIds');
            $monitoredCalendars = $entity->get('calendarMonitoredCalendarsNames');

            if (!is_object($monitoredCalendars)) {
                $monitoredCalendars = (object) [];
            }

            if (empty($monitoredCalendarIds)) {
                $monitoredCalendarIds = [];
            }

            $mainCalendarId = $entity->get('calendarMainCalendarId');
            $mainCalendarName = $entity->get('calendarMainCalendarName');

            if ($direction == "GCToEspo" && !in_array($mainCalendarId, $monitoredCalendarIds)) {
                $monitoredCalendarIds[] = $mainCalendarId;
                $monitoredCalendars->$mainCalendarId = $mainCalendarName;
            }

            foreach ($monitoredCalendarIds as $calendarId) {
                /** @var GoogleCalendar $repo */
                $repo = $this->entityManager->getRepository('GoogleCalendar');

                $googleCalendar = $repo->getCalendarByGCId($calendarId);

                if (empty($googleCalendar)) {
                    $googleCalendar = $this->entityManager->getNewEntity('GoogleCalendar');

                    $googleCalendar->set('name', $monitoredCalendars->$calendarId);
                    $googleCalendar->set('calendarId', $calendarId);

                    $this->entityManager->saveEntity($googleCalendar);
                }

                $id = $googleCalendar->get('id');

                if (isset($storedUsersCalendars['monitored'][$id])) {
                    if (!$storedUsersCalendars['monitored'][$id]['active']) {
                        $calendarEntity = $this->entityManager
                            ->getEntityById('GoogleCalendarUser', $storedUsersCalendars['monitored'][$id]['id']);
                        $calendarEntity->set('active', true);

                        $this->entityManager->saveEntity($calendarEntity);

                    }
                } else {
                    $calendarEntity = $this->entityManager->getNewEntity('GoogleCalendarUser');

                    $calendarEntity->set('userId', $userId);
                    $calendarEntity->set('type', 'monitored');
                    $calendarEntity->set('role', 'owner');
                    $calendarEntity->set('googleCalendarId', $id);

                    $this->entityManager->saveEntity($calendarEntity);
                }
            }

            foreach ($storedUsersCalendars['monitored'] as $calendar) {
                if (
                    $calendar['active'] &&
                    (!is_array($monitoredCalendarIds) || !in_array($calendar['calendarId'], $monitoredCalendarIds))
                ) {
                    $calendarEntity = $this->entityManager->getEntityById('GoogleCalendarUser', $calendar['id']);
                    $calendarEntity->set('active', false);

                    $this->entityManager->saveEntity($calendarEntity);
                }
            }

            if ($direction == "GCToEspo") {
                $mainCalendarId = '';
                $mainCalendarName = [];
            }

            if (empty($mainCalendarId)) {
                foreach($storedUsersCalendars['main'] as $calendar) {
                    if ($calendar['active']) {
                        $calendarEntity = $this->entityManager
                            ->getEntityById('GoogleCalendarUser', $calendar['id']);

                        $calendarEntity->set('active', false);
                        $this->entityManager->saveEntity($calendarEntity);
                    }
                }
            } else {
                /** @var GoogleCalendar $repo */
                $repo = $this->entityManager->getRepository('GoogleCalendar');

                $googleCalendar = $repo->getCalendarByGCId($mainCalendarId);

                if (empty($googleCalendar)) {
                    $googleCalendar = $this->entityManager->getNewEntity('GoogleCalendar');

                    $googleCalendar->set('name', $mainCalendarName);
                    $googleCalendar->set('calendarId', $mainCalendarId);

                    $this->entityManager->saveEntity($googleCalendar);
                }

                $id = $googleCalendar->get('id');

                foreach ($storedUsersCalendars['main'] as $calendarId => $calendar) {
                    if ($calendar['active'] && $id != $calendarId) {
                        $calendarEntity = $this->entityManager->getEntityById('GoogleCalendarUser', $calendar['id']);
                        $calendarEntity->set('active', false);

                        $this->entityManager->saveEntity($calendarEntity);
                    }
                    else if (!$calendar['active'] && $id == $calendarId) {
                        $calendarEntity = $this->entityManager->getEntityById('GoogleCalendarUser', $calendar['id']);
                        $calendarEntity->set('active', true);

                        $this->entityManager->saveEntity($calendarEntity);
                    }
                }

                if (!isset($storedUsersCalendars['main'][$id])) {
                    $calendarEntity = $this->entityManager->getNewEntity('GoogleCalendarUser');

                    $calendarEntity->set('userId', $userId);
                    $calendarEntity->set('type', 'main');
                    $calendarEntity->set('role', 'owner');
                    $calendarEntity->set('googleCalendarId', $id);

                    $this->entityManager->saveEntity($calendarEntity);
                }
            }
        }
    }

    public function beforeSave(Entity $entity): void
    {
        [$integration, $userId] = explode('__', $entity->get('id'));

        if ($integration != 'Google') {
            return;
        }

        $user = $this->entityManager->getEntityById('User', $userId);

        if (!$user) {
            return;
        }

        $prevEntity = $this->entityManager->getEntityById('ExternalAccount', $entity->get('id'));

        if (!$prevEntity || $prevEntity->get('calendarStartDate') <= $entity->get('calendarStartDate')) {
            return;
        }

        $googleCalendarUsers = $this->entityManager
            ->getRDBRepository('GoogleCalendarUser')
            ->where([
                'active' => true,
                'userId' => $userId
            ])
            ->find();

        foreach ($googleCalendarUsers as $googleCalendarUser) {
            $googleCalendarUser->set('pageToken', '');
            $googleCalendarUser->set('syncToken', '');
            $googleCalendarUser->set('lastSync', null);

            $this->entityManager->saveEntity($googleCalendarUser);
        }
    }
}
