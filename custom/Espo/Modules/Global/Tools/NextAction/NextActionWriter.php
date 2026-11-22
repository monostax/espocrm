<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Tools\NextAction;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Utils\Metadata;
use Espo\Modules\Global\Classes\NextAction\PendingActivity;
use Espo\Modules\Global\Classes\NextAction\TenantActivities;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * A record's next action (Próximo passo): pick an activity, create a task, move an activity here, complete it, save
 * the reference. Shared by the people's picker (Global/Controllers/RecordNextAction, which lets only people in) and
 * the opportunity watcher (the AI's CRM user). Every check runs as the given ACL's user through the record services;
 * callers decide who may act and hold the record's lock.
 */
class NextActionWriter
{
    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private ServiceContainer $records,
        private Metadata $metadata,
    ) {}

    /** The reference the caller saw must still be the record's. */
    public function checkExpected(Entity $record, mixed $expectedId, mixed $expectedType): void
    {
        if (($record->get('nextActionId') ?: null) !== $expectedId ||
            ($record->get('nextActionType') ?: null) !== $expectedType) {
            throw new Conflict('The next action has changed. Refresh and try again.');
        }
    }

    /**
     * A pending task on the record, created through the record service for validation, assignments, ACL and activity
     * hooks. `$attributes` adds to or overrides the defaults (name, assignee, teams, deadline...).
     */
    public function createTask(Entity $record, object $attributes): Entity
    {
        $data = get_object_vars($attributes);
        $name = $data['name'] ?? null;
        unset($data['name']);
        $created = $this->records->get('Task')->create((object) [
            'name' => $name,
            'status' => 'Planned',
            'parentType' => $record->getEntityType(),
            'parentId' => $record->getId(),
            ...$data,
        ])->getEntity();
        $activity = $this->activity($record, 'Task', $created->getId());
        $this->checkPending($activity);
        return $activity;
    }

    /**
     * The record's own activity, locked. With `$allowUnlinked`, also an unlinked one or any of the record's workspace
     * (the Activities inbox reach), which {@see moveHere()} then moves to the record.
     */
    public function activity(Entity $record, mixed $type, mixed $id, bool $allowUnlinked = false): Entity
    {
        if (!in_array($type, ['Task', 'Call', 'Meeting'], true) || !is_string($id) || $id === '') {
            throw new BadRequest('A supported activity reference is required.');
        }
        $parent = ['parentType' => $record->getEntityType(), 'parentId' => $record->getId()];
        $where = $parent;
        if ($allowUnlinked) {
            $options = [$parent, ['parentId' => null]];
            $tenant = TenantActivities::where($this->entityManager, $type, $record->get('tenantId'));
            if ($tenant) {
                $options[] = $tenant;
            }
            $where = ['OR' => $options];
        }
        $activity = $this->entityManager->getRDBRepository($type)->where([
            'id' => $id, ...$where,
        ])->forUpdate()->findOne();
        if (!$activity) {
            throw new NotFound();
        }
        if (!$this->acl->checkEntityRead($activity) || !$this->acl->checkField($type, 'status')) {
            throw new Forbidden();
        }
        return $activity;
    }

    public function belongsTo(Entity $activity, Entity $record): bool
    {
        return $activity->get('parentType') === $record->getEntityType() && $activity->get('parentId') === $record->getId();
    }

    /** An unlinked activity or one elsewhere in the workspace becomes the record's: deal buckets follow the parent. */
    public function moveHere(Entity $activity, Entity $record): void
    {
        $type = $activity->getEntityType();
        if (!$this->acl->checkEntityEdit($activity) || !$this->acl->checkField($type, 'parent', 'edit')) {
            throw new Forbidden();
        }
        $this->checkNotAnotherStep($activity);
        $this->records->get($type)->update($activity->getId(), (object) [
            'parentType' => $record->getEntityType(),
            'parentId' => $record->getId(),
        ]);
    }

    public function checkPending(Entity $activity): void
    {
        if (!PendingActivity::isPending($activity, $this->metadata)) {
            throw new Conflict('Choose an unfinished, active activity.');
        }
    }

    public function isPending(Entity $activity): bool
    {
        return PendingActivity::isPending($activity, $this->metadata);
    }

    /** The activity's first completed status, as "Concluir" sets it. */
    public function complete(Entity $activity): void
    {
        $this->checkStatusEdit($activity);
        $status = $this->metadata->get(['scopes', $activity->getEntityType(), 'completedStatusList', 0]);
        if (!$status) {
            throw new BadRequest('Activity completion is not configured.');
        }
        $this->records->get($activity->getEntityType())->update($activity->getId(), (object) ['status' => $status]);
    }

    public function setStatus(Entity $activity, string $status): void
    {
        $this->checkStatusEdit($activity);
        $this->records->get($activity->getEntityType())->update($activity->getId(), (object) ['status' => $status]);
    }

    private function checkStatusEdit(Entity $activity): void
    {
        $type = $activity->getEntityType();
        if (!$this->acl->checkEntityEdit($activity) || !$this->acl->checkField($type, 'status', 'edit')) {
            throw new Forbidden();
        }
    }

    /**
     * Saves the reference on a record the caller reloaded under its lock: activity hooks can advance the record's
     * version in the same transaction, so a pre-hook copy must not be saved.
     */
    public function save(Entity $record, ?Entity $activity, array $options = []): object
    {
        $record->set('nextActionId', $activity?->getId());
        $record->set('nextActionType', $activity?->getEntityType());
        $this->entityManager->saveEntity($record, $options);
        $result = (object) [
            'id' => $record->getId(),
            'nextActionId' => $record->get('nextActionId'),
            'nextActionType' => $record->get('nextActionType'),
        ];
        // A person's step pauses the AI follow-up (PauseFollowupForPersonStep); return the result.
        if ($record->hasAttribute('followupTimer')) {
            $result->followupTimer = $record->get('followupTimer');
            $result->followupStatus = $record->get('followupStatus');
        }
        return $result;
    }

    /** Moving another deal's or initiative's current step would silently empty its slot. */
    private function checkNotAnotherStep(Entity $activity): void
    {
        $type = $activity->get('parentType');
        if (!in_array($type, ['Opportunity', 'Initiative'], true) || !$activity->get('parentId')) {
            return;
        }
        // Lock the other record so its own next-action selection cannot race this move.
        $other = $this->entityManager->getRDBRepository($type)
            ->where(['id' => $activity->get('parentId')])->forUpdate()->findOne();
        if ($other && $other->get('nextActionId') === $activity->getId() &&
            $other->get('nextActionType') === $activity->getEntityType()) {
            throw new Conflict('This activity is already the next step of another record.');
        }
    }
}
