<?php

namespace Espo\Modules\PackEnterprise\Repositories;

use DateTime;
use Espo\Core\ORM\Entity;
use Espo\Core\Repositories\Database;
use Espo\Modules\PackEnterprise\Core\GoogleCalendar\Items\CalendarEvent;
use Espo\ORM\Query\DeleteBuilder;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\Query\UnionBuilder;
use Espo\ORM\Query\UpdateBuilder;
use Espo\Repositories\EmailAddress;
use Exception;
use PDO;

/**
 * Repository for MsxGoogleCalendar entity.
 * Adapted from Espo\Modules\Google\Repositories\GoogleCalendar.
 * References MsxGoogleCalendar* entities and msxGoogleCalendar* fields.
 */
class MsxGoogleCalendar extends Database
{
    /** @var string[]|null */
    private ?array $allowedEventTypes = null;

    /** @var string[]|null */
    private ?array $userWithIntegrationIdList = null;

    /** @var string[] */
    private array $coreEventTypes = ['Meeting', 'Call'];

    /** @var array<string, string> */
    private array $usersMiddles = [
        'Meeting' => 'MeetingUser',
        'Call' => 'CallUser',
    ];

    /** @var array<string, string> */
    private array $usersJoinForeignKeys2 = [
        'Meeting' => 'meetingId',
        'Call' => 'callId',
    ];

    /**
     * @param string|string[] $types
     * @return string[]
     */
    private function validateEventTypes($types): array
    {
        if (!is_array($this->allowedEventTypes)) {
            $this->loadAllowedEventTypes();
        }

        $selectedEventTypes = [];
        $eventTypes = is_array($types) ? $types : [$types];

        foreach ($eventTypes as $eventType) {
            if (
                in_array($eventType, $this->allowedEventTypes) &&
                !in_array($eventType, $selectedEventTypes)
            ) {
                $selectedEventTypes[] = $eventType;
            }
        }

        return $selectedEventTypes;
    }

    private function loadAllowedEventTypes(): void
    {
        $scopes = $this->metadata->get('scopes');
        $allowedTypes = [];

        foreach ($scopes as $scope => $defs) {
            if (
                !empty($defs['activity']) &&
                !empty($defs['entity']) &&
                !empty($defs['object']) &&
                empty($defs['disabled']) &&
                $scope !== 'Email'
            ) {
                $allowedTypes[] = $scope;
            }
        }

        $this->allowedEventTypes = $allowedTypes;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function storedUsersCalendars(string $userId): array
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select([
                ['gcuser.id', 'id'],
                ['gcuser.type', 'type'],
                ['gcuser.msxGoogleCalendarId', 'msxGoogleCalendarId'],
                ['gcuser.active', 'active'],
                ['gc.name', 'name'],
                ['gc.calendarId', 'calendarId'],
            ])
            ->from('MsxGoogleCalendarUser', 'gcuser')
            ->join('MsxGoogleCalendar', 'gc', ['gcuser.msxGoogleCalendarId:' => 'gc.id'])
            ->where([
                'gcuser.userId' => $userId,
            ])
            ->build();

        $sth = $this->entityManager->getQueryExecutor()->execute($query);

        $rows = $sth->fetchAll(PDO::FETCH_ASSOC);

        $result = [
            'monitored' => [],
            'main' => [],
        ];

        foreach ($rows as $row) {
            $type = $row['type'];
            $msxGoogleCalendarId = $row['msxGoogleCalendarId'];

            $result[$type][$msxGoogleCalendarId] = $row;
        }

        return $result;
    }

    /**
     * @return ?Entity
     */
    public function getCalendarByGCId(string $googleCalendarId)
    {
        return $this->entityManager
            ->getRDBRepository('MsxGoogleCalendar')
            ->where(['calendarId' => $googleCalendarId])
            ->findOne();
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function getEventAttendees(string $eventType, string $eventId): ?array
    {
        if (
            !in_array($eventType, $this->allowedEventTypes ?? []) ||
            !in_array($eventType, $this->coreEventTypes)
        ) {
            return null;
        }

        $event = $this->entityManager->getEntityById($eventType, $eventId);

        if (!$event) {
            return [];
        }

        $result = [];
        $links = ['users', 'contacts', 'leads'];

        foreach ($links as $link) {
            $attendees = $this->entityManager
                ->getRDBRepository($eventType)
                ->getRelation($event, $link)
                ->select([
                    'id',
                    'acceptanceStatus',
                ])
                ->find();

            foreach ($attendees as $attendee) {
                /** @var EmailAddress $repo */
                $repo = $this->entityManager->getRepository('EmailAddress');
                $emailData = $repo->getEmailAddressData($attendee);

                $result[] = [
                    'emailData' => $emailData,
                    'status' => $attendee->get('acceptanceStatus'),
                    'id' => $attendee->getId(),
                    'scope' => $attendee->getEntityType(),
                    'entityType' => $attendee->getEntityType(),
                ];
            }
        }

        return $result;
    }

    /**
     * @return ?Entity
     */
    public function getUsersMainCalendar(string $userId)
    {
        return $this->entityManager
            ->getRDBRepository('MsxGoogleCalendarUser')
            ->where([
                'active' => true,
                'userId' => $userId,
                'type' => 'main',
            ])
            ->findOne();
    }

    public function addRecurrentEventToQueue(string $calendarUserId, string $eventId): void
    {
        $this->removeRecurrentEventFromQueueByEventId($eventId);

        $this->entityManager->createEntity('MsxGoogleCalendarRecurrentEvent', [
            'msxGoogleCalendarUserId' => $calendarUserId,
            'eventId' => $eventId,
        ]);
    }

    public function removeRecurrentEventFromQueue(string $id): void
    {
        $delete = DeleteBuilder::create()
            ->from('MsxGoogleCalendarRecurrentEvent')
            ->where(['id' => $id])
            ->build();

        $this->entityManager->getQueryExecutor()->execute($delete);
    }

    public function removeRecurrentEventFromQueueByEventId(string $eventId): void
    {
        $delete = DeleteBuilder::create()
            ->from('MsxGoogleCalendarRecurrentEvent')
            ->where(['eventId' => $eventId])
            ->build();

        $this->entityManager->getQueryExecutor()->execute($delete);
    }

    /**
     * @return array<string, mixed>|false
     */
    public function getRecurrentEventFromQueue(string $calendarUserId)
    {
        $maxRange = new DateTime();
        $maxRange->modify('+6 months');

        $select = SelectBuilder::create()
            ->from('MsxGoogleCalendarRecurrentEvent')
            ->select([
                'id',
                'eventId',
                'pageToken',
                ['lastLoadedEventTime', 'lastEventTime'],
            ])
            ->where([
                'msxGoogleCalendarUserId' => $calendarUserId,
                [
                    'OR' => [
                        ['lastLoadedEventTime' => null],
                        ['lastLoadedEventTime<' => $maxRange->format('Y-m-d H:i:s')],
                    ],
                ],
            ])
            ->order('lastLoadedEventTime')
            ->build();

        try {
            $sth = $this->entityManager->getQueryExecutor()->execute($select);

            return $sth->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $GLOBALS['log']->error("MsxGoogleCalendar: Failed query: " . $e->getMessage());

            return false;
        }
    }

    public function updateRecurrentEvent(string $id, string $pageToken = '', ?string $lastEventTime = null): void
    {
        $update = UpdateBuilder::create()
            ->in('MsxGoogleCalendarRecurrentEvent')
            ->set([
                'pageToken' => $pageToken,
                'lastLoadedEventTime' => !empty($lastEventTime) ? $lastEventTime : null,
            ])
            ->where(['id' => $id])
            ->build();

        try {
            $this->entityManager->getQueryExecutor()->execute($update);
        } catch (Exception $e) {
            $GLOBALS['log']->error("MsxGoogleCalendar: Failed query: " . $e->getMessage());
        }
    }

    /**
     * @param CalendarEvent $googleEvent
     * @param string[] $eventTypes
     * @return Entity[]
     */
    public function findEspoEntitiesForGoogleEvent(
        string $userId,
        CalendarEvent $googleEvent,
        array $eventTypes
    ): array {
        $eventId = $googleEvent->getId();
        $uid = $googleEvent->getICalUID();

        if (!$eventId) {
            return [];
        }

        $results = [];
        $eventTypes = $this->validateEventTypes($eventTypes);

        foreach ($eventTypes as $eventType) {
            if (in_array($eventType, $this->coreEventTypes)) {
                $where = ['msxGoogleCalendarEventId' => $eventId];

                if ($this->metadata->get("entityDefs.$eventType.fields.uid") && $uid) {
                    $where = [
                        'OR' => [
                            $where,
                            [
                                'uid' => $uid,
                                'msxGoogleCalendarEventId' => null,
                            ],
                        ],
                    ];
                }

                $events = $this->entityManager
                    ->getRDBRepository($eventType)
                    ->clone(
                        SelectBuilder::create()
                            ->from($eventType)
                            ->withDeleted()
                            ->build()
                    )
                    ->where($where)
                    ->order('modifiedAt', true)
                    ->find();

                foreach ($events as $event) {
                    $results[] = $event;
                }

                continue;
            }

            $select = SelectBuilder::create()
                ->select('gce.entityId', 'entityId')
                ->from('MsxGoogleCalendarEvent', 'gce')
                ->join($eventType, 'entityTable', [
                    'entityTable.id:' => 'gce.entityId',
                    'entityTable.deleted' => false,
                ])
                ->where([
                    'entityTable.assignedUserId' => $userId,
                    'gce.msxGoogleCalendarEventId' => $eventId,
                ])
                ->order('entityTable.modifiedAt', 'DESC')
                ->build();

            try {
                $sth = $this->entityManager->getQueryExecutor()->execute($select);
                $rows = $sth->fetchAll(PDO::FETCH_ASSOC);

                foreach ($rows as $row) {
                    $event = $this->entityManager->getEntityById($eventType, $row['entityId']);

                    if ($event) {
                        $results[] = $event;
                    }
                }
            } catch (Exception $e) {
                $GLOBALS['log']->error("MsxGoogleCalendar: Failed query: " . $e->getMessage());

                return [];
            }
        }

        return $results;
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

    /**
     * @param string[] $eventTypes
     * @return array<int, array<string, mixed>>
     */
    public function getEvents(
        string $userId,
        array $eventTypes,
        string $since,
        string $to,
        string $lastEventId,
        string $googleCalendarId,
        int $limit = 20
    ): array {
        $msxGoogleCalendar = $this->getCalendarByGCId($googleCalendarId);

        if (empty($msxGoogleCalendar)) {
            return [];
        }

        $lowerLimitWhere = $lastEventId ?
            [
                'OR' => [
                    'modifiedAt>' => $since,
                    [
                        'modifiedAt' => $since,
                        'id>' => $lastEventId,
                    ],
                ],
            ] :
            ['modifiedAt>' => $since];

        $eventTypes = $this->validateEventTypes($eventTypes);

        $queryList = [];

        foreach ($eventTypes as $eventType) {
            $isAllDay = (bool) $this->metadata->get(['entityDefs', $eventType, 'fields', 'isAllDay']);
            $hasJoinUrl = (bool) $this->metadata->get(['entityDefs', $eventType, 'fields', 'joinUrl']);
            $hasLocation = $this->hasFieldTextVarchar($eventType, 'location');
            $hasCLocation = $this->hasFieldTextVarchar($eventType, 'cLocation');

            if (in_array($eventType, $this->coreEventTypes)) {
                $foreignKey = $this->usersJoinForeignKeys2[$eventType];
                $middleEntityType = $this->usersMiddles[$eventType];

                $select = SelectBuilder::create()
                    ->from($eventType)
                    ->select([
                        ["'$eventType'", 'scope'],
                        'id',
                        'name',
                        'dateStart',
                        'dateEnd',
                        $isAllDay ? ['dateStartDate', 'dateStartDate'] : ['null', 'dateStartDate'],
                        $isAllDay ? ['dateEndDate', 'dateEndDate'] : ['null', 'dateEndDate'],
                        'msxGoogleCalendarEventId',
                        'modifiedAt',
                        'description',
                        'deleted',
                        'status',
                        [$hasLocation ? 'location' : 'null', 'location'],
                        [$hasCLocation ? 'cLocation' : 'null', 'cLocation'],
                        [$hasJoinUrl ? 'joinUrl' : 'null', 'joinUrl'],
                    ])
                    ->leftJoin($middleEntityType, 'middle', [
                        "middle.$foreignKey:" => 'id',
                        'middle.deleted' => false,
                    ])
                    ->where([
                        'middle.userId' => $userId,
                        ['msxGoogleCalendarEventId!=' => ''],
                        ['msxGoogleCalendarEventId!=' => 'FAIL'],
                        ['msxGoogleCalendarEventId!=' => null],
                        'msxGoogleCalendarId' => $msxGoogleCalendar->get('id'),
                        'modifiedAt<' => $to,
                        [
                            'OR' => [
                                'modifiedAt!=:' => 'createdAt',
                                'deleted' => true,
                            ],
                        ],
                    ])
                    ->where($lowerLimitWhere)
                    ->withDeleted()
                    ->build();

                $queryList[] = $select;

                continue;
            }

            $select = SelectBuilder::create()
                ->from($eventType)
                ->select([
                    ["'$eventType'", 'scope'],
                    'id',
                    'name',
                    'dateStart',
                    'dateEnd',
                    $isAllDay ? ['dateStartDate', 'dateStartDate'] : ['null', 'dateStartDate'],
                    $isAllDay ? ['dateEndDate', 'dateEndDate'] : ['null', 'dateEndDate'],
                    ['gce.msxGoogleCalendarEventId', 'msxGoogleCalendarEventId'],
                    'modifiedAt',
                    'description',
                    'deleted',
                    'status',
                    [$hasLocation ? 'location' : 'null', 'location'],
                    [$hasCLocation ? 'cLocation' : 'null', 'cLocation'],
                    [$hasJoinUrl ? 'joinUrl' : 'null', 'joinUrl'],
                ])
                ->leftJoin('MsxGoogleCalendarEvent', 'gce', [
                    'gce.entityId:' => 'id',
                    'gce.entityType' => $eventType,
                ])
                ->where([
                    'assignedUserId' => $userId,
                    ['gce.msxGoogleCalendarEventId!=' => ''],
                    ['gce.msxGoogleCalendarEventId!=' => 'FAIL'],
                    ['gce.msxGoogleCalendarEventId!=' => null],
                    'gce.msxGoogleCalendarId' => $msxGoogleCalendar->get('id'),
                    'modifiedAt<' => $to,
                    [
                        'OR' => [
                            'modifiedAt!=:' => 'createdAt',
                            'deleted' => true,
                        ],
                    ],
                ])
                ->where($lowerLimitWhere)
                ->withDeleted()
                ->build();

            $queryList[] = $select;
        }

        if ($queryList === []) {
            return [];
        }

        $result = [];

        $builder = UnionBuilder::create();

        foreach ($queryList as $select) {
            $builder->query($select);
        }

        $union = $builder
            ->order('modifiedAt', 'ASC')
            ->order('id', 'ASC')
            ->limit(0, $limit)
            ->build();

        try {
            $sth = $this->entityManager->getQueryExecutor()->execute($union);
            $rows = $sth->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $attendees = (!$row['deleted']) ?
                    $this->getEventAttendees($row['scope'], $row['id']) : [];

                $result[] = array_merge($row, ['attendees' => $attendees]);
            }
        } catch (Exception $e) {
            $GLOBALS['log']->error("MsxGoogleCalendar: Failed query: " . $e->getMessage());
        }

        return $result;
    }

    /**
     * @return string[]
     */
    protected function getUserWithPushIntegrationIdList(): array
    {
        if (isset($this->userWithIntegrationIdList)) {
            return $this->userWithIntegrationIdList;
        }

        // Find users who have active MsxGoogleCalendarUser records with push direction.
        $calendarUsers = $this->entityManager
            ->getRDBRepository('MsxGoogleCalendarUser')
            ->where([
                'active' => true,
                'calendarDirection!=' => 'GCToEspo',
            ])
            ->select(['userId'])
            ->group('userId')
            ->find();

        $userWithIntegrationIdList = [];

        foreach ($calendarUsers as $calendarUser) {
            $userWithIntegrationIdList[] = $calendarUser->get('userId');
        }

        $this->userWithIntegrationIdList = $userWithIntegrationIdList;

        return $userWithIntegrationIdList;
    }

    /**
     * @param string[] $eventTypes
     * @return array<int, array<string, mixed>>
     */
    public function getNewEvents(
        string $userId,
        array $eventTypes,
        string $since,
        int $limit = 20
    ): array {
        $eventTypes = $this->validateEventTypes($eventTypes);
        $userWithIntegrationIdList = $this->getUserWithPushIntegrationIdList();

        $queryList = [];

        foreach ($eventTypes as $eventType) {
            $isAllDay = (bool) $this->metadata->get(['entityDefs', $eventType, 'fields', 'isAllDay']);
            $hasLocation = $this->hasFieldTextVarchar($eventType, 'location');
            $hasCLocation = $this->hasFieldTextVarchar($eventType, 'cLocation');
            $hasUid = (bool) $this->metadata->get(['entityDefs', $eventType, 'fields', 'uid']);
            $hasJoinUrl = (bool) $this->metadata->get(['entityDefs', $eventType, 'fields', 'joinUrl']);

            if (in_array($eventType, $this->coreEventTypes)) {
                $foreignKey = $this->usersJoinForeignKeys2[$eventType];
                $middleEntityType = $this->usersMiddles[$eventType];

                $select = SelectBuilder::create()
                    ->from($eventType)
                    ->select([
                        ["'$eventType'", 'scope'],
                        'id',
                        'name',
                        'dateStart',
                        'dateEnd',
                        [$isAllDay ? 'dateStartDate' : 'null', 'dateStartDate'],
                        [$isAllDay ? 'dateEndDate' : 'null', 'dateEndDate'],
                        'modifiedAt',
                        'description',
                        'status',
                        [$hasLocation ? 'location' : 'null', 'location'],
                        [$hasCLocation ? 'cLocation' : 'null', 'cLocation'],
                        [$hasUid ? 'uid' : 'null', 'uid'],
                        [$hasJoinUrl ? 'joinUrl' : 'null', 'joinUrl'],
                    ])
                    ->leftJoin($middleEntityType, 'middle', [
                        "middle.$foreignKey:" => 'id',
                        'middle.deleted' => false,
                    ])
                    ->where([
                        'dateStart>=' => $since,
                        'middle.userId' => $userId,
                        [
                            'OR' => [
                                ['assignedUserId' => $userId],
                                ['assignedUserId' => null],
                                ['assignedUserId!=' => $userWithIntegrationIdList],
                            ],
                        ],
                        [
                            'OR' => [
                                ['msxGoogleCalendarEventId' => ''],
                                ['msxGoogleCalendarEventId' => null],
                            ],
                        ],
                        'status!=' => 'Not Held',
                    ])
                    ->build();

                $queryList[] = $select;

                continue;
            }

            $select = SelectBuilder::create()
                ->from($eventType)
                ->select([
                    ["'$eventType'", 'scope'],
                    'id',
                    'name',
                    'dateStart',
                    'dateEnd',
                    [$isAllDay ? 'dateStartDate' : 'null', 'dateStartDate'],
                    [$isAllDay ? 'dateEndDate' : 'null', 'dateEndDate'],
                    'modifiedAt',
                    'description',
                    'status',
                    [$hasLocation ? 'location' : 'null', 'location'],
                    [$hasCLocation ? 'cLocation' : 'null', 'cLocation'],
                    [$hasUid ? 'uid' : 'null', 'uid'],
                    [$hasJoinUrl ? 'joinUrl' : 'null', 'joinUrl'],
                ])
                ->leftJoin('MsxGoogleCalendarEvent', 'gce', [
                    'gce.entityId:' => 'id',
                    'gce.entityType' => $eventType,
                ])
                ->where([
                    'dateStart>=' => $since,
                    'assignedUserId' => $userId,
                    [
                        'OR' => [
                            ['gce.msxGoogleCalendarEventId' => ''],
                            ['gce.msxGoogleCalendarEventId' => null],
                        ],
                    ],
                    'status!=' => 'Not Held',
                ])
                ->build();

            $queryList[] = $select;
        }

        if ($queryList === []) {
            return [];
        }

        $result = [];

        $builder = UnionBuilder::create();

        foreach ($queryList as $select) {
            $builder->query($select);
        }

        $union = $builder
            ->order('dateStart', 'DESC')
            ->limit(0, $limit)
            ->build();

        try {
            $sth = $this->entityManager->getQueryExecutor()->execute($union);
            $rows = $sth->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $attendees = $this->getEventAttendees($row['scope'], $row['id']);
                $result[] = array_merge($row, ['attendees' => $attendees]);
            }
        } catch (Exception $e) {
            $GLOBALS['log']->error("MsxGoogleCalendar: Failed query: " . $e->getMessage());
        }

        return $result;
    }

    /**
     * @param string[] $eventTypes
     */
    public function deleteRecurrentInstancesFromEspo(
        string $calendarId,
        string $googleCalendarEventId,
        array $eventTypes
    ): void {
        $eventTypes = $this->validateEventTypes($eventTypes);

        foreach ($eventTypes as $eventType) {
            if (in_array($eventType, $this->coreEventTypes)) {
                $update = UpdateBuilder::create()
                    ->in($eventType)
                    ->set([
                        'deleted' => true,
                        'msxGoogleCalendarId' => null,
                        'msxGoogleCalendarEventId' => null,
                    ])
                    ->where([
                        'msxGoogleCalendarId' => $calendarId,
                        'msxGoogleCalendarEventId*' => $googleCalendarEventId . '_%',
                    ])
                    ->build();

                try {
                    $this->entityManager->getQueryExecutor()->execute($update);
                } catch (Exception $e) {
                    $GLOBALS['log']->error("MsxGoogleCalendar: Failed query: " . $e->getMessage());
                }

                continue;
            }

            $select = SelectBuilder::create()
                ->select('id')
                ->from('MsxGoogleCalendarEvent')
                ->where([
                    'entityType' => $eventType,
                    'msxGoogleCalendarId' => $calendarId,
                    'msxGoogleCalendarEventId*' => $googleCalendarEventId . '_%',
                ])
                ->withDeleted()
                ->build();

            $sthSelect = $this->entityManager->getQueryExecutor()->execute($select);
            $ids = $sthSelect->fetchAll(PDO::FETCH_COLUMN, 0);

            if (!$ids) {
                continue;
            }

            $select = SelectBuilder::create()
                ->select('entityId')
                ->from('MsxGoogleCalendarEvent')
                ->where([
                    'entityType' => $eventType,
                    'msxGoogleCalendarId' => $calendarId,
                    'msxGoogleCalendarEventId*' => $googleCalendarEventId . '_%',
                ])
                ->withDeleted()
                ->build();

            $sthSelect = $this->entityManager->getQueryExecutor()->execute($select);
            $entityIds = $sthSelect->fetchAll(PDO::FETCH_COLUMN, 0);

            $update = UpdateBuilder::create()
                ->in($eventType)
                ->set(['deleted' => true])
                ->where(['id' => $entityIds])
                ->build();

            $this->entityManager->getQueryExecutor()->execute($update);

            $delete = DeleteBuilder::create()
                ->from('MsxGoogleCalendarEvent')
                ->where(['id' => $ids])
                ->build();

            $this->entityManager->getQueryExecutor()->execute($delete);
        }

        $this->removeRecurrentEventFromQueueByEventId($googleCalendarEventId);
    }

    public function storeEventRelation(
        string $entityType,
        string $entityId,
        string $msxGoogleCalendarId,
        ?string $msxGoogleCalendarEventId = null
    ): void {
        if (in_array($entityType, $this->coreEventTypes)) {
            $set = ['msxGoogleCalendarId' => $msxGoogleCalendarId];

            if ($msxGoogleCalendarEventId) {
                $set['msxGoogleCalendarEventId'] = $msxGoogleCalendarEventId;
            }

            $query = UpdateBuilder::create()
                ->in($entityType)
                ->set($set)
                ->where(['id' => $entityId])
                ->build();
        } else {
            $data = $this->getEventEntityMsxGoogleData($entityType, $entityId);

            if ($data && isset($data['id'])) {
                $set = ['msxGoogleCalendarId' => $msxGoogleCalendarId];

                if ($msxGoogleCalendarEventId) {
                    $set['msxGoogleCalendarEventId'] = $msxGoogleCalendarEventId;
                }

                $query = UpdateBuilder::create()
                    ->in('MsxGoogleCalendarEvent')
                    ->set($set)
                    ->where(['id' => $data['id']])
                    ->build();
            } else {
                $this->entityManager->createEntity('MsxGoogleCalendarEvent', [
                    'entityType' => $entityType,
                    'entityId' => $entityId,
                    'msxGoogleCalendarId' => $msxGoogleCalendarId,
                    'msxGoogleCalendarEventId' => $msxGoogleCalendarEventId,
                ]);

                return;
            }
        }

        $this->entityManager->getQueryExecutor()->execute($query);
    }

    public function resetEventRelation(string $entityType, string $entityId): bool
    {
        if (in_array($entityType, $this->coreEventTypes)) {
            $query = UpdateBuilder::create()
                ->in($entityType)
                ->set([
                    'msxGoogleCalendarId' => null,
                    'msxGoogleCalendarEventId' => null,
                ])
                ->where(['id' => $entityId])
                ->build();
        } else {
            $query = DeleteBuilder::create()
                ->from('MsxGoogleCalendarEvent')
                ->where([
                    'entityId' => $entityId,
                    'entityType' => $entityType,
                ])
                ->build();
        }

        try {
            $this->entityManager->getQueryExecutor()->execute($query);

            return true;
        } catch (Exception $e) {
            $GLOBALS['log']->error("MsxGoogleCalendar: Failed query: " . $e->getMessage());
        }

        return false;
    }

    /**
     * @return array<string, mixed>|false
     */
    public function getEventEntityMsxGoogleData(string $entityType, string $entityId)
    {
        $query = SelectBuilder::create()
            ->from('MsxGoogleCalendarEvent')
            ->select([
                'id',
                'msxGoogleCalendarId',
                'msxGoogleCalendarEventId',
            ])
            ->where([
                'entityId' => $entityId,
                'entityType' => $entityType,
            ])
            ->build();

        $sth = $this->entityManager->getQueryExecutor()->execute($query);

        return $sth->fetch(PDO::FETCH_ASSOC);
    }
}
