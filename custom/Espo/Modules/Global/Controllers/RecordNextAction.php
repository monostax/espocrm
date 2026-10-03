<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Controllers;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;

abstract class RecordNextAction
{
    public function __construct(
        protected EntityManager $entityManager,
        private Acl $acl,
        private User $user,
        private ServiceContainer $records,
        private Metadata $metadata,
    ) {}

    abstract protected function entityType(): string;

    protected function checkMutable(Entity $record): void
    {}

    protected function taskTeamIds(Entity $record): array
    {
        /** @var \Espo\Core\ORM\Entity $record */
        return $record->getLinkMultipleIdList('teams');
    }

    public function postActionSelect(Request $request): object
    {
        return $this->entityManager->getTransactionManager()->run(function () use ($request): object {
            $record = $this->record($request);
            $body = $request->getParsedBody();
            $this->checkExpected($record, $body);

            if ($body->createTask ?? false) {
                if (!is_string($body->name ?? null) || trim($body->name) === '') {
                    throw new BadRequest('An actionable task name is required.');
                }
                // Use normal record creation for validation, assignments, ACL and activity hooks.
                $created = $this->records->get('Task')->create((object) [
                    'name' => trim($body->name),
                    'status' => 'Not Started',
                    'parentType' => $record->getEntityType(),
                    'parentId' => $record->getId(),
                    'assignedUserId' => $record->get('assignedUserId') ?: $this->user->getId(),
                    'teamsIds' => $this->taskTeamIds($record),
                    'dateEndDate' => $body->dateEndDate ?? null,
                ])->getEntity();
                $activity = $this->activity($record, 'Task', $created->getId());
                $this->checkPending($activity);
            } elseif ($body->activityId ?? null) {
                $activity = $this->activity($record, $body->activityType ?? null, $body->activityId);
                $this->checkPending($activity);
            } else {
                $activity = null;
            }

            return $this->saveSelection($record, $activity);
        });
    }

    public function postActionComplete(Request $request): object
    {
        return $this->entityManager->getTransactionManager()->run(function () use ($request): object {
            $record = $this->record($request);
            $this->checkExpected($record, $request->getParsedBody());
            $activity = $this->activity(
                $record, $record->get('nextActionType'), $record->get('nextActionId'),
            );
            $this->checkPending($activity);
            $type = $activity->getEntityType();
            if (!$this->acl->checkEntityEdit($activity) || !$this->acl->checkField($type, 'status', 'edit')) {
                throw new Forbidden();
            }
            $status = $this->metadata->get(['scopes', $type, 'completedStatusList', 0]);
            if (!$status) {
                throw new BadRequest('Activity completion is not configured.');
            }
            $this->records->get($type)->update($activity->getId(), (object) ['status' => $status]);

            // Completion and clearing the reference either both succeed or both roll back.
            return $this->saveSelection($record, null);
        });
    }

    private function record(Request $request): Entity
    {
        $record = $this->entityManager->getRDBRepository($this->entityType())
            ->where(['id' => (string) $request->getRouteParam('id')])->forUpdate()->findOne();
        if (!$record) {
            throw new NotFound();
        }
        if ((!$this->user->isRegular() && !$this->user->isAdmin()) ||
            !$this->acl->checkEntityRead($record) || !$this->acl->checkEntityEdit($record)) {
            throw new Forbidden();
        }
        $this->checkMutable($record);
        return $record;
    }

    private function checkExpected(Entity $record, stdClass $body): void
    {
        if (!property_exists($body, 'expectedId') || !property_exists($body, 'expectedType')) {
            throw new BadRequest('The displayed next-action reference is required.');
        }
        if (($record->get('nextActionId') ?: null) !== $body->expectedId ||
            ($record->get('nextActionType') ?: null) !== $body->expectedType) {
            throw new Conflict('The next action has changed. Refresh and try again.');
        }
    }

    private function activity(Entity $record, mixed $type, mixed $id): Entity
    {
        if (!in_array($type, ['Task', 'Call', 'Meeting'], true) || !is_string($id) || $id === '') {
            throw new BadRequest('A supported activity reference is required.');
        }
        $activity = $this->entityManager->getRDBRepository($type)->where([
            'id' => $id, 'parentType' => $record->getEntityType(), 'parentId' => $record->getId(),
        ])->forUpdate()->findOne();
        if (!$activity) {
            throw new NotFound();
        }
        if (!$this->acl->checkEntityRead($activity) || !$this->acl->checkField($type, 'status')) {
            throw new Forbidden();
        }
        return $activity;
    }

    private function checkPending(Entity $activity): void
    {
        $type = $activity->getEntityType();
        $pending = $type === 'Task'
            ? !in_array($activity->get('status'), $this->metadata->get([
                'entityDefs', 'Task', 'fields', 'status', 'notActualOptions',
            ]) ?? [], true)
            : in_array($activity->get('status'), $this->metadata->get(['scopes', $type, 'activityStatusList']) ?? [], true);
        if (!$pending) {
            throw new Conflict('Choose an unfinished, active activity.');
        }
    }

    private function saveSelection(Entity $record, ?Entity $activity): object
    {
        $record->set('nextActionId', $activity?->getId());
        $record->set('nextActionType', $activity?->getEntityType());
        $this->entityManager->saveEntity($record);
        return (object) [
            'id' => $record->getId(),
            'nextActionId' => $record->get('nextActionId'),
            'nextActionType' => $record->get('nextActionType'),
        ];
    }
}
