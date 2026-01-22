<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Classes\FieldProcessing\ChatwootConversation;

use Espo\ORM\Entity;
use Espo\Core\FieldProcessing\Loader as LoaderInterface;
use Espo\Core\FieldProcessing\Loader\Params;
use Espo\Core\ORM\EntityManager;
use Espo\Modules\Crm\Entities\Task;

/**
 * Loads child tasks for ChatwootConversation entities.
 * Since tasks use hasChildren relationship (parent-child pattern),
 * we need to load them separately as they don't have automatic linkMultiple attributes.
 *
 * @implements LoaderInterface<Entity>
 */
class TasksLoader implements LoaderInterface
{
    public function __construct(
        private EntityManager $entityManager
    ) {}

    public function process(Entity $entity, Params $params): void
    {
        // Only process if tasksIds or tasksNames is requested in select
        if ($params->hasSelect()) {
            if (!$params->hasInSelect('tasksIds') && !$params->hasInSelect('tasksNames') && !$params->hasInSelect('tasksColumns')) {
                return;
            }
        }

        // Fetch tasks linked to this conversation via parent relationship
        $taskData = $this->fetchTaskData($entity);

        $entity->set('tasksIds', $taskData['ids']);
        $entity->set('tasksNames', $taskData['names']);
        $entity->set('tasksColumns', $taskData['columns']);
    }

    /**
     * Fetch tasks linked to the entity via parent relationship.
     *
     * @return array{ids: string[], names: array<string, string>, columns: array<string, array{status: ?string, dateEnd: ?string}>}
     */
    private function fetchTaskData(Entity $entity): array
    {
        $ids = [];
        $names = [];
        $columns = [];

        /** @var iterable<Task> $collection */
        $collection = $this->entityManager
            ->getRDBRepository(Task::ENTITY_TYPE)
            ->select(['id', 'name', 'status', 'dateEnd'])
            ->where([
                'parentType' => $entity->getEntityType(),
                'parentId' => $entity->getId(),
                'deleted' => false,
            ])
            ->order('createdAt', 'DESC')
            ->limit(50) // Limit to prevent too many tasks
            ->find();

        foreach ($collection as $task) {
            $taskId = $task->getId();
            $ids[] = $taskId;
            $names[$taskId] = $task->getName() ?? 'Task';
            $columns[$taskId] = [
                'status' => $task->getStatus(),
                'dateEnd' => $task->get('dateEnd'),
            ];
        }

        return [
            'ids' => $ids,
            'names' => $names,
            'columns' => $columns,
        ];
    }
}
