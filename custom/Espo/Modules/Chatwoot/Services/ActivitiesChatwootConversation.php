<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Name\Field;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Modules\Crm\Entities\Call;
use Espo\Modules\Crm\Entities\Meeting;
use Espo\Modules\Crm\Entities\Task;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Select;
use Espo\ORM\Query\SelectBuilder;
use RuntimeException;

/**
 * Activities / History query provider for the ChatwootConversation scope.
 *
 * The core Activities service (`Espo\Modules\Crm\Tools\Activities\Service`)
 * matches activities to their parent via `parentType` / `parentId`. A
 * ChatwootConversation, however, is linked to Meeting / Call / Task /
 * Appointment through many-to-many relations (`chatwootConversations`),
 * so the core query would return nothing.
 *
 * The core service looks up a service named `Activities<ParentEntityType>`
 * and, when it exposes a `getActivities<Scope>Query` method, delegates the
 * query building to it. This class implements that hook so the standard
 * "Activities (Planned)" / "Activities (Held)" panels work for a
 * ChatwootConversation record.
 */
class ActivitiesChatwootConversation
{
    private const LINK = 'chatwootConversations';

    public function __construct(
        private SelectBuilderFactory $selectBuilderFactory,
        private EntityManager $entityManager
    ) {}

    /**
     * @param string[] $statusList
     */
    public function getActivitiesMeetingQuery(Entity $entity, array $statusList = []): Select
    {
        return $this->buildLinkQuery($entity, Meeting::ENTITY_TYPE, $statusList);
    }

    /**
     * @param string[] $statusList
     */
    public function getActivitiesCallQuery(Entity $entity, array $statusList = []): Select
    {
        return $this->buildLinkQuery($entity, Call::ENTITY_TYPE, $statusList);
    }

    /**
     * Task supports ChatwootConversation as `parent`, so include tasks
     * related either through the many-to-many link or through the parent.
     *
     * @param string[] $statusList
     * @return Select[]
     */
    public function getActivitiesTaskQuery(Entity $entity, array $statusList = []): array
    {
        $parentBuilder = $this->createBaseBuilder(Task::ENTITY_TYPE, $statusList)
            ->where([
                'parentId' => $entity->getId(),
                'parentType' => $entity->getEntityType(),
            ]);

        $linkBuilder = $this->createBaseBuilder(Task::ENTITY_TYPE, $statusList)
            ->join(self::LINK)
            ->where([
                self::LINK . '.id' => $entity->getId(),
                'OR' => [
                    'parentType!=' => $entity->getEntityType(),
                    'parentId!=' => $entity->getId(),
                    'parentType' => null,
                    'parentId' => null,
                ],
            ]);

        return [
            $parentBuilder->build(),
            $linkBuilder->build(),
        ];
    }

    /**
     * @param string[] $statusList
     */
    public function getActivitiesAppointmentQuery(Entity $entity, array $statusList = []): Select
    {
        return $this->buildLinkQuery($entity, 'Appointment', $statusList);
    }

    /**
     * @param string[] $statusList
     */
    private function buildLinkQuery(Entity $entity, string $scope, array $statusList): Select
    {
        return $this->createBaseBuilder($scope, $statusList)
            ->join(self::LINK)
            ->where([self::LINK . '.id' => $entity->getId()])
            ->build();
    }

    /**
     * Select the same column set the core service uses so the per-scope
     * queries can be UNION-ed together.
     *
     * @param string[] $statusList
     */
    private function createBaseBuilder(string $scope, array $statusList): SelectBuilder
    {
        $seed = $this->entityManager->getNewEntity($scope);

        $attr = fn (string $name) => $seed->hasAttribute($name) ? [$name, $name] : ['null', $name];

        try {
            $builder = $this->selectBuilderFactory
                ->create()
                ->from($scope)
                ->withStrictAccessControl()
                ->buildQueryBuilder()
                ->select([
                    'id',
                    'name',
                    $attr('dateStart'),
                    $attr('dateEnd'),
                    $attr('dateStartDate'),
                    $attr('dateEndDate'),
                    ['"' . $scope . '"', '_scope'],
                    $attr('assignedUserId'),
                    $attr('assignedUserName'),
                    $attr('parentType'),
                    $attr('parentId'),
                    'status',
                    Field::CREATED_AT,
                    ['false', 'hasAttachment'],
                    ['null', 'fromEmailAddressName'],
                    ['null', 'fromString'],
                ]);
        } catch (BadRequest|Forbidden $e) {
            throw new RuntimeException($e->getMessage());
        }

        if ($statusList !== []) {
            $builder->where(['status' => $statusList]);
        }

        return $builder;
    }
}
