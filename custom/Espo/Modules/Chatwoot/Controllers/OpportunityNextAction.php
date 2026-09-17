<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

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

class OpportunityNextAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private User $user,
        private ServiceContainer $records,
        private Metadata $metadata,
    ) {}

    public function postActionSelect(Request $request): object
    {
        return $this->entityManager->getTransactionManager()->run(function () use ($request): object {
            $opportunity = $this->opportunity($request);
            $body = $request->getParsedBody();
            $this->checkExpected($opportunity, $body);

            if ($body->createTask ?? false) {
                if (!is_string($body->name ?? null) || trim($body->name) === '') {
                    throw new BadRequest('An actionable task name is required.');
                }
                // Use normal record creation for validation, assignments, ACL and activity hooks.
                $created = $this->records->get('Task')->create((object) [
                    'name' => trim($body->name),
                    'status' => 'Not Started',
                    'parentType' => 'Opportunity',
                    'parentId' => $opportunity->getId(),
                    'assignedUserId' => $opportunity->get('assignedUserId') ?: $this->user->getId(),
                    'teamsIds' => $opportunity->getLinkMultipleIdList('teams'),
                    'dateEndDate' => $body->dateEndDate ?? null,
                ])->getEntity();
                $activity = $this->activity($opportunity, 'Task', $created->getId());
                $this->checkPending($activity);
            } elseif ($body->activityId ?? null) {
                $activity = $this->activity($opportunity, $body->activityType ?? null, $body->activityId);
                $this->checkPending($activity);
            } else {
                $activity = null;
            }

            return $this->saveSelection($opportunity, $activity);
        });
    }

    public function postActionComplete(Request $request): object
    {
        return $this->entityManager->getTransactionManager()->run(function () use ($request): object {
            $opportunity = $this->opportunity($request);
            $this->checkExpected($opportunity, $request->getParsedBody());
            $activity = $this->activity(
                $opportunity, $opportunity->get('nextActionType'), $opportunity->get('nextActionId'),
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
            return $this->saveSelection($opportunity, null);
        });
    }

    private function opportunity(Request $request): Entity
    {
        $opportunity = $this->entityManager->getRDBRepository('Opportunity')
            ->where(['id' => (string) $request->getRouteParam('id')])->forUpdate()->findOne();
        if (!$opportunity) {
            throw new NotFound();
        }
        if ((!$this->user->isRegular() && !$this->user->isAdmin()) ||
            !$this->acl->checkEntityRead($opportunity) || !$this->acl->checkEntityEdit($opportunity)) {
            throw new Forbidden();
        }
        if ($opportunity->get('status') !== 'Open') {
            throw new Conflict('The opportunity is closed.');
        }
        return $opportunity;
    }

    private function checkExpected(Entity $opportunity, stdClass $body): void
    {
        if (!property_exists($body, 'expectedId') || !property_exists($body, 'expectedType')) {
            throw new BadRequest('The displayed next-action reference is required.');
        }
        if (($opportunity->get('nextActionId') ?: null) !== $body->expectedId ||
            ($opportunity->get('nextActionType') ?: null) !== $body->expectedType) {
            throw new Conflict('The next action has changed. Refresh and try again.');
        }
    }

    private function activity(Entity $opportunity, mixed $type, mixed $id): Entity
    {
        if (!in_array($type, ['Task', 'Call', 'Meeting'], true) || !is_string($id) || $id === '') {
            throw new BadRequest('A supported activity reference is required.');
        }
        $activity = $this->entityManager->getRDBRepository($type)->where([
            'id' => $id, 'parentType' => 'Opportunity', 'parentId' => $opportunity->getId(),
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

    private function saveSelection(Entity $opportunity, ?Entity $activity): object
    {
        $opportunity->set('nextActionId', $activity?->getId());
        $opportunity->set('nextActionType', $activity?->getEntityType());
        $this->entityManager->saveEntity($opportunity);
        return (object) [
            'id' => $opportunity->getId(),
            'nextActionId' => $opportunity->get('nextActionId'),
            'nextActionType' => $opportunity->get('nextActionType'),
        ];
    }
}
