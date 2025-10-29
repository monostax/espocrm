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

namespace Espo\Modules\Google\Services;

use Espo\Core\AclManager;
use Espo\Core\InjectableFactory;
use Espo\Core\ServiceFactory;
use Espo\Entities\User;
use Espo\ORM\Entity;

use Espo\Modules\Google\Core\Google\Actions\Calendar;
use Espo\ORM\EntityManager;

class GoogleCalendar
{
    private ?Calendar $googleCalendarManager = null;

    private EntityManager $entityManager;
    private InjectableFactory $injectableFactory;
    private AclManager $aclManager;
    private User $user;
    private ServiceFactory $serviceFactory;

    public function __construct(
        EntityManager $entityManager,
        InjectableFactory $injectableFactory,
        AclManager $aclManager,
        User $user,
        ServiceFactory $serviceFactory
    ) {
        $this->entityManager = $entityManager;
        $this->injectableFactory = $injectableFactory;
        $this->aclManager = $aclManager;
        $this->user = $user;
        $this->serviceFactory = $serviceFactory;
    }

    protected function getGoogleCalendarManager(): Calendar
    {
        if (!$this->googleCalendarManager) {
            $this->googleCalendarManager = $this->injectableFactory->create(Calendar::class);
        }

        return $this->googleCalendarManager;
    }

    /**
     * @return array
     */
    public function usersCalendars(): array
    {
        $calendarManager = $this->getGoogleCalendarManager();
        $calendarManager->setUserId($this->user->get('id'));

        return $calendarManager->getCalendarList();
    }

    public function syncCalendar(Entity $calendar): void
    {
        /** @var ?User $user */
        $user = $this->entityManager->getEntityById('User', $calendar->get('userId'));

        if (!$user || !$user->get('isActive')) {
            return;
        }

        if (!$this->aclManager->check($user, 'GoogleCalendar')) {
            return;
        }

        $externalAccount = $this->entityManager
            ->getEntityById('ExternalAccount', 'Google__' . $calendar->get('userId'));

        $enabled = $externalAccount->get('enabled') &&
            ($externalAccount->get('calendarEnabled') || $externalAccount->get('googleCalendarEnabled'));

        if (!$enabled || !$calendar->get('userId')) {
            return;
        }

        $isConnected = $this->serviceFactory
            ->create('ExternalAccount')
            ->ping('Google', $calendar->get('userId'));

        if (!$isConnected) {
            $GLOBALS['log']
                ->error('Google Calendar Synchronization: \'' . $calendar->get('userName') .
                    '\' user could not connect to Google Server when synchronizing the calendar "' .
                    $calendar->get('googleCalendarName') . '"'
                );
        }

        $calendarManager = $this->getGoogleCalendarManager();
        $calendarManager->setUserId($calendar->get('userId'));

        $calendarManager->run($calendar, $externalAccount);
    }
}
