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

namespace Espo\Modules\Google\Jobs;

use Espo\Core\InjectableFactory;
use Espo\Core\Job\JobDataLess;
use Espo\Modules\Google\Services\GoogleCalendar;
use Espo\ORM\EntityManager;
use Exception;

class SynchronizeEventsWithGoogleCalendar implements JobDataLess
{
    private EntityManager $entityManager;
    private InjectableFactory $injectableFactory;

    public function __construct(
        EntityManager $entityManager,
        InjectableFactory $injectableFactory
    ) {
        $this->entityManager = $entityManager;
        $this->injectableFactory = $injectableFactory;
    }

    public function run(): void
    {
        $integrationEntity = $this->entityManager->getEntityById('Integration', 'Google');

        if (!$integrationEntity || !$integrationEntity->get('enabled')) {
            return;
        }

        $service = $this->injectableFactory->create(GoogleCalendar::class);

        $collection = $this->entityManager
            ->getRDBRepository('GoogleCalendarUser')
            ->where([
                'active' => true,
            ])
            ->order('lastLooked')
            ->find();

        foreach ($collection as $calendar) {
            try {
                $service->syncCalendar($calendar);
            }
            catch (Exception $e) {
                $GLOBALS['log']->error('GoogleCalendarERROR: Run Sync Error: ' . $e->getMessage());
            }
        }
    }
}
