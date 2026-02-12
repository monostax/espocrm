<?php

namespace Espo\Modules\PackEnterprise\Core\GoogleCalendar\Actions;

use DateTime;
use Espo\Core\Acl\Table;
use Espo\Modules\Crm\Entities\Meeting;
use Espo\Modules\PackEnterprise\Core\GoogleCalendar\Client\GoogleCalendarClient;
use Espo\Modules\PackEnterprise\Core\GoogleCalendar\Items\CalendarEvent;
use Espo\Modules\PackEnterprise\Repositories\MsxGoogleCalendar;
use Espo\ORM\Entity;
use Espo\Repositories\EmailAddress;
use Exception;
use RuntimeException;
use stdClass;

/**
 * Event-level sync operations for Google Calendar.
 * Adapted from Espo\Modules\Google\Core\Google\Actions\Event.
 */
class EventSync extends Base
{
    public string $calendarId = '';

    /** @var array<string, mixed> */
    public array $syncParams = [];

    /** @var array<string, string> */
    private array $googleEspoPairs = [
        'summary' => 'name',
        'start' => 'dateStart',
        'end' => 'dateEnd',
        'description' => 'description',
        'startDate' => 'dateStartDate',
        'endDate' => 'dateEndDate',
        'location' => 'location',
        'iCalUID' => 'uid',
    ];

    /** @var array<string, string> */
    private array $statusPairs = [
        'needsAction' => 'None',
        'accepted' => 'Accepted',
        'tentative' => 'Tentative',
        'declined' => 'Declined',
    ];

    /**
     * @return array<string, string>
     */
    public function getFieldPairs(string $entityType): array
    {
        $pairs = $this->googleEspoPairs;

        if ($this->hasFieldTextVarchar($entityType, 'cLocation')) {
            $pairs['location'] = 'cLocation';
        }

        return $pairs;
    }

    private function hasFieldTextVarchar(string $entityType, string $field): bool
    {
        $has = in_array($this->metadata->get(['entityDefs', $entityType, 'fields', $field, 'type']), [
            'varchar',
            'text',
        ]);

        if (!$has) {
            return false;
        }

        return !$this->metadata->get(['entityDefs', $entityType, 'fields', $field, 'notStorable']);
    }

    public function getCalendarId(): string
    {
        return $this->calendarId;
    }

    public function setCalendarId(string $calendarId): void
    {
        $this->calendarId = $calendarId;
    }

    private function getMsxGoogleCalendarRepository(): MsxGoogleCalendar
    {
        /** @var MsxGoogleCalendar */
        return $this->entityManager->getRepository('MsxGoogleCalendar');
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getEventList(array $params = []): array
    {
        return $this->getClient()->getEventList($this->getCalendarId(), $params);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getEventInstances(string $eventId, array $params = []): array
    {
        return $this->getClient()->getEventInstances($this->getCalendarId(), $eventId, $params);
    }

    /**
     * @param array<string, mixed> $espoEvent
     */
    public function insertIntoGoogle(array $espoEvent): bool
    {
        $googleEvent = $this->convertEventToGoogle($espoEvent);

        if (!empty($googleEvent)) {
            $response = $this->getClient()->insertEvent($this->getCalendarId(), $googleEvent);

            if (is_array($response) && !empty($response['id'])) {
                $this->getMsxGoogleCalendarRepository()->storeEventRelation(
                    $espoEvent['scope'],
                    $espoEvent['id'],
                    $this->syncParams['calendar']->get('msxGoogleCalendarId'),
                    $response['id']
                );

                return true;
            }
        }

        $this->getMsxGoogleCalendarRepository()->resetEventRelation($espoEvent['scope'], $espoEvent['id']);

        return false;
    }

    /**
     * @param array<string, mixed> $event
     */
    public function updateGoogleEvent(array $event, bool $withCompare): bool
    {
        $googleEvent = $this->retrieveGoogleEvent($event['msxGoogleCalendarEventId']);

        if (!is_object($googleEvent) || !$googleEvent->getId()) {
            return false;
        }

        $entityType = $event['scope'] ?? null;

        if ($withCompare && $event['modifiedAt'] < $googleEvent->updated() || $googleEvent->isPrivate()) {
            return false;
        }

        $changedFields = [];

        if ($googleEvent->isDeleted() != $event['deleted']) {
            if ($event['deleted']) {
                if (
                    $this->syncParams['removeGCEventIfRemovedInEspo'] ||
                    $googleEvent->getSource() == 'EspoCRM'
                ) {
                    return $this->getClient()->deleteEvent($this->getCalendarId(), $googleEvent->getId());
                }
            } else {
                $googleEvent->restore();
                $changedFields[] = 'status';
            }
        }

        $name = $googleEvent->getSummary();
        $parsedName = $this->parseGoogleEventName($name);

        if ($parsedName['name'] !== $event['name'] || $parsedName['scope'] !== $event['scope']) {
            $changedFields[] = 'name';
            $googleEvent->setSummary($this->convertToGoogleEventName($event['scope'], $event['name']));
        }

        foreach ($this->getFieldPairs($entityType) as $googleField => $espoField) {
            $googleFieldUCF = ucfirst($googleField);

            if (!array_key_exists($espoField, $event)) {
                continue;
            }

            if ($espoField === 'name') {
                continue;
            }

            $getMethod = 'get' . $googleFieldUCF;
            $setMethod = 'set' . $googleFieldUCF;

            if (
                method_exists($googleEvent, $getMethod) &&
                method_exists($googleEvent, $setMethod) &&
                $googleEvent->$getMethod() != $event[$espoField]
            ) {
                $changedFields[] = $googleField;
                $googleEvent->$setMethod($event[$espoField]);
            }
        }

        if ($googleEvent->getSource() === 'EspoCRM') {
            $joinUrl = $event['joinUrl'] ?? null;

            if (is_string($joinUrl)) {
                $googleEvent->appendJoinUrlToDescription($joinUrl);
            }
        }

        if (!$this->syncParams['dontSyncEventAttendees'] && $event['attendees'] !== null) {
            $modifiedAtt = false;
            $googleAttendees = $googleEvent->getAttendees();
            $googleAttendeesEmails = [];

            foreach ($googleAttendees as $gcAttendee) {
                $googleAttendeesEmails[] = $gcAttendee['email'];
            }

            foreach ($event['attendees'] as $espoAttendee) {
                $emailAddress = null;

                foreach ($espoAttendee['emailData'] as $email) {
                    if (!is_object($email)) {
                        continue;
                    }

                    if (in_array($email->emailAddress, $googleAttendeesEmails)) {
                        $emailAddress = $email->emailAddress;
                        break;
                    }
                }

                if (
                    isset($espoAttendee['emailData'][0]) &&
                    empty($emailAddress) &&
                    is_object($espoAttendee['emailData'][0])
                ) {
                    $emailAddress = $espoAttendee['emailData'][0]->emailAddress;
                }

                if (!empty($emailAddress)) {
                    $gAttendeeStatus = array_search($espoAttendee['status'], $this->statusPairs);

                    if ($espoAttendee['id'] == $this->syncParams['userId']) {
                        if (
                            in_array($emailAddress, $googleAttendeesEmails) ||
                            count($event['attendees']) > 1
                        ) {
                            $modifiedAtt |= $googleEvent->addAttendee($emailAddress, $gAttendeeStatus);
                        }
                    } else {
                        $modifiedAtt |= $googleEvent->addAttendee($emailAddress, $gAttendeeStatus);
                    }
                }
            }

            if ($modifiedAtt) {
                $changedFields[] = 'attendees';
            }
        }

        if (!empty($changedFields)) {
            $res = $this->getClient()
                ->updateEvent($this->getCalendarId(), $googleEvent->getId(), $googleEvent->build());

            if (!$res) {
                $this->getMsxGoogleCalendarRepository()->storeEventRelation(
                    $entityType, $event['id'], '', 'FAIL'
                );
            } else {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $event
     */
    public function updateEspoEvent(array $event, bool $withCompare = true): bool
    {
        $googleEvent = $this->asCalendarEvent($event);

        $parsedName = $this->parseGoogleEventName($googleEvent->getSummary());

        $scope = $parsedName['scope'];
        $name = $parsedName['name'];

        if (!$this->acl->check($scope, Table::ACTION_EDIT)) {
            return false;
        }

        if ($googleEvent->isDeleted()) {
            $this->deleteRecurrentInstancesFromEspo($googleEvent->getId());
        }

        if (
            $googleEvent->getEventType() &&
            !in_array($googleEvent->getEventType(), [
                CalendarEvent::EVENT_TYPE_DEFAULT,
                CalendarEvent::EVENT_TYPE_FROM_GMAIL,
            ])
        ) {
            return false;
        }

        if ($googleEvent->getRecurrence() && !$googleEvent->getRecurringEventId()) {
            $this->deleteRecurrentInstancesFromEspo($googleEvent->getId());

            if (!$googleEvent->isPrivate() && $googleEvent->hasEnd()) {
                $this->addRecurrentEventToQueue($googleEvent->getId());

                $espoEvents = $this->getMsxGoogleCalendarRepository()->findEspoEntitiesForGoogleEvent(
                    $this->syncParams['userId'],
                    $googleEvent,
                    $this->syncParams['syncEntities']
                );

                foreach ($espoEvents as $espoEvent) {
                    $this->getMsxGoogleCalendarRepository()->resetEventRelation(
                        $espoEvent->getEntityType(),
                        $espoEvent->get('id')
                    );

                    $this->entityManager->removeEntity($espoEvent, ['silent' => true]);
                }
            }

            return false;
        }

        if (!$googleEvent->isDeleted() && $googleEvent->getStart() < $this->syncParams['fetchSince']) {
            return false;
        }

        $espoEvents = $this->getMsxGoogleCalendarRepository()->findEspoEntitiesForGoogleEvent(
            $this->syncParams['userId'],
            $googleEvent,
            $this->syncParams['syncEntities']
        );

        if (empty($espoEvents)) {
            if (in_array($scope, $this->syncParams['syncEntities'])) {
                $espoEvents = [$this->entityManager->getNewEntity($scope)];
            } else {
                return false;
            }
        }

        foreach ($espoEvents as $espoEvent) {
            if ($espoEvent->get('deleted')) {
                continue;
            }

            if (
                !is_object($espoEvent) ||
                !in_array($espoEvent->getEntityType(), $this->syncParams['syncEntities'])
            ) {
                continue;
            }

            $eventIsNew = $espoEvent->isNew();

            if (
                $googleEvent->isDeleted() ||
                $googleEvent->isPrivate() ||
                !$googleEvent->hasEnd()
            ) {
                if (!$eventIsNew && $this->acl->check($espoEvent->getEntityType(), 'delete')) {
                    $this->getMsxGoogleCalendarRepository()->resetEventRelation(
                        $espoEvent->getEntityType(),
                        $espoEvent->get('id')
                    );

                    $this->entityManager->removeEntity($espoEvent, ['silent' => true]);
                }

                continue;
            }

            if (
                $scope !== $espoEvent->getEntityType() &&
                in_array($scope, $this->syncParams['syncEntities'])
            ) {
                if ($googleEvent->getSource() !== 'EspoCRM') {
                    $espoEvent->loadLinkMultipleField('users', ['status' => 'acceptanceStatus']);
                    $espoEvent->loadLinkMultipleField('contacts', ['status' => 'acceptanceStatus']);
                    $espoEvent->loadLinkMultipleField('leads', ['status' => 'acceptanceStatus']);

                    $oldValues = [];

                    foreach ($espoEvent->fields as $field => $fieldParams) {
                        if ($field == 'id') {
                            continue;
                        }

                        $oldValues[$field] = $espoEvent->get($field);
                    }

                    $this->getMsxGoogleCalendarRepository()->resetEventRelation(
                        $espoEvent->getEntityType(),
                        $espoEvent->get('id')
                    );

                    $this->entityManager->removeEntity($espoEvent, ['silent' => true]);

                    $espoEvent = $this->entityManager->getNewEntity($scope);
                    $eventIsNew = true;
                    $espoEvent->set($oldValues);
                }
            }

            if (!in_array($espoEvent->getEntityType(), $this->syncParams['syncEntities'])) {
                continue;
            }

            if (!$eventIsNew && $withCompare && $espoEvent->get('modifiedAt') > $googleEvent->updated()) {
                continue;
            }

            $isModified = false;

            if (
                !$eventIsNew &&
                $espoEvent->hasAttribute('msxGoogleCalendarId') &&
                $espoEvent->get('msxGoogleCalendarId') !== $this->syncParams['calendar']->get('msxGoogleCalendarId')
            ) {
                $this->getMsxGoogleCalendarRepository()->storeEventRelation(
                    $espoEvent->getEntityType(),
                    $espoEvent->get('id'),
                    $this->syncParams['calendar']->get('msxGoogleCalendarId'),
                    $googleEvent->getId()
                );
            }

            $entityDefs = method_exists($this->entityManager, 'getDefs') ?
                $this->entityManager
                    ->getDefs()
                    ->getEntity($espoEvent->getEntityType()) :
                null;

            foreach ($this->getFieldPairs($espoEvent->getEntityType()) as $googleField => $espoField) {
                if (!$espoEvent->hasAttribute($espoField)) {
                    continue;
                }

                if ($espoField == 'name') {
                    $googleValue = $name;
                } else {
                    if (!method_exists($googleEvent, 'get' . ucfirst($googleField))) {
                        continue;
                    }

                    $googleValue = $googleEvent->{'get' . ucfirst($googleField)}();
                }

                if (
                    $espoEvent->getAttributeType($espoField) === Entity::VARCHAR &&
                    $entityDefs
                ) {
                    $maxLength = $entityDefs->getAttribute($espoField)->getLength() ?? 255;
                    $googleValue = substr($googleValue, 0, $maxLength);
                }

                if ($eventIsNew) {
                    $espoEvent->set($espoField, $googleValue);
                } else if ($espoEvent->get($espoField) != $googleValue) {
                    $espoEvent->set($espoField, $googleValue);
                    $isModified = true;
                }
            }

            $joinUrl = $googleEvent->getJoinUrl();

            if ($joinUrl && $espoEvent instanceof Meeting) {
                if (!$eventIsNew && $joinUrl !== $espoEvent->get('joinUrl')) {
                    $isModified = true;
                }

                $espoEvent->set('joinUrl', $joinUrl);
            }

            $attendeeFields = [
                'usersIds',
                'contactsIds',
                'leadsIds',
                'usersColumns',
                'contactsColumns',
                'leadsColumns',
            ];

            if ($eventIsNew) {
                $userId = $this->syncParams['userId'];
                $espoEvent->set('assignedUserId', $userId);

                if ($this->syncParams['assignDefaultTeam']) {
                    $user = $this->entityManager->getEntityById('User', $userId);

                    if ($user && $user->get('defaultTeamId')) {
                        $teamsIds = $espoEvent->get('teamsIds') ?? [];
                        $teamsIds[] = $user->get('defaultTeamId');
                        $espoEvent->set('teamsIds', $teamsIds);
                    }
                }

                try {
                    $dateEspo = new DateTime($espoEvent->get('dateEnd'));
                } catch (Exception $e) {
                    throw new RuntimeException($e->getMessage());
                }

                $dateNow = new DateTime();

                if ($dateEspo < $dateNow) {
                    $espoEvent->set('status', 'Held');
                }

                if (
                    $espoEvent->hasRelation('users') &&
                    $espoEvent->hasRelation('contacts') &&
                    $espoEvent->hasRelation('leads')
                ) {
                    $usersIds = [];
                    $contactsIds = [];
                    $leadsIds = [];
                    $usersColumns = new stdClass();
                    $contactsColumns = new stdClass();
                    $leadsColumns = new stdClass();

                    foreach ($googleEvent->getAttendees() as $gAttendee) {
                        if (!empty($gAttendee['email'])) {
                            /** @var EmailAddress $repo */
                            $repo = $this->entityManager->getRepository('EmailAddress');
                            $entity = $repo->getEntityByAddress($gAttendee['email']);

                            if (!empty($entity)) {
                                $id = $entity->get('id');
                                $entityName = strtolower($entity->getEntityType());

                                ${$entityName . 'sIds'}[] = $id;

                                $columnData = new stdClass();
                                $columnData->status = $this->statusPairs[$gAttendee['responseStatus']] ?? 'None';
                                ${$entityName . 'sColumns'}->$id = $columnData;
                            }
                        }
                    }

                    if (empty($usersIds) || !in_array($userId, $usersIds)) {
                        $usersIds[] = $userId;

                        $columnData = new stdClass();
                        $columnData->status = 'None';
                        $usersColumns->$userId = $columnData;
                    }

                    foreach ($attendeeFields as $attendeeField) {
                        if ($espoEvent->hasAttribute($attendeeField) && !empty($$attendeeField)) {
                            $espoEvent->set($attendeeField, $$attendeeField);
                        }
                    }
                }
            } else {
                $espoAttendees = $this->getMsxGoogleCalendarRepository()
                    ->getEventAttendees($espoEvent->getEntityType(), $espoEvent->get('id'));

                if ($espoAttendees !== null) {
                    $espoEvent->loadLinkMultipleField('users', ['status' => 'acceptanceStatus']);
                    $espoEvent->loadLinkMultipleField('contacts', ['status' => 'acceptanceStatus']);

                    if ($espoEvent->get('leads')) {
                        $espoEvent->loadLinkMultipleField('leads', ['status' => 'acceptanceStatus']);
                    }

                    foreach ($googleEvent->getAttendees() as $gAttendee) {
                        if (empty($gAttendee['email'])) {
                            continue;
                        }

                        $eAttendee = null;
                        $emailOwner = '';

                        foreach ($espoAttendees as $eAttendee) {
                            if (empty($eAttendee['emailData']) || !is_array($eAttendee['emailData'])) {
                                continue;
                            }

                            foreach ($eAttendee['emailData'] as $email) {
                                if (
                                    is_object($email) &&
                                    strtolower($email->emailAddress) == strtolower($gAttendee['email'])
                                ) {
                                    $emailOwner = $eAttendee;
                                    break 2;
                                }
                            }
                        }

                        if (empty($emailOwner)) {
                            /** @var EmailAddress $repo */
                            $repo = $this->entityManager->getRepository('EmailAddress');
                            $entity = $repo->getEntityByAddress($gAttendee['email']);

                            $entityName = (!empty($entity)) ? strtolower($entity->getEntityType()) : '';

                            if (in_array($entityName . 'sIds', $attendeeFields)) {
                                $id = $entity->get('id');

                                ${$entityName . 'sIds'} = $espoEvent->get($entityName . 'sIds');
                                ${$entityName . 'sIds'}[] = $entity->get('id');

                                ${$entityName . 'sColumns'} = $espoEvent->get($entityName . 'sColumns');

                                if (!isset(${$entityName . 'sColumns'})) {
                                    ${$entityName . 'sColumns'} = new stdClass();
                                }

                                $columnData = new stdClass();
                                $columnData->status = $this->statusPairs[$gAttendee['responseStatus']] ?? 'None';
                                ${$entityName . 'sColumns'}->$id = $columnData;

                                $espoEvent->set($entityName . 'sIds', ${$entityName . 'sIds'});
                                $espoEvent->set($entityName . 'sColumns', ${$entityName . 'sColumns'});

                                $isModified = true;
                            }
                        } else if (
                            $eAttendee['status'] != ($this->statusPairs[$gAttendee['responseStatus']] ?? 'None') &&
                            ($this->statusPairs[$gAttendee['responseStatus']] ?? 'None') != 'None'
                        ) {
                            $entityName = strtolower($eAttendee['scope']);
                            $entityId = $eAttendee['id'];

                            ${$entityName . 'sColumns'} = $espoEvent->get($entityName . 'sColumns');

                            ${$entityName . 'sColumns'}->$entityId->status =
                                $this->statusPairs[$gAttendee['responseStatus']] ?? 'None';

                            $espoEvent->set($entityName . 'sColumns', ${$entityName . 'sColumns'});

                            $isModified = true;
                        }
                    }
                }
            }

            if ($eventIsNew || $isModified) {
                $this->entityManager->saveEntity($espoEvent, ['silent' => true]);

                if ($eventIsNew) {
                    $this->getMsxGoogleCalendarRepository()->storeEventRelation(
                        $espoEvent->getEntityType(),
                        $espoEvent->get('id'),
                        $this->syncParams['calendar']->get('msxGoogleCalendarId'),
                        $googleEvent->getId()
                    );
                }
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $espoEvent
     * @return array<string, mixed>
     */
    public function convertEventToGoogle(array $espoEvent): array
    {
        $entityType = $espoEvent['scope'];

        $googleEvent = $this->asCalendarEvent();
        $espoEvent['name'] = $this->convertToGoogleEventName($entityType, $espoEvent['name']);

        if (empty($espoEvent['dateEnd'])) {
            $espoEvent['dateEnd'] = $espoEvent['dateStart'];
        }

        foreach ($this->getFieldPairs($entityType) as $googleField => $espoField) {
            if (method_exists($googleEvent, 'set' . ucfirst($googleField)) && isset($espoEvent[$espoField])) {
                $googleEvent->{'set' . ucfirst($googleField)}($espoEvent[$espoField]);
            }
        }

        $uid = $espoEvent['uid'] ?? null;

        if (is_string($uid) && strlen($uid) <= 255) {
            $googleEvent->setICalUID($uid);
        }

        $joinUrl = $espoEvent['joinUrl'] ?? null;

        if (is_string($joinUrl)) {
            $googleEvent->appendJoinUrlToDescription($joinUrl);
        }

        if (!empty($espoEvent['attendees'])) {
            foreach ($espoEvent['attendees'] as $attendee) {
                if (
                    $this->syncParams['dontSyncEventAttendees'] &&
                    in_array($attendee['entityType'], ['Contact', 'Lead'])
                ) {
                    continue;
                }

                if (
                    !empty($attendee['emailData']) &&
                    ($attendee['id'] != $this->syncParams['userId'] ||
                        count($espoEvent['attendees']) > 1)
                ) {
                    $googleEvent->addAttendee(
                        $attendee['emailData'][0]->emailAddress,
                        array_search($attendee['status'], $this->statusPairs)
                    );
                }
            }
        }

        $siteUrl = rtrim($this->config->get('siteUrl'), '/');
        $url = $siteUrl . '/#' . $espoEvent['scope'] . '/view/' . $espoEvent['id'];

        $googleEvent->setSource('EspoCRM', $url);

        return $googleEvent->build();
    }

    /**
     * @return array{scope: string, name: string}
     */
    public function parseGoogleEventName(?string $value): array
    {
        $scope = $this->syncParams['defaultEntity'];
        $name = $value ?? '';

        foreach ($this->syncParams['entityLabels'] as $entityType => $label) {
            if (!empty($label)) {
                $pattern = "/^{$label}[':',' ','-']+/i";
                $matchRes = preg_match_all($pattern, $name);

                if ($matchRes > 0) {
                    $scope = $entityType;
                    $name = preg_replace($pattern, '', $name, 1);

                    if (empty($name)) {
                        $name = $value ?? '';
                    }

                    break;
                }
            } else {
                $scope = $entityType;
                break;
            }
        }

        return ['scope' => $scope, 'name' => $name];
    }

    public function convertToGoogleEventName(string $scope, string $name): string
    {
        $label = $this->syncParams['entityLabels'][$scope] ?? '';

        if (!empty($label)) {
            $label .= ': ';
        }

        return $label . $name;
    }

    public function retrieveGoogleEvent(string $id): ?CalendarEvent
    {
        $event = $this->getClient()->retrieveEvent($this->getCalendarId(), $id);

        if (!empty($event) && is_array($event)) {
            return $this->asCalendarEvent($event);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function asCalendarEvent(array $event = []): CalendarEvent
    {
        $calendarEvent = new CalendarEvent($event);

        $calendarEvent->setDefaults([
            'timeZone' => $this->syncParams['googleTimeZone'],
            'userTimeZone' => $this->syncParams['userTimeZone'],
        ]);

        return $calendarEvent;
    }

    private function addRecurrentEventToQueue(string $eventId): void
    {
        $this->getMsxGoogleCalendarRepository()->addRecurrentEventToQueue(
            $this->syncParams['calendar']->get('id'),
            $eventId
        );
    }

    /**
     * @return array<string, mixed>|false
     */
    public function getRecurrentEventFromQueue()
    {
        return $this->getMsxGoogleCalendarRepository()->getRecurrentEventFromQueue(
            $this->syncParams['calendar']->get('id')
        );
    }

    public function updateRecurrentEvent(string $id, string $pageToken = '', ?string $lastEventTime = null): void
    {
        $this->getMsxGoogleCalendarRepository()->updateRecurrentEvent($id, $pageToken, $lastEventTime);
    }

    public function removeRecurrentEventFromQueue(string $id): void
    {
        $this->getMsxGoogleCalendarRepository()->removeRecurrentEventFromQueue($id);
    }

    public function deleteRecurrentInstancesFromEspo(string $id): void
    {
        $this->getMsxGoogleCalendarRepository()->deleteRecurrentInstancesFromEspo(
            $this->syncParams['calendar']->get('msxGoogleCalendarId'),
            $id,
            $this->syncParams['syncEntities']
        );
    }
}
