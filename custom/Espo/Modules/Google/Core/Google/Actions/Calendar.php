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

namespace Espo\Modules\Google\Core\Google\Actions;

use Espo\Entities\ExternalAccount;
use Espo\Modules\Google\Core\Google\Clients\Calendar as CalendarClient;
use Espo\Modules\Google\Repositories\GoogleCalendar;
use Espo\ORM\Entity;

use DateTime;
use DateTimeZone;
use Exception;
use RuntimeException;

class Calendar extends Base
{
    const MAX_EVENT_COUNT = 20;
    const MAX_ESPO_EVENT_INSERT_COUNT = 20;
    const MAX_ESPO_EVENT_UPDATE_COUNT = 20;
    const MAX_RECURRENT_EVENT_COUNT = 20;

    const SUCCESS_INCREMENT = 1;
    const FAIL_INCREMENT = 0.5;

    private $syncParams = [];
    private ?Event $eventManager = null;

    private $eventCounter = 0;
    private $recurrentEventCounter = 0;
    private $espoEventInsertCounter = 0;
    private $espoEventUpdateCounter = 0;

    /**
     * @return CalendarClient
     */
    protected function getClient()
    {
        return parent::getClient()->getCalendarClient();
    }

    /**
     * @return array
     */
    public function getCalendarList($params = []): array
    {
        $lists = [];

        $client = $this->getClient();
        $response = $client->getCalendarList($params);

        if (is_array($response) && isset($response['items'])) {
            foreach ($response['items'] as $item) {
                $lists[$item['id']] = $item['summary'];
            }

            if (isset($response['nextPageToken'])) {
                $params['pageToken'] = $response['nextPageToken'];
                $this->getCalendarList($params);
            }
         }

         return $lists;
    }

    private function resetCounters()
    {
        $this->eventCounter = 0;
        $this->recurrentEventCounter = 0;
        $this->espoEventInsertCounter = 0;
        $this->espoEventUpdateCounter = 0;
    }

    /**
     * @param Entity $calendar
     * @param ExternalAccount $externalAccount
     */
    private function prepareData($calendar, $externalAccount): void
    {
        $this->resetCounters();

        $integrationStartDate = $externalAccount->get('calendarStartDate');
        $lastSync = $calendar->get('lastSync');
        $lastSyncArr = explode('_',  $lastSync);
        $lastSyncTime = (isset($lastSyncArr[0])) ? $lastSyncArr[0] : '';
        $lastSyncId = (isset($lastSyncArr[1])) ? $lastSyncArr[1] : '';
        $startDate = (!empty($lastSyncTime) && $lastSyncTime > $integrationStartDate) ?
            $lastSyncTime :
            $integrationStartDate;


        try {
            $startSyncTime = new DateTime('now');
        }
        catch (Exception $e) {
            throw new RuntimeException($e->getMessage());
        }

        $entityLabels = [];
        $syncEntities = [];
        $syncEntitiesTMP = $externalAccount->get('calendarEntityTypes');

        if (is_array($syncEntitiesTMP)) {
            foreach ($syncEntitiesTMP as $syncEntity) {
                if ($this->acl->check($syncEntity, 'read')) {
                    $syncEntities[] = $syncEntity;
                }
            }
        }

        if (empty($syncEntities)) {
            throw new RuntimeException("No allowed entity.");
        }

        foreach ($syncEntities as $entity) {

            $label = $externalAccount->get($entity . "IdentificationLabel");

            if (empty($label)) {
                $entityLabels[$entity] = $label;
            } else {
                $entityLabels = [$entity => $label] + $entityLabels;
            }
        }

        $googleCalendar = $this->entityManager
            ->getRDBRepository($calendar->getEntityType())
            ->getRelation($calendar, 'googleCalendar')
            ->findOne();

        //$googleCalendar = $calendar->get('googleCalendar');

        if (!$googleCalendar) {
            throw new RuntimeException("Cannot load calendar for user {$calendar->get('userId')}.");
        }

        $googleCalendarId = $googleCalendar->get('calendarId');
        $isMain = ($calendar->get('type') == 'main');

        $calendarInfo = $this->getClient()->getCalendarInfo($googleCalendarId);

        $googleTimeZone = (!empty($calendarInfo) && isset($calendarInfo['timeZone'])) ? $calendarInfo['timeZone'] : 'UTC';

        $userPreference = $this->entityManager->getEntityById('Preferences', $calendar->get('userId'));

        $userTimeZone = $userPreference->get('timeZone');

        $defaultEntity = (
            in_array(
                $externalAccount->get('calendarDefaultEntity'), $syncEntities)
            ) ?
            $externalAccount->get('calendarDefaultEntity') :
            '';

        $this->syncParams = [
            'fetchSince' => $integrationStartDate,
            'startDate' => $startDate,
            'lastUpdatedId' => $lastSyncId,
            'syncEntities' => $syncEntities,
            'entityLabels' => $entityLabels,
            'userId' => $calendar->get('userId'),
            'googleCalendarId' => $googleCalendarId,
            'direction' => $externalAccount->get('calendarDirection'),
            'defaultEntity' => $defaultEntity,
            'isMain' => $isMain,
            'isInMain' => (!$isMain && $externalAccount->get('calendarMainCalendarId') == $googleCalendarId),
            'calendar' => $calendar,
            'startSyncTime' => $startSyncTime->format('Y-m-d H:i:s'),
            'googleTimeZone' => (!empty($googleTimeZone)) ? $googleTimeZone : 'UTC',
            'userTimeZone' => (!empty($userTimeZone)) ? $userTimeZone : 'UTC',
            'removeGCEventIfRemovedInEspo' => $externalAccount->get('removeGoogleCalendarEventIfRemovedInEspo'),
            'dontSyncEventAttendees' => $externalAccount->get('dontSyncEventAttendees'),
            'assignDefaultTeam' => $externalAccount->get('calendarAssignDefaultTeam'),
        ];

        $this->eventManager = $this->injectableFactory->create(Event::class);

        $this->eventManager->setUserId($this->getUserId());
        $this->eventManager->setCalendarId($googleCalendarId);
        $this->eventManager->syncParams = $this->syncParams;
    }

    private function insertNewEspoEventsIntoGoogle(): void
    {
        $list = $this->getRepo()->getNewEvents(
            $this->syncParams['userId'],
            $this->syncParams['syncEntities'],
            $this->syncParams['fetchSince'],
            self::MAX_ESPO_EVENT_INSERT_COUNT
        );

        foreach ($list as $item) {
            try {
                $insertResult = $this->eventManager->insertIntoGoogle($item);
            } catch (Exception $e) {
                $GLOBALS['log']->error("Google Calendar Sync error: " . $e->getMessage());

                continue;
            }

            $this->espoEventInsertCounter += (($insertResult) ? self::SUCCESS_INCREMENT : self::FAIL_INCREMENT);
        }

        if (count($list) > 0 && $this->espoEventInsertCounter < self::MAX_ESPO_EVENT_INSERT_COUNT) {
            $this->insertNewEspoEventsIntoGoogle();
        }
    }

    private function updateEspoEventsInGoogle($withCompare = false): void
    {
        $events = $this->getRepo()->getEvents(
            $this->syncParams['userId'],
            $this->syncParams['syncEntities'],
            $this->syncParams['startDate'],
            $this->syncParams['startSyncTime'],
            $this->syncParams['lastUpdatedId'],
            $this->syncParams['googleCalendarId'],
            self::MAX_ESPO_EVENT_UPDATE_COUNT
        );

        $lastDate = null;
        $id = null;

        foreach ($events as $event) {
            $updateResult = $this->eventManager->updateGoogleEvent($event, $withCompare);

            $this->espoEventUpdateCounter += (($updateResult) ? self::SUCCESS_INCREMENT : self::FAIL_INCREMENT);

            $lastDate = (!empty($event['modifiedAt'])) ? $event['modifiedAt'] : $event['createdAt'];
            $id = $event['id'];
        }

        if ($lastDate && $id) {
            $this->syncParams['calendar']->set('lastSync', $lastDate . '_' . $id);

            try {
                $this->entityManager->saveEntity($this->syncParams['calendar']);
            } catch (Exception $e) {
                $GLOBALS['log']->info("Google Calendar Synchronization: Updating lastSync is failed. ($lastDate)");
            }
        }

        if (
            count($events) == self::MAX_ESPO_EVENT_UPDATE_COUNT &&
            $this->espoEventUpdateCounter < self::MAX_ESPO_EVENT_UPDATE_COUNT
        ) {
            $this->updateEspoEventsInGoogle($withCompare);
        }
    }

    private function loadGoogleEvents($withCompare = false): void
    {
        $syncToken = $this->syncParams['calendar']->get('syncToken');
        $pageToken = $this->syncParams['calendar']->get('pageToken');
        $fetchSince = $this->syncParams['fetchSince'];

        $params = [];

        if (empty($syncToken) && empty($pageToken) && !empty($fetchSince)) {
            try {
                $timeMin = new DateTime($fetchSince . " 00:00:00", new DateTimeZone($this->syncParams['userTimeZone']));
            } catch (Exception $e) {
                throw new RuntimeException($e->getMessage());
            }

            $params['timeMin'] = $timeMin->format("Y-m-d\TH:i:s\Z");
        }

        if (!empty($syncToken)) {
            $params['syncToken'] = $syncToken;
        }

        if (!empty($pageToken)) {
            $params['pageToken'] = $pageToken;
        }

        $result = $this->eventManager->getEventList($params);

        if (empty($result) || !is_array($result)) {
            return;
        }

        if (isset($result['success']) && $result['success'] === false) {
            if (isset($result['action']) && $result['action'] == 'resetToken') {
                $toSave = false;

                if (!empty($pageToken)) {
                    $this->syncParams['calendar']->set('pageToken', '');

                    $toSave = true;
                }

                if (empty($pageToken) && !empty($syncToken)) {
                    $this->syncParams['calendar']->set('syncToken', '');

                    $toSave = true;
                }

                if ($toSave) {
                    $this->entityManager->saveEntity($this->syncParams['calendar']);
                }
            }

            return;
        }

        foreach ($result['items'] as $item) {
            try {
                $updateResult = $this->eventManager->updateEspoEvent($item, $withCompare);
            } catch (Exception $e) {
                $GLOBALS['log']->error("Google Calendar Sync error: " . $e->getMessage());

                continue;
            }

            $this->eventCounter += ($updateResult) ? self::SUCCESS_INCREMENT : self::FAIL_INCREMENT;
        }

        if (isset($result['nextPageToken'])) {
            $this->syncParams['calendar']->set('pageToken', $result['nextPageToken']);

            $this->entityManager->saveEntity($this->syncParams['calendar']);

            if ($this->eventCounter < self::MAX_EVENT_COUNT) {
                $this->loadGoogleEvents($withCompare);
            }
        } else if (isset($result['nextSyncToken'])) {
            $this->syncParams['calendar']->set('pageToken', '');
            $this->syncParams['calendar']->set('syncToken', $result['nextSyncToken']);

            $this->entityManager->saveEntity($this->syncParams['calendar']);
        }
    }

    private function loadRecurrentGoogleEvents($withCompare = false): void
    {
        $recurrentEvent = $this->eventManager->getRecurrentEventFromQueue();

        if (empty($recurrentEvent)) {
            return;
        }

        $pageToken = $recurrentEvent['pageToken'];
        $params = [];

        if (!empty($pageToken)) {
            $params['pageToken'] = $pageToken;
        }

        try {
            $result = $this->eventManager->getEventInstances($recurrentEvent['eventId'], $params);

            if (isset($result['success']) && $result['success'] === false) {
                if (isset($result['action'])) {
                    if ($result['action'] == 'resetToken') {
                        if (!empty($pageToken)) {
                            $this->eventManager->updateRecurrentEvent($recurrentEvent['id']);
                        } else {
                            $this->eventManager->removeRecurrentEventFromQueue($recurrentEvent['id']);
                        }

                        throw new RuntimeException("Reset pageToken for recurrent event {$recurrentEvent['id']}");
                    } else if ($result['action'] == 'deleteEvent') {
                        $this->eventManager->removeRecurrentEventFromQueue($recurrentEvent['id']);

                        throw new RuntimeException("Delete recurrent event {$recurrentEvent['id']} from queue");
                    }
                } else {
                    throw new RuntimeException("Sync for Recurrent event {$recurrentEvent['id']} is failed");
                }
            }

            $lastId = '';

            if (!isset($result['items']) || !is_array($result['items'])) {
                throw new RuntimeException("Recurrent event {$recurrentEvent['id']} instances are not loaded");
            }

            foreach ($result['items'] as $item) {
                // iCalUID is the same for recurring events.
                unset($item['iCalUID']);

                $updateResult = $this->eventManager->updateEspoEvent($item, $withCompare);

                $this->recurrentEventCounter += ($updateResult) ? self::SUCCESS_INCREMENT : self::FAIL_INCREMENT;

                $lastId = $item['id'];
            }

            if (isset($result['nextPageToken'])) {
                $lastIdArr = explode('_', $lastId);
                $lastDateStr = $recurrentEvent['lastEventTime'];

                if (is_array($lastIdArr) && !empty($lastIdArr[count($lastIdArr) - 1])) {
                    try {
                        $lastDate = new DateTime($lastIdArr[count($lastIdArr) - 1]);

                        $lastDateStr = $lastDate->format('Y-m-d H:i:s');
                    } catch (Exception $e) {
                        $GLOBALS['log']
                            ->error(
                                'Google Calendar Synchronization: Last recurrent id is ' . $lastId . ". " .
                                $e->getMessage()
                            );
                    }
                }

                $this->eventManager
                    ->updateRecurrentEvent($recurrentEvent['id'], $result['nextPageToken'], $lastDateStr);
            } else if (isset($result['nextSyncToken'])) {
                $this->eventManager->removeRecurrentEventFromQueue($recurrentEvent['id']);
            }
        } catch (Exception $e) {
            $GLOBALS['log']->error('Google Calendar Synchronization: ' . $e->getMessage());
        }

        if ($this->recurrentEventCounter < self::MAX_RECURRENT_EVENT_COUNT) {
            $this->loadRecurrentGoogleEvents($withCompare);
        }
    }

    private function twoWaySync(): void
    {
        $this->loadGoogleEvents(true);
        $this->loadRecurrentGoogleEvents(true);
        $this->updateEspoEventsInGoogle(true);
    }

    private function syncEspoToGC(): void
    {
        $this->updateEspoEventsInGoogle();
        $this->insertNewEspoEventsIntoGoogle();
    }

    private function syncGCToEspo(): void
    {
        $this->loadGoogleEvents();
        $this->loadRecurrentGoogleEvents();
    }

    private function syncBoth(): void
    {
        if ($this->syncParams['isMain'] || !$this->syncParams['isInMain']) {
            $this->twoWaySync();
        }
        else if (!$this->syncParams['isMain'] && $this->syncParams['isInMain']) {
            $mainCalendar = $this->getRepo()
                ->getUsersMainCalendar($this->syncParams['userId']);

            if (!empty($mainCalendar)) {
                $this->syncParams['calendar']->set('syncToken', $mainCalendar->get('syncToken'));
                $this->syncParams['calendar']->set('pageToken', $mainCalendar->get('pageToken'));

                $this->entityManager->saveEntity($this->syncParams['calendar']);
            }
        }

        if ($this->syncParams['isMain']) {
            $this->insertNewEspoEventsIntoGoogle();
        }
    }

    /**
     * @param Entity $calendar
     * @param ExternalAccount $externalAccount
     */
    public function run($calendar, $externalAccount): bool
    {
        if (!$this->acl->checkScope('GoogleCalendar')) {
            $GLOBALS['log']
                ->info("Google Calendar Synchronization: Access Forbidden for user {$calendar->get('userId')}");

            return false;
        }

        try {
            $this->prepareData($calendar, $externalAccount);

            $direction = $this->syncParams['direction'] ?? null;

            $this->syncParams['calendar']->set('lastLooked', $this->syncParams['startSyncTime']);

            $this->entityManager->saveEntity($this->syncParams['calendar']);

            if ($direction === 'EspoToGC') {
                $this->syncEspoToGC();
            } else if ($direction === 'GCToEspo') {
                $this->syncGCToEspo();
            } else if ($direction === 'Both') {
                $this->syncBoth();
            }

            return true;
        } catch (Exception $e) {
            $GLOBALS['log']
                ->error(
                    "Google Calendar Synchronization: Error when calendar synchronization is running. ".
                    "GoogleCalendarUser Id {$calendar->get('id')}. Message: {$e->getMessage()}"
                );
        }

        return false;
    }

    private function getRepo(): GoogleCalendar
    {
        /** @var GoogleCalendar  */
        return $this->entityManager->getRepository('GoogleCalendar');
    }
}
