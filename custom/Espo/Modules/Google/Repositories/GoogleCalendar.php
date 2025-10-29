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

namespace Espo\Modules\Google\Repositories;

use DateTime;
use Espo\Core\ORM\Entity;
use Espo\Core\Repositories\Database;
use Espo\Modules\Google\Core\Google\Items\Event;
use Espo\ORM\Query\DeleteBuilder;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\Query\UnionBuilder;
use Espo\ORM\Query\UpdateBuilder;
use Espo\Repositories\EmailAddress;
use Exception;
use PDO;

class GoogleCalendar extends Database
{
    /** @var string[]|null */
    private $allowedEventTypes = null;

    private $userWithIntegrationIdList;

    private $coreEventTypes = ['Meeting', 'Call'];

    private $usersMiddles = [
        'Meeting' => 'MeetingUser',
        'Call' => 'CallUser',
    ];

    private $usersJoinForeignKeys2 = [
        'Meeting' => 'meetingId',
        'Call' => 'callId',
    ];

    private function validateEventTypes($types)
    {
        if (!is_array($this->allowedEventTypes)) {
            $this->loadAllowedEventTypes();
        }

        $selectedEventTypes = [];
        $eventTypes = (is_array($types)) ? $types : [$types];

        foreach($eventTypes as $eventType) {
            if (in_array($eventType, $this->allowedEventTypes) &&
                !in_array($eventType, $selectedEventTypes)) {
                $selectedEventTypes[] = $eventType;
            }
        }

        return $selectedEventTypes;
    }

    private function loadAllowedEventTypes()
    {
        $scopes = $this->metadata->get('scopes');
        $allowedTypes = [];

        foreach ($scopes as $scope => $defs) {
            if (!empty($defs['activity']) &&
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
     * @param string $userId
     * @return array<string, array<string, mixed>>
     */
    public function storedUsersCalendars($userId)
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select([
                ['gcuser.id', 'id'],
                ['gcuser.type', 'type'],
                ['gcuser.googleCalendarId', 'googleCalendarId'],
                ['gcuser.active', 'active'],
                ['gc.name', 'name'],
                ['gc.calendarId', 'calendarId']
            ])
            ->from('GoogleCalendarUser', 'gcuser')
            ->join('GoogleCalendar', 'gc', ['gcuser.googleCalendarId:' => 'gc.id'])
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
            $googleCalendarId = $row['googleCalendarId'];

            $result[$type][$googleCalendarId] = $row;
        }

        return $result;
    }

    /**
     * @param string $googleCalendarId
     * @return ?Entity
     */
    public function getCalendarByGCId($googleCalendarId)
    {
        return $this->entityManager
            ->getRDBRepository('GoogleCalendar')
            ->where(['calendarId' => $googleCalendarId])
            ->findOne();
    }

    public function getEventAttendees($eventType, $eventId)
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

    public function getUsersMainCalendar($userId)
    {
        return $this->entityManager
            ->getRDBRepository('GoogleCalendarUser')
            ->where([
                'active' => true,
                'userId' => $userId,
                'type' => 'main',
            ])
            ->findOne();
    }

    public function addRecurrentEventToQueue($calendarId, $eventId)
    {
        $this->removeRecurrentEventFromQueueByEventId($eventId);

        $this->entityManager->createEntity('GoogleCalendarRecurrentEvent', [
            'googleCalendarUserId' => $calendarId,
            'eventId' => $eventId,
        ]);
    }

    public function removeRecurrentEventFromQueue($id)
    {
        $delete = DeleteBuilder::create()
            ->from('GoogleCalendarRecurrentEvent')
            ->where(['id' => $id])
            ->build();

        $this->entityManager->getQueryExecutor()->execute($delete);
    }

    public function removeRecurrentEventFromQueueByEventId($eventId)
    {
        $delete = DeleteBuilder::create()
            ->from('GoogleCalendarRecurrentEvent')
            ->where(['eventId' => $eventId])
            ->build();

        $this->entityManager->getQueryExecutor()->execute($delete);
    }

    public function getRecurrentEventFromQueue($calendarId)
    {
        $maxRange = new DateTime();
        $maxRange->modify('+6 months');

        $select = SelectBuilder::create()
            ->from('GoogleCalendarRecurrentEvent')
            ->select([
                'id',
                'eventId',
                'pageToken',
                ['lastLoadedEventTime', 'lastEventTime']
            ])
            ->where([
                'googleCalendarUserId' => $calendarId,
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
        }
        catch (Exception $e) {
            $GLOBALS['log']->error("GoogleCalendarERROR: Failed query: " . $e->getMessage());

            return false;
        }
    }

    public function updateRecurrentEvent($id, $pageToken = '', $lastEventTime = null)
    {
        $update = UpdateBuilder::create()
            ->in('GoogleCalendarRecurrentEvent')
            ->set([
                'pageToken' => $pageToken,
                'lastLoadedEventTime' => !empty($lastEventTime) ? $lastEventTime : null,
            ])
            ->where(['id' => $id])
            ->build();

        try {
            $this->entityManager->getQueryExecutor()->execute($update);
        }
        catch (Exception $e) {
            $GLOBALS['log']->error("GoogleCalendarERROR: Failed query: " . $e->getMessage());
        }
    }

    public function isCalendarActive($email)
    {
        $calendar = $this
            ->where(['calendarId' => $email])
            ->findOne();

        return !empty($calendar);
    }

    /**
     * @param string $userId
     * @param Event $googleEvent
     * @param string[] $eventTypes
     * @return Entity[]
     */
    public function findEspoEntitiesForGoogleEvent($userId, $googleEvent, $eventTypes)
    {
        $eventId = $googleEvent->getId();
        $uid = $googleEvent->getICalUID();

        if (!$eventId) {
            return [];
        }

        $results = [];

        $eventTypes = $this->validateEventTypes($eventTypes);

        foreach ($eventTypes as $eventType) {
            if (in_array($eventType, $this->coreEventTypes)) {
                $where = ['googleCalendarEventId' => $eventId];

                if ($this->metadata->get("entityDefs.$eventType.fields.uid") && $uid) {
                    $where = [
                        'OR' => [
                            $where,
                            [
                                'uid' => $uid,
                                // Only if not already connected with another user/calendar.
                                'googleCalendarEventId' => null,
                            ],
                        ]
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
                ->from('GoogleCalendarEvent', 'gce')
                ->join($eventType, 'entityTable', [
                    'entityTable.id:' => 'gce.entityId',
                    'entityTable.deleted' => false,
                ])
                ->where([
                    'entityTable.assignedUserId' => $userId,
                    'gce.googleCalendarEventId' => $eventId,
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
                $GLOBALS['log']->error("GoogleCalendarERROR: Failed query: " . $e->getMessage());

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
     * @param string $userId
     * @param string[] $eventTypes
     * @param string $since
     * @param string $to
     * @param string $lastEventId
     * @param string $googleCalendarId
     * @param int $limit
     * @return array<string, mixed>[]
     */
    public function getEvents(
        $userId,
        $eventTypes,
        $since,
        $to,
        $lastEventId,
        $googleCalendarId,
        $limit = 20
    ) {

        $googleCalendar = $this->getCalendarByGCId($googleCalendarId);

        if (empty($googleCalendar)) {
            return [];
        }

        $lowerLimitWhere = $lastEventId ?
            [
                'OR' => [
                    'modifiedAt>' => $since,
                    [
                        'modifiedAt' => $since,
                        'id>' => $lastEventId,
                    ]
                ]
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
                        'googleCalendarEventId',
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
                        ['googleCalendarEventId!=' => ''],
                        ['googleCalendarEventId!=' => 'FAIL'],
                        ['googleCalendarEventId!=' => null],
                        'googleCalendarId' => $googleCalendar->get('id'),
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
                    ['gce.googleCalendarEventId', 'googleCalendarEventId'],
                    'modifiedAt',
                    'description',
                    'deleted',
                    'status',
                    [$hasLocation ? 'location' : 'null', 'location'],
                    [$hasCLocation ? 'cLocation' : 'null', 'cLocation'],
                    [$hasJoinUrl ? 'joinUrl' : 'null', 'joinUrl'],
                ])
                ->leftJoin('GoogleCalendarEvent', 'gce', [
                    'gce.entityId:' => 'id',
                    'gce.entityType' => $eventType,
                ])
                ->where([
                    'assignedUserId' => $userId,
                    ['gce.googleCalendarEventId!=' => ''],
                    ['gce.googleCalendarEventId!=' => 'FAIL'],
                    ['gce.googleCalendarEventId!=' => null],
                    'gce.googleCalendarId' => $googleCalendar->get('id'),
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
            $GLOBALS['log']->error("GoogleCalendarERROR: Failed query: " . $e->getMessage());
        }

        return $result;
    }

    protected function getUserWithPushIntegrationIdList()
    {
        if (isset($this->userWithIntegrationIdList)) {
            return $this->userWithIntegrationIdList;
        }

        $userList = $this->entityManager
            ->getRDBRepository('User')
            ->select(['id'])
            ->where([
                'type' => ['admin', 'regular'],
                'isActive' => true,
            ])
            ->find();

        $userWithIntegrationIdList = [];

        foreach ($userList as $user) {
            $ea = $this->entityManager->getRepository('ExternalAccount')
                ->get('Google__' . $user->get('id'));

            if ($ea->get('googleCalendarEnabled') && $ea->get('calendarDirection') !== 'GCToEspo') {
                $userWithIntegrationIdList[] = $user->get('id');
            }
        }

        $this->userWithIntegrationIdList = $userWithIntegrationIdList;

        return $userWithIntegrationIdList;
    }

    /**
     * @param string $userId
     * @param string[] $eventTypes
     * @param string $since
     * @param int $limit
     * @return array<string, mixed>[]
     */
    public function getNewEvents($userId, $eventTypes, $since, $limit = 20)
    {
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
                            ]
                        ],
                        [
                            'OR' => [
                                ['googleCalendarEventId' => ''],
                                ['googleCalendarEventId' => null]
                            ]
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
                ->leftJoin('GoogleCalendarEvent', 'gce', [
                    'gce.entityId:' => 'id',
                    'gce.entityType' => $eventType,
                ])
                ->where([
                    'dateStart>=' => $since,
                    'assignedUserId' => $userId,
                    [
                        'OR' => [
                            ['gce.googleCalendarEventId' => ''],
                            ['gce.googleCalendarEventId' => null]
                        ]
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
            $GLOBALS['log']->error("GoogleCalendarERROR: Failed query: " . $e->getMessage());
        }

        return $result;
    }

    public function deleteRecurrentInstancesFromEspo($calendarId, $googleCalendarEventId, $eventTypes)
    {
        $eventTypes = $this->validateEventTypes($eventTypes);

        foreach ($eventTypes as $eventType) {
            if (in_array($eventType, $this->coreEventTypes)) {
                $update = UpdateBuilder::create()
                    ->in($eventType)
                    ->set([
                        'deleted' => true,
                        'googleCalendarId' => null,
                        'googleCalendarEventId' => null,
                    ])
                    ->where([
                        'googleCalendarId' => $calendarId,
                        'googleCalendarEventId*' => $googleCalendarEventId . '_%',
                    ])
                    ->build();

                try {
                    $this->entityManager->getQueryExecutor()->execute($update);
                }
                catch (Exception $e) {
                    $GLOBALS['log']->error("GoogleCalendarERROR: Failed query: " . $e->getMessage());
                }

                continue;
            }

            $select = SelectBuilder::create()
                ->select('id')
                ->from('GoogleCalendarEvent')
                ->where([
                    'entityType' => $eventType,
                    'googleCalendarId' => $calendarId,
                    'googleCalendarEventId*' => $googleCalendarEventId . '_%',
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
                ->from('GoogleCalendarEvent')
                ->where([
                    'entityType' => $eventType,
                    'googleCalendarId' => $calendarId,
                    'googleCalendarEventId*' => $googleCalendarEventId . '_%',
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
                ->from('GoogleCalendarEvent')
                ->where(['id' => $ids])
                ->build();

            $this->entityManager->getQueryExecutor()->execute($delete);
        }

        $this->removeRecurrentEventFromQueueByEventId($googleCalendarEventId);
    }

    /**
     * @param string $googleCalendarId
     * @param ?string $googleCalendarEventId
     */
    public function storeEventRelation(
        string $entityType,
        string $entityId,
        $googleCalendarId,
        $googleCalendarEventId = null
    ) {
        if (in_array($entityType, $this->coreEventTypes)) {
            $set = ['googleCalendarId' => $googleCalendarId];

            if ($googleCalendarEventId) {
                $set['googleCalendarEventId'] = $googleCalendarEventId;
            }

            $query = UpdateBuilder::create()
                ->in($entityType)
                ->set($set)
                ->where(['id' => $entityId])
                ->build();
        } else {
            $data = $this->getEventEntityGoogleData($entityType, $entityId);

            if ($data && isset($data['id'])) {
                $set = ['googleCalendarId' => $googleCalendarId];

                if ($googleCalendarEventId) {
                    $set['googleCalendarEventId'] = $googleCalendarEventId;
                }

                $query = UpdateBuilder::create()
                    ->in('GoogleCalendarEvent')
                    ->set($set)
                    ->where(['id' => $data['id']])
                    ->build();
            } else {
                $this->entityManager->createEntity('GoogleCalendarEvent', [
                    'entityType' => $entityType,
                    'entityId' => $entityId,
                    'googleCalendarId' => $googleCalendarId,
                    'googleCalendarEventId' => $googleCalendarEventId,
                ]);

                return;
            }
        }

        $this->entityManager->getQueryExecutor()->execute($query);
    }

    public function resetEventRelation($entityType, $entityId)
    {
        if (in_array($entityType, $this->coreEventTypes)) {
            $query = UpdateBuilder::create()
                ->in($entityType)
                ->set([
                    'googleCalendarId' => null,
                    'googleCalendarEventId' => null,
                ])
                ->where(['id' => $entityId])
                ->build();
        }
        else {
            $query = DeleteBuilder::create()
                ->from('GoogleCalendarEvent')
                ->where([
                    'entityId' => $entityId,
                    'entityType' => $entityType,
                ])
                ->build();
        }

        try {
            $this->entityManager->getQueryExecutor()->execute($query);

            return true;
        }
        catch (Exception $e) {
            $GLOBALS['log']->error("GoogleCalendarERROR: Failed query :" . $e->getMessage());
        }

        return false;
    }

    public function getEventEntityGoogleData($entityType, $entityId)
    {
        $query = SelectBuilder::create()
            ->from('GoogleCalendarEvent')
            ->select([
                'id',
                'googleCalendarId',
                'googleCalendarEventId',
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
