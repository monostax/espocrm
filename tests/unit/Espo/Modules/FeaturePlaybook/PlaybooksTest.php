<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeaturePlaybook;

use Espo\Core\Acl;
use Espo\Core\ApplicationState;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\CreateResult;
use Espo\Core\Record\Service;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Record\UpdateResult;
use Espo\Entities\User;
use Espo\Modules\FeaturePlaybook\Hooks\Task\PlaybookProgress;
use Espo\Modules\FeaturePlaybook\Services\Definitions;
use Espo\Modules\FeaturePlaybook\Services\Playbooks;
use Espo\Modules\FeaturePlaybook\Services\Progress;
use Espo\Modules\Global\Tools\Acl\TeamsAccess;
use Espo\ORM\BaseEntity;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\ORM\TransactionManager;
use PHPUnit\Framework\TestCase;

/** Exercises service contracts with real entities; ORM and Task service boundaries are mocked. */
class PlaybooksTest extends TestCase
{
    private array $rows = [];
    private int $sequence = 0;
    private array $denied = [];
    private Playbooks $service;
    private PlaybookProgress $hook;
    private Service $tasks;

    protected function setUp(): void
    {
        $em = $this->createMock(EntityManager::class);
        $tx = $this->createMock(TransactionManager::class);
        $tx->method('run')->willReturnCallback(function ($callback) {
            $snapshot = unserialize(serialize($this->rows));
            try {
                return $callback();
            } catch (\Throwable $error) {
                $this->rows = $snapshot;
                throw $error;
            }
        });
        $em->method('getTransactionManager')->willReturn($tx);
        $em->method('getNewEntity')->willReturnCallback(fn ($type) => $this->entity($type));
        $em->method('createEntity')->willReturnCallback(function ($type, $values) {
            $entity = $this->entity($type, (array) $values);
            $this->store($entity);
            return $entity;
        });
        $em->method('saveEntity')->willReturnCallback(fn ($entity) => $this->store($entity));
        $em->method('removeEntity')->willReturnCallback(function ($entity) {
            unset($this->rows[$entity->getEntityType()][$entity->getId()]);
        });
        $em->method('getEntityById')->willReturnCallback(fn ($type, $id) => isset($this->rows[$type][$id]) ? clone $this->rows[$type][$id] : null);
        $em->method('getRDBRepository')->willReturnCallback(function ($type) {
            $repo = $this->createMock(RDBRepository::class);
            $deletedQuery = $this->createMock(RDBSelectBuilder::class);
            $deletedQuery->method('where')->willReturnCallback(fn ($where) => $repo->where($where));
            $repo->method('clone')->willReturn($deletedQuery);
            $repo->method('where')->willReturnCallback(function ($where) use ($type) {
                $query = $this->createMock(RDBSelectBuilder::class);
                $query->method('forUpdate')->willReturnSelf();
                $query->method('order')->willReturnSelf();
                $matches = function () use ($type, $where) {
                    $rows = array_filter($this->rows[$type] ?? [], function ($row) use ($where) {
                        if (!array_key_exists('deleted', $where) && $row->get('deleted')) return false;
                        foreach ($where as $key => $value) {
                            if ($row->get($key) !== $value) return false;
                        }
                        return true;
                    });
                    return array_map(fn ($row) => clone $row, array_values($rows));
                };
                $query->method('findOne')->willReturnCallback(fn () => $matches()[0] ?? null);
                $query->method('find')->willReturnCallback(fn () => new EntityCollection($matches()));
                $query->method('count')->willReturnCallback(fn () => count($matches()));
                return $query;
            });
            return $repo;
        });
        $user = $this->createMock(User::class);
        $user->method('isRegular')->willReturn(true);
        $user->method('getId')->willReturn('operator');
        $acl = $this->createMock(Acl::class);
        $acl->method('checkEntityRead')->willReturnCallback(fn ($entity) => !in_array($entity->getId(), $this->denied, true));
        $acl->method('checkEntityEdit')->willReturnCallback(fn ($entity) => !in_array($entity->getId(), $this->denied, true));
        $acl->method('checkEntity')->willReturnCallback(fn ($entity) => !in_array($entity->getId(), $this->denied, true));
        $acl->method('checkScope')->willReturn(true);
        $acl->method('checkField')->willReturn(true);
        $teams = $this->createMock(TeamsAccess::class);
        $teams->method('userSharesTeam')->willReturn(true);
        $teams->method('entityTeamIds')->willReturn(['team']);
        $progress = new Progress($em, $user);
        $state = $this->createMock(ApplicationState::class);
        $state->method('isLogged')->willReturn(true);
        $this->hook = new PlaybookProgress($em, $progress, $acl, $state);
        $records = $this->createMock(ServiceContainer::class);
        $this->tasks = $this->createMock(Service::class);
        $records->method('get')->with('Task')->willReturn($this->tasks);
        $this->tasks->method('create')->willReturnCallback(function ($values) {
            $entity = $this->entity('Task', (array) $values);
            $this->store($entity);
            return new CreateResult($entity);
        });
        $this->tasks->method('update')->willReturnCallback(function ($id, $values) {
            $entity = clone $this->rows['Task'][$id];
            $entity->set((array) $values);
            $this->hook->beforeSave($entity, []);
            $this->rows['Task'][$id] = clone $entity;
            $this->hook->afterSave($entity, []);
            $this->store($entity);
            return new UpdateResult($entity);
        });
        $this->service = new Playbooks($em, $acl, $user, $records, $teams, $progress);
        $this->store($this->entity('Opportunity', ['id' => 'deal', 'tenantId' => 'tenant', 'assignedUserId' => 'owner']));
        $this->store($this->entity('Opportunity', ['id' => 'other', 'tenantId' => 'other-tenant', 'assignedUserId' => 'owner']));
    }

    public function testPlainStepsDoNotCreateTasksAndReopeningUpdatesProgress(): void
    {
        $this->tasks->expects($this->never())->method('create');
        $run = $this->blank();
        $completed = $this->step($run, 'Completed');
        $this->assertSame('Completed', $completed->status);
        $this->assertSame(1, $completed->completed);
        $this->assertSame('operator', $completed->steps[0]->completedById);
        $reopened = $this->step($run, 'Pending');
        $this->assertSame('Active', $reopened->status);
        $this->assertNull($reopened->steps[0]->completedAt);
        $skipped = $this->step($run, 'Skipped');
        $this->assertSame('Completed', $skipped->status);
        $this->assertSame(0, $skipped->completed);
        $this->assertSame(1, $skipped->skipped);
        $this->assertSame(1, $skipped->total);
    }

    public function testRetryApplicationAndIntentionalRepeatAreDistinct(): void
    {
        $run = $this->blank();
        $retry = $this->blank();
        $repeat = $this->blank('a-different-request-key');
        $this->assertSame($run->id, $retry->id);
        $this->assertNotSame($run->id, $repeat->id);
        $this->step($run, 'Completed');
        $runs = $this->service->overview('deal')->runs;
        $this->assertSame(0, $runs[1]->completed);
    }

    public function testRequestKeyCannotBeReusedForDifferentInput(): void
    {
        $this->blank();
        $this->expectException(Conflict::class);
        $this->service->apply('deal', (object) ['requestKey' => 'application-request-key', 'name' => 'Different']);
    }

    public function testTemplateEditsAndArchivalPreserveSnapshots(): void
    {
        $template = $this->service->saveTemplate('deal', (object) [
            'name' => 'Qualification', 'status' => 'Published',
            'steps' => [(object) ['name' => 'Decision maker', 'instructions' => 'Original instructions']],
        ]);
        $run = $this->service->apply('deal', (object) ['requestKey' => 'template-request-key', 'templateId' => $template->id]);
        $this->service->saveTemplate('deal', (object) [
            'id' => $template->id, 'expectedRevision' => 1, 'name' => 'Updated', 'status' => 'Archived',
            'steps' => [(object) ['name' => 'New step', 'instructions' => 'Changed']],
        ]);
        $persisted = $this->service->overview('deal')->runs[0];
        $this->assertSame($run->id, $persisted->id);
        $this->assertSame(1, $persisted->sourceRevision);
        $this->assertSame('Original instructions', $persisted->steps[0]->instructions);
        $this->expectException(Conflict::class);
        $this->service->apply('deal', (object) ['requestKey' => 'archived-request-key', 'templateId' => $template->id]);
    }

    public function testTaskActivationIsIdempotentAndActivitiesCompletionSynchronizes(): void
    {
        $this->tasks->expects($this->once())->method('create');
        $run = $this->blank(kind: 'Task');
        $body = (object) ['action' => 'activate', 'stepId' => $run->steps[0]->id];
        $activated = $this->service->mutate('deal', $run->id, $body);
        $retry = $this->service->mutate('deal', $run->id, $body);
        $this->assertSame($activated->steps[0]->taskId, $retry->steps[0]->taskId);
        $this->tasks->update($activated->steps[0]->taskId, (object) ['status' => 'Completed']);
        $this->assertSame('Completed', $this->service->overview('deal')->runs[0]->status);
        $reopened = $this->step($run, 'Pending');
        $this->assertSame('Active', $reopened->status);
        $this->assertSame('Planned', $this->rows['Task'][$activated->steps[0]->taskId]->get('status'));
    }

    public function testStopCancelsPendingTasksAndResumeDoesNotRestartThem(): void
    {
        $run = $this->blank(kind: 'Task');
        $activated = $this->service->mutate('deal', $run->id, (object) ['action' => 'activate', 'stepId' => $run->steps[0]->id]);
        $taskId = $activated->steps[0]->taskId;
        $stopped = $this->service->mutate('deal', $run->id, (object) ['action' => 'stop', 'reason' => 'Prospect replied']);
        $this->assertSame('Stopped', $stopped->status);
        $this->assertSame(0, $stopped->completed);
        $this->assertSame('Canceled', $this->rows['Task'][$taskId]->get('status'));
        try {
            $this->tasks->update($taskId, (object) ['status' => 'Planned']);
            $this->fail('A stopped run must not restart through a Task edit.');
        } catch (Conflict) {
            $this->assertSame('Canceled', $this->rows['Task'][$taskId]->get('status'));
        }
        $this->service->mutate('deal', $run->id, (object) ['action' => 'resume']);
        $this->assertSame('Canceled', $this->rows['Task'][$taskId]->get('status'));
        $this->step($run, 'Pending');
        $this->assertSame('Planned', $this->rows['Task'][$taskId]->get('status'));
    }

    public function testStopRollsBackWhenLinkedTaskCannotBeEdited(): void
    {
        $run = $this->blank(kind: 'Task');
        $activated = $this->service->mutate('deal', $run->id, (object) ['action' => 'activate', 'stepId' => $run->steps[0]->id]);
        $this->denied[] = $activated->steps[0]->taskId;
        try {
            $this->service->mutate('deal', $run->id, (object) ['action' => 'stop', 'reason' => 'Stop']);
            $this->fail('Task permission must be required.');
        } catch (Forbidden) {
            $this->assertSame('Active', $this->rows['PlaybookRun'][$run->id]->get('status'));
            $this->assertSame('Planned', $this->rows['Task'][$activated->steps[0]->taskId]->get('status'));
        }
    }

    public function testDeletedTaskRemainsLinkedAndCanBeExplicitlySkipped(): void
    {
        $run = $this->blank(kind: 'Task');
        $body = (object) ['action' => 'activate', 'stepId' => $run->steps[0]->id];
        $activated = $this->service->mutate('deal', $run->id, $body);
        $task = $this->rows['Task'][$activated->steps[0]->taskId];
        unset($this->rows['Task'][$task->getId()]);
        $this->hook->afterRemove($task, []);
        $retry = $this->service->mutate('deal', $run->id, $body);
        $this->assertTrue($retry->steps[0]->hasTask);
        $this->assertNull($retry->steps[0]->taskId);
        $this->assertSame('Cancelled', $retry->steps[0]->status);
        $this->assertSame('Completed', $this->step($run, 'Skipped')->status);
    }

    public function testRunCannotBeMutatedThroughAnotherOpportunity(): void
    {
        $run = $this->blank();
        $this->expectException(NotFound::class);
        $this->service->mutate('other', $run->id, (object) ['action' => 'stop', 'reason' => 'No']);
    }

    public function testOpportunityCascadeCanRemovePlaybookTask(): void
    {
        $run = $this->blank(kind: 'Task');
        $activated = $this->service->mutate('deal', $run->id, (object) ['action' => 'activate', 'stepId' => $run->steps[0]->id]);
        $task = $this->rows['Task'][$activated->steps[0]->taskId];
        $this->rows['Opportunity']['deal']->set('deleted', true);

        $this->hook->beforeRemove($task, []);
        $this->hook->afterRemove($task, []);

        $this->assertSame('Cancelled', $this->rows['PlaybookRunStep'][$run->steps[0]->id]->get('status'));
    }

    public function testDeletedOpportunityStillRequiresDeleteAccessForTaskRemoval(): void
    {
        $run = $this->blank(kind: 'Task');
        $activated = $this->service->mutate('deal', $run->id, (object) ['action' => 'activate', 'stepId' => $run->steps[0]->id]);
        $this->rows['Opportunity']['deal']->set('deleted', true);
        $this->denied[] = 'deal';

        $this->expectException(Forbidden::class);
        $this->hook->beforeRemove($this->rows['Task'][$activated->steps[0]->taskId], []);
    }

    public function testDeletedOpportunityDoesNotAllowTaskEdits(): void
    {
        $run = $this->blank(kind: 'Task');
        $activated = $this->service->mutate('deal', $run->id, (object) ['action' => 'activate', 'stepId' => $run->steps[0]->id]);
        $this->rows['Opportunity']['deal']->set('deleted', true);

        $this->expectException(Forbidden::class);
        $this->tasks->update($activated->steps[0]->taskId, (object) ['status' => 'Completed']);
    }

    public function testOpportunityReadPermissionIsRequired(): void
    {
        $this->blank();
        $this->denied[] = 'deal';
        $this->expectException(Forbidden::class);
        $this->service->overview('deal');
    }

    public function testTemplatesCannotCrossTenantBoundaries(): void
    {
        $template = $this->service->saveTemplate('deal', (object) [
            'name' => 'Private', 'status' => 'Published', 'steps' => [(object) ['name' => 'Check']],
        ]);
        $this->expectException(Forbidden::class);
        $this->service->apply('other', (object) ['templateId' => $template->id, 'requestKey' => 'cross-tenant-request']);
    }

    public function testUnsafeReferenceUrlsAreRejected(): void
    {
        $this->expectException(BadRequest::class);
        Definitions::step((object) ['name' => 'Unsafe', 'references' => ['javascript:alert(1)']], 0);
    }

    public function testDraftCannotBeApplied(): void
    {
        $template = $this->service->saveTemplate('deal', (object) ['name' => 'Draft', 'status' => 'Draft', 'steps' => []]);
        $this->expectException(Conflict::class);
        $this->service->apply('deal', (object) ['templateId' => $template->id, 'requestKey' => 'draft-application-key']);
    }

    public function testConcurrentTemplateEditRequiresCurrentRevision(): void
    {
        $template = $this->service->saveTemplate('deal', (object) ['name' => 'Draft', 'steps' => []]);
        $this->expectException(Conflict::class);
        $this->service->saveTemplate('deal', (object) ['id' => $template->id, 'expectedRevision' => 0, 'name' => 'Stale', 'steps' => []]);
    }

    public function testUnactivatedTaskCannotBeCheckedOff(): void
    {
        $run = $this->blank(kind: 'Task');
        $this->expectException(Conflict::class);
        $this->step($run, 'Completed');
    }

    public function testDeletingCompletedTaskKeepsItsHistory(): void
    {
        $run = $this->blank(kind: 'Task');
        $activated = $this->service->mutate('deal', $run->id, (object) ['action' => 'activate', 'stepId' => $run->steps[0]->id]);
        $completed = $this->step($run, 'Completed');
        $task = $this->rows['Task'][$activated->steps[0]->taskId];
        unset($this->rows['Task'][$task->getId()]);
        $this->hook->afterRemove($task, []);
        $persisted = $this->service->overview('deal')->runs[0];
        $this->assertSame('Completed', $persisted->status);
        $this->assertSame($completed->steps[0]->completedAt, $persisted->steps[0]->completedAt);
        $this->assertSame('operator', $persisted->steps[0]->completedById);
    }

    public function testGenericRecordServicesAreUnavailable(): void
    {
        foreach (['Playbook', 'PlaybookStep', 'PlaybookRun', 'PlaybookRunStep'] as $name) {
            $class = 'Espo\\Modules\\FeaturePlaybook\\Services\\' . $name;
            try {
                new $class();
                $this->fail('Generic record services must not bypass opportunity access.');
            } catch (Forbidden) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testActivitiesCannotBypassOpportunityEditAccess(): void
    {
        $run = $this->blank(kind: 'Task');
        $activated = $this->service->mutate('deal', $run->id, (object) ['action' => 'activate', 'stepId' => $run->steps[0]->id]);
        $this->denied[] = 'deal';
        $this->expectException(Forbidden::class);
        $this->tasks->update($activated->steps[0]->taskId, (object) ['status' => 'Completed']);
    }

    private function blank(string $key = 'application-request-key', string $kind = 'Check'): object
    {
        return $this->service->apply('deal', (object) [
            'requestKey' => $key, 'name' => 'Checklist', 'steps' => [(object) ['name' => 'First', 'kind' => $kind]],
        ]);
    }

    private function step(object $run, string $status): object
    {
        return $this->service->mutate('deal', $run->id, (object) ['action' => 'step', 'stepId' => $run->steps[0]->id, 'status' => $status]);
    }

    private function store(Entity $entity): void
    {
        if (!$entity->hasId()) $entity->set('id', 'record-' . ++$this->sequence);
        $entity->setAsFetched();
        $this->rows[$entity->getEntityType()][$entity->getId()] = clone $entity;
    }

    private function entity(string $type, array $values = []): Entity
    {
        $names = ['id', 'name', 'kind', 'instructions', 'status', 'tenantId', 'assignedUserId', 'assignedUserName',
            'requestKey', 'requestHash', 'playbookId', 'opportunityId', 'runId', 'taskId', 'completedAt',
            'completedById', 'completedByName', 'createdAt', 'createdByName', 'stopReason', 'parentType', 'parentId'];
        $attributes = array_fill_keys($names, ['type' => 'varchar']);
        foreach (['revision', 'sourceRevision', 'position'] as $name) $attributes[$name] = ['type' => 'int'];
        $attributes['references'] = ['type' => 'jsonArray'];
        $attributes['teamsIds'] = ['type' => 'jsonArray'];
        $attributes['deleted'] = ['type' => 'bool'];
        $entity = new BaseEntity($type, ['attributes' => $attributes]);
        $entity->set($values);
        return $entity;
    }
}
