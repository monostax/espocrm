<?php

namespace Espo\Modules\PackEnterprise\Core\GoogleCalendar\Actions;

use Espo\Modules\PackEnterprise\Repositories\MsxGoogleCalendar;
use Espo\ORM\Entity;

use DateTime;
use DateTimeZone;
use Exception;
use RuntimeException;

/**
 * Sync orchestrator for Google Calendar <-> EspoCRM bidirectional sync.
 * Adapted from Espo\Modules\Google\Core\Google\Actions\Calendar.
 *
 * Key differences from the original:
 * - Reads all settings from MsxGoogleCalendarUser entity (no ExternalAccount).
 * - Uses TokensProvider + OAuthAccount (via Base) instead of ClientManager.
 * - Uses EventSync instead of Event.
 * - run() takes a single Entity parameter (MsxGoogleCalendarUser).
 */
class CalendarSync extends Base
{
    private const MAX_EVENT_COUNT = 20;
    private const MAX_ESPO_EVENT_INSERT_COUNT = 20;
    private const MAX_ESPO_EVENT_UPDATE_COUNT = 20;
    private const MAX_RECURRENT_EVENT_COUNT = 20;

    private const SUCCESS_INCREMENT = 1;
    private const FAIL_INCREMENT = 0.5;

    /** @var array<string, mixed> */
    private array $syncParams = [];
    private ?EventSync $eventManager = null;

    private float $eventCounter = 0;
    private float $recurrentEventCounter = 0;
    private float $espoEventInsertCounter = 0;
    private float $espoEventUpdateCounter = 0;

    /**
     * Fetch the list of Google Calendars for the authenticated user.
     *
     * @param array<string, mixed> $params
     * @return array<string, string> Map of calendarId => summary.
     */
    public function getCalendarList(array $params = []): array
    {
        $this->log->debug("MsxGoogleCalendar [CalendarSync/getCalendarList]: START params=" . json_encode($params));

        $lists = [];

        $client = $this->getClient();
        $response = $client->getCalendarList($params);

        if (is_array($response) && isset($response['items'])) {
            foreach ($response['items'] as $item) {
                $lists[$item['id']] = $item['summary'];
            }

            $this->log->debug("MsxGoogleCalendar [CalendarSync/getCalendarList]: Found " . count($response['items']) . " calendars");

            if (isset($response['nextPageToken'])) {
                $this->log->debug("MsxGoogleCalendar [CalendarSync/getCalendarList]: Has nextPageToken, fetching next page");
                $params['pageToken'] = $response['nextPageToken'];
                $lists = array_merge($lists, $this->getCalendarList($params));
            }
        } else {
            $this->log->debug("MsxGoogleCalendar [CalendarSync/getCalendarList]: No items in response, raw=" . json_encode($response));
        }

        $this->log->debug("MsxGoogleCalendar [CalendarSync/getCalendarList]: Returning " . count($lists) . " calendars total");

        return $lists;
    }

    /**
     * Run the sync for a given MsxGoogleCalendarUser record.
     */
    public function run(Entity $calendarUser): bool
    {
        $calendarUserId = $calendarUser->get('id');
        $userId = $calendarUser->get('userId');

        $this->log->debug("MsxGoogleCalendar [CalendarSync/run]: START calendarUserId={$calendarUserId}, userId={$userId}");

        if (!$this->acl->checkScope('MsxGoogleCalendar')) {
            $this->log->info(
                "MsxGoogleCalendar [CalendarSync/run]: BAIL - ACL check failed for scope 'MsxGoogleCalendar', userId={$userId}"
            );

            return false;
        }

        $this->log->debug("MsxGoogleCalendar [CalendarSync/run]: ACL check passed");

        try {
            $this->prepareData($calendarUser);

            $direction = $this->syncParams['direction'] ?? null;

            $this->log->debug("MsxGoogleCalendar [CalendarSync/run]: direction={$direction}, " .
                "googleCalendarId=" . ($this->syncParams['googleCalendarId'] ?? 'NULL') . ", " .
                "isMain=" . var_export($this->syncParams['isMain'] ?? null, true) . ", " .
                "isInMain=" . var_export($this->syncParams['isInMain'] ?? null, true) . ", " .
                "syncEntities=" . json_encode($this->syncParams['syncEntities'] ?? []) . ", " .
                "fetchSince=" . ($this->syncParams['fetchSince'] ?? 'NULL') . ", " .
                "startDate=" . ($this->syncParams['startDate'] ?? 'NULL'));

            $this->syncParams['calendar']->set('lastLooked', $this->syncParams['startSyncTime']);

            $this->entityManager->saveEntity($this->syncParams['calendar']);

            if ($direction === 'EspoToGC') {
                $this->log->debug("MsxGoogleCalendar [CalendarSync/run]: Dispatching syncEspoToGC");
                $this->syncEspoToGC();
            } elseif ($direction === 'GCToEspo') {
                $this->log->debug("MsxGoogleCalendar [CalendarSync/run]: Dispatching syncGCToEspo");
                $this->syncGCToEspo();
            } elseif ($direction === 'Both') {
                $this->log->debug("MsxGoogleCalendar [CalendarSync/run]: Dispatching syncBoth");
                $this->syncBoth();
            } else {
                $this->log->warning("MsxGoogleCalendar [CalendarSync/run]: Unknown or null direction='{$direction}', no sync performed");
            }

            $this->log->debug("MsxGoogleCalendar [CalendarSync/run]: DONE calendarUserId={$calendarUserId}");

            return true;
        } catch (Exception $e) {
            $this->log->error(
                "MsxGoogleCalendar [CalendarSync/run]: EXCEPTION during sync. " .
                "MsxGoogleCalendarUser Id {$calendarUserId}. Message: {$e->getMessage()}"
            );
        }

        return false;
    }

    private function resetCounters(): void
    {
        $this->eventCounter = 0;
        $this->recurrentEventCounter = 0;
        $this->espoEventInsertCounter = 0;
        $this->espoEventUpdateCounter = 0;
    }

    /**
     * Build sync parameters from the MsxGoogleCalendarUser entity.
     */
    private function prepareData(Entity $calendarUser): void
    {
        $calendarUserId = $calendarUser->get('id');

        $this->log->debug("MsxGoogleCalendar [CalendarSync/prepareData]: START calendarUserId={$calendarUserId}");

        $this->resetCounters();

        $integrationStartDate = $calendarUser->get('calendarStartDate');
        $lastSync = $calendarUser->get('lastSync') ?? '';
        $lastSyncArr = explode('_', $lastSync);
        $lastSyncTime = $lastSyncArr[0] ?? '';
        $lastSyncId = $lastSyncArr[1] ?? '';
        $startDate = (!empty($lastSyncTime) && $lastSyncTime > $integrationStartDate)
            ? $lastSyncTime
            : $integrationStartDate;

        $this->log->debug("MsxGoogleCalendar [CalendarSync/prepareData]: " .
            "integrationStartDate=" . ($integrationStartDate ?? 'NULL') .
            ", lastSync=" . ($lastSync ?: 'EMPTY') .
            ", lastSyncTime=" . ($lastSyncTime ?: 'EMPTY') .
            ", lastSyncId=" . ($lastSyncId ?: 'EMPTY') .
            ", startDate=" . ($startDate ?? 'NULL'));

        try {
            $startSyncTime = new DateTime('now');
        } catch (Exception $e) {
            throw new RuntimeException($e->getMessage());
        }

        // Build the list of entity types allowed for sync, filtered by ACL.
        $entityLabels = [];
        $syncEntities = [];
        $syncEntitiesTMP = $calendarUser->get('calendarEntityTypes');

        $this->log->debug("MsxGoogleCalendar [CalendarSync/prepareData]: calendarEntityTypes raw=" . json_encode($syncEntitiesTMP));

        if (is_array($syncEntitiesTMP)) {
            foreach ($syncEntitiesTMP as $syncEntity) {
                $aclResult = $this->acl->check($syncEntity, 'read');
                $this->log->debug("MsxGoogleCalendar [CalendarSync/prepareData]: ACL check entity={$syncEntity}, read=" . var_export($aclResult, true));

                if ($aclResult) {
                    $syncEntities[] = $syncEntity;
                }
            }
        }

        if (empty($syncEntities)) {
            $this->log->error("MsxGoogleCalendar [CalendarSync/prepareData]: No allowed entity types after ACL filter");
            throw new RuntimeException("No allowed entity type for sync.");
        }

        $this->log->debug("MsxGoogleCalendar [CalendarSync/prepareData]: syncEntities=" . json_encode($syncEntities));

        // Build entity labels map: entities with labels come first, label-less entities last.
        // Labels are stored as {EntityType}IdentificationLabel attributes on the entity.
        foreach ($syncEntities as $entity) {
            $label = $calendarUser->get($entity . 'IdentificationLabel');

            $this->log->debug("MsxGoogleCalendar [CalendarSync/prepareData]: Label for {$entity}=" . var_export($label, true));

            if (empty($label)) {
                $entityLabels[$entity] = $label;
            } else {
                $entityLabels = [$entity => $label] + $entityLabels;
            }
        }

        $this->log->debug("MsxGoogleCalendar [CalendarSync/prepareData]: entityLabels=" . json_encode($entityLabels));

        // Resolve the linked MsxGoogleCalendar entity to get the Google Calendar ID.
        $msxGoogleCalendarId = $calendarUser->get('msxGoogleCalendarId');
        $this->log->debug("MsxGoogleCalendar [CalendarSync/prepareData]: msxGoogleCalendarId on entity=" . ($msxGoogleCalendarId ?? 'NULL'));

        $msxGoogleCalendar = $this->entityManager
            ->getRDBRepository('MsxGoogleCalendarUser')
            ->getRelation($calendarUser, 'msxGoogleCalendar')
            ->findOne();

        if (!$msxGoogleCalendar) {
            $this->log->debug("MsxGoogleCalendar [CalendarSync/prepareData]: msxGoogleCalendar relation not found, trying fallback via calendarMainCalendarId");

            // Try to find the MsxGoogleCalendar by the Google Calendar ID stored on the entity.
            $mainCalendarId = $calendarUser->get('calendarMainCalendarId');
            $this->log->debug("MsxGoogleCalendar [CalendarSync/prepareData]: calendarMainCalendarId=" . ($mainCalendarId ?? 'NULL'));

            if ($mainCalendarId) {
                $msxGoogleCalendar = $this->getRepo()->getCalendarByGCId($mainCalendarId);
                $this->log->debug("MsxGoogleCalendar [CalendarSync/prepareData]: getCalendarByGCId result=" . ($msxGoogleCalendar ? $msxGoogleCalendar->get('id') : 'NULL'));
            }

            if (!$msxGoogleCalendar) {
                $this->log->error("MsxGoogleCalendar [CalendarSync/prepareData]: Cannot load calendar for calendarUserId={$calendarUserId}");
                throw new RuntimeException(
                    "Cannot load calendar for MsxGoogleCalendarUser {$calendarUserId}."
                );
            }

            // Link it for future lookups.
            $calendarUser->set('msxGoogleCalendarId', $msxGoogleCalendar->get('id'));
            $this->entityManager->saveEntity($calendarUser, ['silent' => true]);
            $this->log->debug("MsxGoogleCalendar [CalendarSync/prepareData]: Linked msxGoogleCalendarId=" . $msxGoogleCalendar->get('id'));
        } else {
            $this->log->debug("MsxGoogleCalendar [CalendarSync/prepareData]: msxGoogleCalendar found id=" . $msxGoogleCalendar->get('id') .
                ", calendarId=" . $msxGoogleCalendar->get('calendarId'));
        }

        $googleCalendarId = $msxGoogleCalendar->get('calendarId');
        $isMain = ($calendarUser->get('type') === 'main');

        $this->log->debug("MsxGoogleCalendar [CalendarSync/prepareData]: googleCalendarId={$googleCalendarId}, type=" . $calendarUser->get('type') . ", isMain=" . var_export($isMain, true));

        // Determine if this monitored calendar shares the same Google Calendar as the main.
        $isInMain = false;

        if (!$isMain) {
            $mainCalendarUser = $this->getRepo()->getUsersMainCalendar($calendarUser->get('userId'));

            if ($mainCalendarUser) {
                $mainMsxGoogleCalendar = $this->entityManager
                    ->getRDBRepository('MsxGoogleCalendarUser')
                    ->getRelation($mainCalendarUser, 'msxGoogleCalendar')
                    ->findOne();

                if ($mainMsxGoogleCalendar) {
                    $isInMain = ($mainMsxGoogleCalendar->get('calendarId') === $googleCalendarId);
                }

                $this->log->debug("MsxGoogleCalendar [CalendarSync/prepareData]: mainCalendarUser found, isInMain=" . var_export($isInMain, true));
            } else {
                $this->log->debug("MsxGoogleCalendar [CalendarSync/prepareData]: No main calendar user found for userId=" . $calendarUser->get('userId'));
            }
        }

        // Fetch Google Calendar timezone.
        $this->log->debug("MsxGoogleCalendar [CalendarSync/prepareData]: Fetching calendar info for googleCalendarId={$googleCalendarId}");

        $calendarInfo = $this->getClient()->getCalendarInfo($googleCalendarId);
        $googleTimeZone = (!empty($calendarInfo) && isset($calendarInfo['timeZone']))
            ? $calendarInfo['timeZone']
            : 'UTC';

        $this->log->debug("MsxGoogleCalendar [CalendarSync/prepareData]: calendarInfo result=" .
            ($calendarInfo === false ? 'FALSE' : 'OK') .
            ", googleTimeZone={$googleTimeZone}");

        // Fetch user timezone from preferences.
        $userPreference = $this->entityManager->getEntityById('Preferences', $calendarUser->get('userId'));
        $userTimeZone = $userPreference ? $userPreference->get('timeZone') : null;

        $this->log->debug("MsxGoogleCalendar [CalendarSync/prepareData]: userTimeZone=" . ($userTimeZone ?? 'NULL'));

        $defaultEntity = (
            in_array($calendarUser->get('calendarDefaultEntity'), $syncEntities)
        )
            ? $calendarUser->get('calendarDefaultEntity')
            : '';

        $this->log->debug("MsxGoogleCalendar [CalendarSync/prepareData]: " .
            "direction=" . ($calendarUser->get('calendarDirection') ?? 'NULL') .
            ", defaultEntity=" . ($defaultEntity ?: 'EMPTY') .
            ", removeGCEventIfRemovedInEspo=" . var_export($calendarUser->get('removeGCEventIfRemovedInEspo'), true) .
            ", dontSyncEventAttendees=" . var_export($calendarUser->get('dontSyncEventAttendees'), true) .
            ", assignDefaultTeam=" . var_export($calendarUser->get('calendarAssignDefaultTeam'), true));

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
            'isInMain' => $isInMain,
            'calendar' => $calendarUser,
            'startSyncTime' => $startSyncTime->format('Y-m-d H:i:s'),
            'googleTimeZone' => !empty($googleTimeZone) ? $googleTimeZone : 'UTC',
            'userTimeZone' => !empty($userTimeZone) ? $userTimeZone : 'UTC',
            'removeGCEventIfRemovedInEspo' => $calendarUser->get('removeGCEventIfRemovedInEspo'),
            'dontSyncEventAttendees' => $calendarUser->get('dontSyncEventAttendees'),
            'assignDefaultTeam' => $calendarUser->get('calendarAssignDefaultTeam'),
        ];

        // Create and configure the EventSync instance.
        $this->log->debug("MsxGoogleCalendar [CalendarSync/prepareData]: Creating EventSync instance, " .
            "userId=" . $this->getUserId() .
            ", oAuthAccountId=" . ($this->oAuthAccountId ?? 'NULL') .
            ", calendarId={$googleCalendarId}");

        $this->eventManager = $this->injectableFactory->create(EventSync::class);
        $this->eventManager->setUserId($this->getUserId());
        $this->eventManager->setOAuthAccountId($this->oAuthAccountId);
        $this->eventManager->setCalendarId($googleCalendarId);
        $this->eventManager->syncParams = $this->syncParams;

        $this->log->debug("MsxGoogleCalendar [CalendarSync/prepareData]: DONE");
    }

    /**
     * Push new Espo events to Google Calendar.
     */
    private function insertNewEspoEventsIntoGoogle(): void
    {
        $this->log->debug("MsxGoogleCalendar [CalendarSync/insertNewEspoEventsIntoGoogle]: START " .
            "userId=" . $this->syncParams['userId'] .
            ", fetchSince=" . ($this->syncParams['fetchSince'] ?? 'NULL') .
            ", counter={$this->espoEventInsertCounter}/" . self::MAX_ESPO_EVENT_INSERT_COUNT);

        $list = $this->getRepo()->getNewEvents(
            $this->syncParams['userId'],
            $this->syncParams['syncEntities'],
            $this->syncParams['fetchSince'],
            self::MAX_ESPO_EVENT_INSERT_COUNT
        );

        $this->log->debug("MsxGoogleCalendar [CalendarSync/insertNewEspoEventsIntoGoogle]: Found " . count($list) . " new Espo events to push");

        foreach ($list as $item) {
            $this->log->debug("MsxGoogleCalendar [CalendarSync/insertNewEspoEventsIntoGoogle]: Inserting event scope=" .
                ($item['scope'] ?? 'NULL') . ", id=" . ($item['id'] ?? 'NULL') . ", name=" . ($item['name'] ?? 'NULL'));

            try {
                $insertResult = $this->eventManager->insertIntoGoogle($item);
            } catch (Exception $e) {
                $this->log->error("MsxGoogleCalendar [CalendarSync/insertNewEspoEventsIntoGoogle]: Insert error: " . $e->getMessage());

                continue;
            }

            $this->espoEventInsertCounter += ($insertResult) ? self::SUCCESS_INCREMENT : self::FAIL_INCREMENT;

            $this->log->debug("MsxGoogleCalendar [CalendarSync/insertNewEspoEventsIntoGoogle]: Insert result=" .
                var_export($insertResult, true) . ", counter={$this->espoEventInsertCounter}");
        }

        if (count($list) > 0 && $this->espoEventInsertCounter < self::MAX_ESPO_EVENT_INSERT_COUNT) {
            $this->log->debug("MsxGoogleCalendar [CalendarSync/insertNewEspoEventsIntoGoogle]: More events to process, recursing");
            $this->insertNewEspoEventsIntoGoogle();
        }
    }

    /**
     * Push modified Espo events to Google Calendar.
     */
    private function updateEspoEventsInGoogle(bool $withCompare = false): void
    {
        $this->log->debug("MsxGoogleCalendar [CalendarSync/updateEspoEventsInGoogle]: START " .
            "withCompare=" . var_export($withCompare, true) .
            ", startDate=" . ($this->syncParams['startDate'] ?? 'NULL') .
            ", startSyncTime=" . ($this->syncParams['startSyncTime'] ?? 'NULL') .
            ", lastUpdatedId=" . ($this->syncParams['lastUpdatedId'] ?? 'EMPTY') .
            ", counter={$this->espoEventUpdateCounter}/" . self::MAX_ESPO_EVENT_UPDATE_COUNT);

        $events = $this->getRepo()->getEvents(
            $this->syncParams['userId'],
            $this->syncParams['syncEntities'],
            $this->syncParams['startDate'],
            $this->syncParams['startSyncTime'],
            $this->syncParams['lastUpdatedId'],
            $this->syncParams['googleCalendarId'],
            self::MAX_ESPO_EVENT_UPDATE_COUNT
        );

        $this->log->debug("MsxGoogleCalendar [CalendarSync/updateEspoEventsInGoogle]: Found " . count($events) . " modified Espo events to push");

        $lastDate = null;
        $id = null;

        foreach ($events as $event) {
            $this->log->debug("MsxGoogleCalendar [CalendarSync/updateEspoEventsInGoogle]: Updating Google event scope=" .
                ($event['scope'] ?? 'NULL') . ", id=" . ($event['id'] ?? 'NULL') .
                ", gcEventId=" . ($event['msxGoogleCalendarEventId'] ?? 'NULL'));

            $updateResult = $this->eventManager->updateGoogleEvent($event, $withCompare);

            $this->espoEventUpdateCounter += ($updateResult) ? self::SUCCESS_INCREMENT : self::FAIL_INCREMENT;

            $this->log->debug("MsxGoogleCalendar [CalendarSync/updateEspoEventsInGoogle]: Update result=" .
                var_export($updateResult, true) . ", counter={$this->espoEventUpdateCounter}");

            $lastDate = !empty($event['modifiedAt']) ? $event['modifiedAt'] : ($event['createdAt'] ?? null);
            $id = $event['id'];
        }

        if ($lastDate && $id) {
            $newLastSync = $lastDate . '_' . $id;
            $this->log->debug("MsxGoogleCalendar [CalendarSync/updateEspoEventsInGoogle]: Saving lastSync={$newLastSync}");

            $this->syncParams['calendar']->set('lastSync', $newLastSync);

            try {
                $this->entityManager->saveEntity($this->syncParams['calendar']);
            } catch (Exception $e) {
                $this->log->info(
                    "MsxGoogleCalendar [CalendarSync/updateEspoEventsInGoogle]: Updating lastSync failed. ({$lastDate})"
                );
            }
        }

        if (
            count($events) == self::MAX_ESPO_EVENT_UPDATE_COUNT &&
            $this->espoEventUpdateCounter < self::MAX_ESPO_EVENT_UPDATE_COUNT
        ) {
            $this->log->debug("MsxGoogleCalendar [CalendarSync/updateEspoEventsInGoogle]: More events to process, recursing");
            $this->updateEspoEventsInGoogle($withCompare);
        }
    }

    /**
     * Fetch events from Google Calendar and create/update them in Espo.
     */
    private function loadGoogleEvents(bool $withCompare = false): void
    {
        $syncToken = $this->syncParams['calendar']->get('syncToken');
        $pageToken = $this->syncParams['calendar']->get('pageToken');
        $fetchSince = $this->syncParams['fetchSince'];

        $this->log->debug("MsxGoogleCalendar [CalendarSync/loadGoogleEvents]: START " .
            "withCompare=" . var_export($withCompare, true) .
            ", syncToken=" . ($syncToken ?: 'EMPTY') .
            ", pageToken=" . ($pageToken ?: 'EMPTY') .
            ", fetchSince=" . ($fetchSince ?? 'NULL') .
            ", counter={$this->eventCounter}/" . self::MAX_EVENT_COUNT);

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
            $this->log->debug("MsxGoogleCalendar [CalendarSync/loadGoogleEvents]: Using timeMin=" . $params['timeMin']);
        }

        if (!empty($syncToken)) {
            $params['syncToken'] = $syncToken;
        }

        if (!empty($pageToken)) {
            $params['pageToken'] = $pageToken;
        }

        $this->log->debug("MsxGoogleCalendar [CalendarSync/loadGoogleEvents]: Calling getEventList with params=" . json_encode($params));

        $result = $this->eventManager->getEventList($params);

        if (empty($result) || !is_array($result)) {
            $this->log->debug("MsxGoogleCalendar [CalendarSync/loadGoogleEvents]: BAIL - Empty or non-array result");
            return;
        }

        if (isset($result['success']) && $result['success'] === false) {
            $this->log->debug("MsxGoogleCalendar [CalendarSync/loadGoogleEvents]: API returned success=false, action=" . ($result['action'] ?? 'NONE'));

            if (isset($result['action']) && $result['action'] === 'resetToken') {
                $toSave = false;

                if (!empty($pageToken)) {
                    $this->log->debug("MsxGoogleCalendar [CalendarSync/loadGoogleEvents]: Resetting pageToken");
                    $this->syncParams['calendar']->set('pageToken', '');
                    $toSave = true;
                }

                if (empty($pageToken) && !empty($syncToken)) {
                    $this->log->debug("MsxGoogleCalendar [CalendarSync/loadGoogleEvents]: Resetting syncToken");
                    $this->syncParams['calendar']->set('syncToken', '');
                    $toSave = true;
                }

                if ($toSave) {
                    $this->entityManager->saveEntity($this->syncParams['calendar']);
                }
            }

            return;
        }

        $itemCount = isset($result['items']) && is_array($result['items']) ? count($result['items']) : 0;
        $this->log->debug("MsxGoogleCalendar [CalendarSync/loadGoogleEvents]: Received {$itemCount} events from Google");

        if (isset($result['items']) && is_array($result['items'])) {
            foreach ($result['items'] as $item) {
                $eventId = $item['id'] ?? 'N/A';
                $summary = $item['summary'] ?? '(no summary)';
                $this->log->debug("MsxGoogleCalendar [CalendarSync/loadGoogleEvents]: Processing Google event id={$eventId}, summary={$summary}");

                try {
                    $updateResult = $this->eventManager->updateEspoEvent($item, $withCompare);
                } catch (Exception $e) {
                    $this->log->error("MsxGoogleCalendar [CalendarSync/loadGoogleEvents]: updateEspoEvent error for id={$eventId}: " . $e->getMessage());

                    continue;
                }

                $this->eventCounter += ($updateResult) ? self::SUCCESS_INCREMENT : self::FAIL_INCREMENT;
            }
        }

        if (isset($result['nextPageToken'])) {
            $this->log->debug("MsxGoogleCalendar [CalendarSync/loadGoogleEvents]: Has nextPageToken, saving and continuing. counter={$this->eventCounter}");

            $this->syncParams['calendar']->set('pageToken', $result['nextPageToken']);

            $this->entityManager->saveEntity($this->syncParams['calendar']);

            if ($this->eventCounter < self::MAX_EVENT_COUNT) {
                $this->loadGoogleEvents($withCompare);
            } else {
                $this->log->debug("MsxGoogleCalendar [CalendarSync/loadGoogleEvents]: MAX_EVENT_COUNT reached, stopping pagination");
            }
        } elseif (isset($result['nextSyncToken'])) {
            $this->log->debug("MsxGoogleCalendar [CalendarSync/loadGoogleEvents]: Got nextSyncToken, saving. Sync cycle complete for this calendar.");

            $this->syncParams['calendar']->set('pageToken', '');
            $this->syncParams['calendar']->set('syncToken', $result['nextSyncToken']);

            $this->entityManager->saveEntity($this->syncParams['calendar']);
        } else {
            $this->log->debug("MsxGoogleCalendar [CalendarSync/loadGoogleEvents]: No nextPageToken or nextSyncToken in response");
        }
    }

    /**
     * Process recurring events from the queue.
     */
    private function loadRecurrentGoogleEvents(bool $withCompare = false): void
    {
        $this->log->debug("MsxGoogleCalendar [CalendarSync/loadRecurrentGoogleEvents]: START " .
            "withCompare=" . var_export($withCompare, true) .
            ", counter={$this->recurrentEventCounter}/" . self::MAX_RECURRENT_EVENT_COUNT);

        $recurrentEvent = $this->eventManager->getRecurrentEventFromQueue();

        if (empty($recurrentEvent)) {
            $this->log->debug("MsxGoogleCalendar [CalendarSync/loadRecurrentGoogleEvents]: No recurrent events in queue");
            return;
        }

        $this->log->debug("MsxGoogleCalendar [CalendarSync/loadRecurrentGoogleEvents]: Processing queue item id=" .
            ($recurrentEvent['id'] ?? 'NULL') . ", eventId=" . ($recurrentEvent['eventId'] ?? 'NULL'));

        $pageToken = $recurrentEvent['pageToken'];
        $params = [];

        if (!empty($pageToken)) {
            $params['pageToken'] = $pageToken;
        }

        try {
            $result = $this->eventManager->getEventInstances($recurrentEvent['eventId'], $params);

            if (isset($result['success']) && $result['success'] === false) {
                $this->log->debug("MsxGoogleCalendar [CalendarSync/loadRecurrentGoogleEvents]: API error, action=" . ($result['action'] ?? 'NONE'));

                if (isset($result['action'])) {
                    if ($result['action'] === 'resetToken') {
                        if (!empty($pageToken)) {
                            $this->eventManager->updateRecurrentEvent($recurrentEvent['id']);
                        } else {
                            $this->eventManager->removeRecurrentEventFromQueue($recurrentEvent['id']);
                        }

                        throw new RuntimeException(
                            "Reset pageToken for recurrent event {$recurrentEvent['id']}"
                        );
                    } elseif ($result['action'] === 'deleteEvent') {
                        $this->eventManager->removeRecurrentEventFromQueue($recurrentEvent['id']);

                        throw new RuntimeException(
                            "Delete recurrent event {$recurrentEvent['id']} from queue"
                        );
                    }
                } else {
                    throw new RuntimeException(
                        "Sync for recurrent event {$recurrentEvent['id']} failed"
                    );
                }
            }

            $lastId = '';

            if (!isset($result['items']) || !is_array($result['items'])) {
                throw new RuntimeException(
                    "Recurrent event {$recurrentEvent['id']} instances are not loaded"
                );
            }

            $instanceCount = count($result['items']);
            $this->log->debug("MsxGoogleCalendar [CalendarSync/loadRecurrentGoogleEvents]: Got {$instanceCount} instances");

            foreach ($result['items'] as $item) {
                // iCalUID is the same for recurring events — remove to avoid duplicate matching.
                unset($item['iCalUID']);

                $updateResult = $this->eventManager->updateEspoEvent($item, $withCompare);

                $this->recurrentEventCounter += ($updateResult) ? self::SUCCESS_INCREMENT : self::FAIL_INCREMENT;

                $lastId = $item['id'];
            }

            if (isset($result['nextPageToken'])) {
                $lastIdArr = explode('_', $lastId);
                $lastDateStr = $recurrentEvent['lastEventTime'] ?? null;

                if (is_array($lastIdArr) && !empty($lastIdArr[count($lastIdArr) - 1])) {
                    try {
                        $lastDate = new DateTime($lastIdArr[count($lastIdArr) - 1]);
                        $lastDateStr = $lastDate->format('Y-m-d H:i:s');
                    } catch (Exception $e) {
                        $this->log->error(
                            'MsxGoogleCalendar [CalendarSync/loadRecurrentGoogleEvents]: Last recurrent id is ' . $lastId . '. ' .
                            $e->getMessage()
                        );
                    }
                }

                $this->log->debug("MsxGoogleCalendar [CalendarSync/loadRecurrentGoogleEvents]: Has nextPageToken, updating queue item");

                $this->eventManager->updateRecurrentEvent(
                    $recurrentEvent['id'],
                    $result['nextPageToken'],
                    $lastDateStr
                );
            } elseif (isset($result['nextSyncToken'])) {
                $this->log->debug("MsxGoogleCalendar [CalendarSync/loadRecurrentGoogleEvents]: Got nextSyncToken, removing from queue");
                $this->eventManager->removeRecurrentEventFromQueue($recurrentEvent['id']);
            }
        } catch (Exception $e) {
            $this->log->error('MsxGoogleCalendar [CalendarSync/loadRecurrentGoogleEvents]: ' . $e->getMessage());
        }

        if ($this->recurrentEventCounter < self::MAX_RECURRENT_EVENT_COUNT) {
            $this->loadRecurrentGoogleEvents($withCompare);
        } else {
            $this->log->debug("MsxGoogleCalendar [CalendarSync/loadRecurrentGoogleEvents]: MAX_RECURRENT_EVENT_COUNT reached");
        }
    }

    private function twoWaySync(): void
    {
        $this->log->debug("MsxGoogleCalendar [CalendarSync/twoWaySync]: START");
        $this->loadGoogleEvents(true);
        $this->loadRecurrentGoogleEvents(true);
        $this->updateEspoEventsInGoogle(true);
        $this->log->debug("MsxGoogleCalendar [CalendarSync/twoWaySync]: DONE");
    }

    private function syncEspoToGC(): void
    {
        $this->log->debug("MsxGoogleCalendar [CalendarSync/syncEspoToGC]: START");
        $this->updateEspoEventsInGoogle();
        $this->insertNewEspoEventsIntoGoogle();
        $this->log->debug("MsxGoogleCalendar [CalendarSync/syncEspoToGC]: DONE");
    }

    private function syncGCToEspo(): void
    {
        $this->log->debug("MsxGoogleCalendar [CalendarSync/syncGCToEspo]: START");
        $this->loadGoogleEvents();
        $this->loadRecurrentGoogleEvents();
        $this->log->debug("MsxGoogleCalendar [CalendarSync/syncGCToEspo]: DONE");
    }

    private function syncBoth(): void
    {
        $this->log->debug("MsxGoogleCalendar [CalendarSync/syncBoth]: START " .
            "isMain=" . var_export($this->syncParams['isMain'], true) .
            ", isInMain=" . var_export($this->syncParams['isInMain'], true));

        if ($this->syncParams['isMain'] || !$this->syncParams['isInMain']) {
            $this->log->debug("MsxGoogleCalendar [CalendarSync/syncBoth]: Running twoWaySync (isMain or not isInMain)");
            $this->twoWaySync();
        } elseif (!$this->syncParams['isMain'] && $this->syncParams['isInMain']) {
            $this->log->debug("MsxGoogleCalendar [CalendarSync/syncBoth]: isInMain=true, copying tokens from main calendar");

            $mainCalendar = $this->getRepo()
                ->getUsersMainCalendar($this->syncParams['userId']);

            if (!empty($mainCalendar)) {
                $this->log->debug("MsxGoogleCalendar [CalendarSync/syncBoth]: Copying syncToken=" .
                    ($mainCalendar->get('syncToken') ?: 'EMPTY') . ", pageToken=" .
                    ($mainCalendar->get('pageToken') ?: 'EMPTY'));

                $this->syncParams['calendar']->set('syncToken', $mainCalendar->get('syncToken'));
                $this->syncParams['calendar']->set('pageToken', $mainCalendar->get('pageToken'));

                $this->entityManager->saveEntity($this->syncParams['calendar']);
            } else {
                $this->log->debug("MsxGoogleCalendar [CalendarSync/syncBoth]: No main calendar found to copy tokens from");
            }
        }

        if ($this->syncParams['isMain']) {
            $this->log->debug("MsxGoogleCalendar [CalendarSync/syncBoth]: isMain=true, inserting new Espo events");
            $this->insertNewEspoEventsIntoGoogle();
        }

        $this->log->debug("MsxGoogleCalendar [CalendarSync/syncBoth]: DONE");
    }

    private function getRepo(): MsxGoogleCalendar
    {
        /** @var MsxGoogleCalendar */
        return $this->entityManager->getRepository('MsxGoogleCalendar');
    }
}
