<?php

namespace Espo\Modules\PackEnterprise\Core\GoogleCalendar\Actions;

use Espo\Modules\PackEnterprise\Repositories\MsxGoogleCalendar;
use Espo\ORM\Entity;

use DateTime;
use DateTimeZone;
use Exception;
use RuntimeException;

/**
 * Main sync orchestrator for Google Calendar.
 * Adapted from Espo\Modules\Google\Core\Google\Actions\Calendar.
 * Uses MsxGoogleCalendarUser (which contains both sync config and oAuthAccountId)
 * instead of separate ($calendar, $externalAccount) parameters.
 */
class CalendarSync extends Base
{
    const MAX_EVENT_COUNT = 20;
    const MAX_ESPO_EVENT_INSERT_COUNT = 20;
    const MAX_ESPO_EVENT_UPDATE_COUNT = 20;
    const MAX_RECURRENT_EVENT_COUNT = 20;

    const SUCCESS_INCREMENT = 1;
    const FAIL_INCREMENT = 0.5;

    /** @var array<string, mixed> */
    private array $syncParams = [];
    private ?EventSync $eventManager = null;

    private float $eventCounter = 0;
    private float $recurrentEventCounter = 0;
    private float $espoEventInsertCounter = 0;
    private float $espoEventUpdateCounter = 0;

    /**
     * @return array<string, string>
     */
    public function getCalendarList(array $params = []): array
    {
        $lists = [];

        $client = $this->getClient();

        try {
            $response = $client->getCalendarList($params);
        } catch (RuntimeException $e) {
            if ($e->getCode() === 401) {
                $this->log->info('MsxGoogleCalendar: Got 401, refreshing token and retrying.');

                $client = $this->refreshClient();
                $response = $client->getCalendarList($params);
            } else {
                throw $e;
            }
        }

        if (is_array($response) && isset($response['items'])) {
            foreach ($response['items'] as $item) {
                $lists[$item['id']] = $item['summary'];
            }

            if (isset($response['nextPageToken'])) {
                $params['pageToken'] = $response['nextPageToken'];

                $lists = array_merge($lists, $this->getCalendarList($params));
            }
        }

        return $lists;
    }

    private function resetCounters(): void
    {
        $this->eventCounter = 0;
        $this->recurrentEventCounter = 0;
        $this->espoEventInsertCounter = 0;
        $this->espoEventUpdateCounter = 0;
    }

    /**
     * Prepare sync parameters from the MsxGoogleCalendarUser entity.
     * In the original Google module, this took ($calendar, $externalAccount).
     * Here, $calendarUser contains both sync config and references to the calendar.
     */
    private function prepareData(Entity $calendarUser): void
    {
        $this->resetCounters();

        $integrationStartDate = $calendarUser->get('calendarStartDate');
        $lastSync = $calendarUser->get('lastSync');
        $lastSyncArr = explode('_', $lastSync ?? '');
        $lastSyncTime = $lastSyncArr[0] ?? '';
        $lastSyncId = $lastSyncArr[1] ?? '';
        $startDate = (!empty($lastSyncTime) && $lastSyncTime > $integrationStartDate) ?
            $lastSyncTime :
            $integrationStartDate;

        try {
            $startSyncTime = new DateTime('now');
        } catch (Exception $e) {
            throw new RuntimeException($e->getMessage());
        }

        $entityLabels = [];
        $syncEntities = [];
        $syncEntitiesTMP = $calendarUser->get('calendarEntityTypes');

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
            $label = $calendarUser->get($entity . 'IdentificationLabel');

            if (empty($label)) {
                $entityLabels[$entity] = $label;
            } else {
                $entityLabels = [$entity => $label] + $entityLabels;
            }
        }

        $msxGoogleCalendar = $this->entityManager
            ->getRDBRepository($calendarUser->getEntityType())
            ->getRelation($calendarUser, 'msxGoogleCalendar')
            ->findOne();

        if (!$msxGoogleCalendar) {
            throw new RuntimeException("Cannot load calendar for user {$calendarUser->get('userId')}.");
        }

        $googleCalendarId = $msxGoogleCalendar->get('calendarId');
        $isMain = ($calendarUser->get('type') == 'main');

        $calendarInfo = $this->getClient()->getCalendarInfo($googleCalendarId);

        $googleTimeZone = (!empty($calendarInfo) && isset($calendarInfo['timeZone'])) ?
            $calendarInfo['timeZone'] : 'UTC';

        $userPreference = $this->entityManager->getEntityById('Preferences', $calendarUser->get('userId'));
        $userTimeZone = $userPreference ? $userPreference->get('timeZone') : 'UTC';

        $defaultEntity = (
            in_array(
                $calendarUser->get('calendarDefaultEntity'), $syncEntities)
            ) ?
            $calendarUser->get('calendarDefaultEntity') :
            '';

        $this->syncParams = [
            'fetchSince' => $integrationStartDate,
            'startDate' => $startDate,
            'lastUpdatedId' => $lastSyncId,
            'syncEntities' => $syncEntities,
            'entityLabels' => $entityLabels,
            'userId' => $calendarUser->get('userId'),
            'googleCalendarId' => $googleCalendarId,
            'direction' => $calendarUser->get('calendarDirection'),
            'defaultEntity' => $defaultEntity,
            'isMain' => $isMain,
            'isInMain' => (!$isMain && $this->isInMainCalendar($calendarUser, $googleCalendarId)),
            'calendar' => $calendarUser,
            'startSyncTime' => $startSyncTime->format('Y-m-d H:i:s'),
            'googleTimeZone' => (!empty($googleTimeZone)) ? $googleTimeZone : 'UTC',
            'userTimeZone' => (!empty($userTimeZone)) ? $userTimeZone : 'UTC',
            'removeGCEventIfRemovedInEspo' => $calendarUser->get('removeGCEventIfRemovedInEspo'),
            'dontSyncEventAttendees' => $calendarUser->get('dontSyncEventAttendees'),
            'assignDefaultTeam' => $calendarUser->get('calendarAssignDefaultTeam'),
        ];

        $this->eventManager = $this->injectableFactory->create(EventSync::class);

        $this->eventManager->setUserId($this->getUserId());
        $this->eventManager->setOAuthAccountId($this->oAuthAccountId);
        $this->eventManager->setCalendarId($googleCalendarId);
        $this->eventManager->syncParams = $this->syncParams;
    }

    /**
     * Check if the given calendar is also the main calendar for this user.
     */
    private function isInMainCalendar(Entity $calendarUser, string $googleCalendarId): bool
    {
        $mainCalendar = $this->getRepo()->getUsersMainCalendar($calendarUser->get('userId'));

        if (!$mainCalendar) {
            return false;
        }

        $mainGoogleCalendar = $this->entityManager
            ->getRDBRepository($mainCalendar->getEntityType())
            ->getRelation($mainCalendar, 'msxGoogleCalendar')
            ->findOne();

        if (!$mainGoogleCalendar) {
            return false;
        }

        return $mainGoogleCalendar->get('calendarId') === $googleCalendarId;
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
                $this->log->error("MsxGoogleCalendar Sync error: " . $e->getMessage());

                continue;
            }

            $this->espoEventInsertCounter += ($insertResult) ? self::SUCCESS_INCREMENT : self::FAIL_INCREMENT;
        }

        if (count($list) > 0 && $this->espoEventInsertCounter < self::MAX_ESPO_EVENT_INSERT_COUNT) {
            $this->insertNewEspoEventsIntoGoogle();
        }
    }

    private function updateEspoEventsInGoogle(bool $withCompare = false): void
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

            $this->espoEventUpdateCounter += ($updateResult) ? self::SUCCESS_INCREMENT : self::FAIL_INCREMENT;

            $lastDate = (!empty($event['modifiedAt'])) ? $event['modifiedAt'] : $event['createdAt'];
            $id = $event['id'];
        }

        if ($lastDate && $id) {
            $this->syncParams['calendar']->set('lastSync', $lastDate . '_' . $id);

            try {
                $this->entityManager->saveEntity($this->syncParams['calendar']);
            } catch (Exception $e) {
                $this->log->info("MsxGoogleCalendar Sync: Updating lastSync failed. ($lastDate)");
            }
        }

        if (
            count($events) == self::MAX_ESPO_EVENT_UPDATE_COUNT &&
            $this->espoEventUpdateCounter < self::MAX_ESPO_EVENT_UPDATE_COUNT
        ) {
            $this->updateEspoEventsInGoogle($withCompare);
        }
    }

    private function loadGoogleEvents(bool $withCompare = false): void
    {
        $syncToken = $this->syncParams['calendar']->get('syncToken');
        $pageToken = $this->syncParams['calendar']->get('pageToken');
        $fetchSince = $this->syncParams['fetchSince'];

        $params = [];

        if (empty($syncToken) && empty($pageToken) && !empty($fetchSince)) {
            try {
                $timeMin = new DateTime(
                    $fetchSince . " 00:00:00",
                    new DateTimeZone($this->syncParams['userTimeZone'])
                );
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
                $this->log->error("MsxGoogleCalendar Sync error: " . $e->getMessage());

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

    private function loadRecurrentGoogleEvents(bool $withCompare = false): void
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

                        throw new RuntimeException(
                            "Reset pageToken for recurrent event {$recurrentEvent['id']}"
                        );
                    } else if ($result['action'] == 'deleteEvent') {
                        $this->eventManager->removeRecurrentEventFromQueue($recurrentEvent['id']);

                        throw new RuntimeException(
                            "Delete recurrent event {$recurrentEvent['id']} from queue"
                        );
                    }
                } else {
                    throw new RuntimeException(
                        "Sync for Recurrent event {$recurrentEvent['id']} failed"
                    );
                }
            }

            $lastId = '';

            if (!isset($result['items']) || !is_array($result['items'])) {
                throw new RuntimeException(
                    "Recurrent event {$recurrentEvent['id']} instances are not loaded"
                );
            }

            foreach ($result['items'] as $item) {
                // iCalUID is the same for recurring events.
                unset($item['iCalUID']);

                $updateResult = $this->eventManager->updateEspoEvent($item, $withCompare);

                $this->recurrentEventCounter += ($updateResult) ?
                    self::SUCCESS_INCREMENT : self::FAIL_INCREMENT;

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
                        $this->log->error(
                            'MsxGoogleCalendar Sync: Last recurrent id is ' . $lastId .
                            '. ' . $e->getMessage()
                        );
                    }
                }

                $this->eventManager->updateRecurrentEvent(
                    $recurrentEvent['id'],
                    $result['nextPageToken'],
                    $lastDateStr
                );
            } else if (isset($result['nextSyncToken'])) {
                $this->eventManager->removeRecurrentEventFromQueue($recurrentEvent['id']);
            }
        } catch (Exception $e) {
            $this->log->error('MsxGoogleCalendar Sync: ' . $e->getMessage());
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
        } else if (!$this->syncParams['isMain'] && $this->syncParams['isInMain']) {
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
     * Run sync for a MsxGoogleCalendarUser entity.
     */
    public function run(Entity $calendarUser): bool
    {
        if (!$this->acl->checkScope('MsxGoogleCalendar')) {
            $this->log->info(
                "MsxGoogleCalendar Sync: Access Forbidden for user {$calendarUser->get('userId')}"
            );

            return false;
        }

        try {
            $this->prepareData($calendarUser);

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
            $this->log->error(
                "MsxGoogleCalendar Sync: Error when calendar synchronization is running. " .
                "MsxGoogleCalendarUser Id {$calendarUser->get('id')}. Message: {$e->getMessage()}"
            );
        }

        return false;
    }

    private function getRepo(): MsxGoogleCalendar
    {
        /** @var MsxGoogleCalendar */
        return $this->entityManager->getRepository('MsxGoogleCalendar');
    }
}
