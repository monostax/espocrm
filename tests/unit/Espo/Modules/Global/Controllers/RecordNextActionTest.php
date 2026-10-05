<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Controllers;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\ORM\Entity;
use Espo\Core\Record\CreateResult;
use Espo\Core\Record\Service;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Record\UpdateResult;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Controllers\OpportunityNextAction;
use Espo\Modules\FeatureInitiative\Controllers\InitiativeNextAction;
use Espo\Modules\Global\Controllers\RecordNextAction;
use Espo\ORM\EntityManager;
use Espo\ORM\QueryComposer\QueryComposer;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\ORM\TransactionManager;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Both controllers exercise the same contract, including real transaction rollback. */
class RecordNextActionTest extends TestCase
{
    private PDO $pdo;
    private Entity $record;
    private Entity $initiativeType;
    private array $activities = [];
    private array $denied = [];
    private array $created = [];
    private array $updated = [];
    private bool $failSave = false;
    private bool $regular = true;

    private function entity(string $type, array $values): Entity
    {
        $attributes = array_fill_keys([
            'id', 'name', 'status', 'nextActionId', 'nextActionType', 'initiativeTypeId',
            'parentType', 'parentId', 'assignedUserId', 'dateEndDate',
        ], ['type' => 'varchar']);
        $attributes['teamsIds'] = ['type' => 'jsonArray'];
        $entity = new Entity($type, ['attributes' => $attributes]);
        $entity->set($values);
        $entity->setAsNotNew();
        $entity->updateFetchedValues();
        return $entity;
    }

    private function controller(string $type = 'Initiative'): RecordNextAction
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE record (id TEXT, next_action_id TEXT, next_action_type TEXT)');
        $this->pdo->exec('CREATE TABLE activity (id TEXT, status TEXT)');
        $this->pdo->exec("INSERT INTO record VALUES ('record-id', NULL, NULL)");
        $this->record = $this->entity($type, [
            'id' => 'record-id', 'status' => $type === 'Opportunity' ? 'Open' : 'To Do',
            'assignedUserId' => 'owner-id', 'teamsIds' => ['opportunity-team'], 'initiativeTypeId' => 'type-id',
        ]);
        $this->initiativeType = $this->entity('InitiativeType', ['id' => 'type-id', 'teamsIds' => ['live-type-team']]);
        $em = $this->createMock(EntityManager::class);
        $em->method('getTransactionManager')->willReturn(new TransactionManager(
            $this->pdo, $this->createMock(QueryComposer::class),
        ));
        $em->method('getEntityById')->with('InitiativeType', 'type-id')->willReturn($this->initiativeType);
        $em->method('getRDBRepository')->willReturnCallback(function (string $entityType) use ($type) {
            $repository = $this->createMock(RDBRepository::class);
            $repository->method('where')->willReturnCallback(function (array $where) use ($entityType, $type) {
                $query = $this->createMock(RDBSelectBuilder::class);
                $query->expects($this->once())->method('forUpdate')->willReturnSelf();
                $query->method('findOne')->willReturnCallback(function () use ($entityType, $type, $where) {
                    $this->assertTrue($this->pdo->inTransaction());
                    if ($entityType === $type) {
                        $this->assertSame(['id' => 'record-id'], $where);
                        return $this->record;
                    }
                    // Query predicates, not just the activity ID, enforce the parent boundary.
                    $parent = $where['OR'][0] ?? $where;
                    $this->assertSame($type, $parent['parentType']);
                    $this->assertSame('record-id', $parent['parentId']);
                    $activity = $this->activities[$entityType . ':' . $where['id']] ?? null;
                    if ($activity && isset($where['OR']) && !$activity->get('parentId')) {
                        $this->assertSame(['parentId' => null], $where['OR'][1]);
                        return $activity;
                    }
                    return $activity && $activity->get('parentType') === $parent['parentType'] &&
                        $activity->get('parentId') === $parent['parentId'] ? $activity : null;
                });
                return $query;
            });
            return $repository;
        });
        $em->method('saveEntity')->willReturnCallback(function (Entity $entity): void {
            $this->assertTrue($this->pdo->inTransaction());
            $this->pdo->prepare('UPDATE record SET next_action_id = ?, next_action_type = ? WHERE id = ?')
                ->execute([$entity->get('nextActionId'), $entity->get('nextActionType'), $entity->getId()]);
            if ($this->failSave) {
                throw new RuntimeException('Record save failed.');
            }
        });
        $acl = $this->createMock(Acl::class);
        $acl->method('checkEntityRead')->willReturnCallback(
            fn ($entity) => !in_array($entity === $this->record ? 'recordRead' : 'activityRead', $this->denied, true),
        );
        $acl->method('checkEntityEdit')->willReturnCallback(
            fn ($entity) => !in_array($entity === $this->record ? 'recordEdit' : 'activityEdit', $this->denied, true),
        );
        $acl->method('checkField')->willReturnCallback(
            fn ($scope, $field, $action = 'read') => !in_array($field . ucfirst($action), $this->denied, true),
        );
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('actor-id');
        $user->method('isRegular')->willReturnCallback(fn () => $this->regular);
        $services = $this->createMock(ServiceContainer::class);
        $services->method('get')->willReturnCallback(function (string $activityType) {
            $service = $this->createMock(Service::class);
            $service->method('create')->willReturnCallback(function (object $data) use ($activityType) {
                $this->assertTrue($this->pdo->inTransaction());
                $this->created[] = (array) $data;
                $activity = $this->addActivity($activityType, ['id' => 'created-id'] + (array) $data);
                return new CreateResult($activity);
            });
            $service->method('update')->willReturnCallback(function (string $id, object $data) use ($activityType) {
                $this->assertTrue($this->pdo->inTransaction());
                $this->updated[] = [$activityType, $id, (array) $data];
                if (property_exists($data, 'status')) {
                    $this->pdo->prepare('UPDATE activity SET status = ? WHERE id = ?')->execute([$data->status, $id]);
                }
                $activity = $this->activities[$activityType . ':' . $id];
                $activity->set($data);
                return new UpdateResult($activity);
            });
            return $service;
        });
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturnCallback(fn ($path) => match ($path) {
            ['entityDefs', 'Task', 'fields', 'status', 'notActualOptions'] => ['Completed', 'Canceled', 'Deferred'],
            ['scopes', 'Call', 'activityStatusList'], ['scopes', 'Meeting', 'activityStatusList'] => ['Planned'],
            ['scopes', 'Task', 'completedStatusList', 0] => 'Completed',
            ['scopes', 'Call', 'completedStatusList', 0], ['scopes', 'Meeting', 'completedStatusList', 0] => 'Held',
            default => null,
        });
        $class = $type === 'Opportunity' ? OpportunityNextAction::class : InitiativeNextAction::class;
        return new $class($em, $acl, $user, $services, $metadata);
    }

    private function addActivity(string $type, array $values = []): Entity
    {
        $activity = $this->entity($type, $values + [
            'id' => 'activity-id', 'parentType' => $this->record->getEntityType(),
            'parentId' => 'record-id', 'status' => 'Planned',
        ]);
        $this->activities[$type . ':' . $activity->getId()] = $activity;
        $this->pdo->prepare('INSERT INTO activity VALUES (?, ?)')->execute([$activity->getId(), $activity->get('status')]);
        return $activity;
    }

    private function selectActivity(string $type): void
    {
        $this->record->set(['nextActionId' => 'activity-id', 'nextActionType' => $type]);
        $this->pdo->prepare('UPDATE record SET next_action_id = ?, next_action_type = ?')->execute(['activity-id', $type]);
    }

    private function request(array $data = []): Request
    {
        $request = $this->createMock(Request::class);
        $request->method('getRouteParam')->with('id')->willReturn('record-id');
        $request->method('getParsedBody')->willReturn((object) ($data + [
            'expectedId' => $this->record->get('nextActionId') ?: null,
            'expectedType' => $this->record->get('nextActionType') ?: null,
        ]));
        return $request;
    }

    public static function activities(): array
    {
        $cases = [];
        foreach (['Opportunity', 'Initiative'] as $recordType) {
            foreach (['Task', 'Call', 'Meeting'] as $activityType) {
                $cases["$recordType:$activityType"] = [$recordType, $activityType];
            }
        }
        return $cases;
    }

    #[DataProvider('activities')]
    public function testSelectAndCompleteShareTheSameContract(string $recordType, string $activityType): void
    {
        $controller = $this->controller($recordType);
        $this->addActivity($activityType);
        $result = $controller->postActionSelect($this->request(['activityId' => 'activity-id', 'activityType' => $activityType]));
        $this->assertSame(['id' => 'record-id', 'nextActionId' => 'activity-id', 'nextActionType' => $activityType], (array) $result);
        $this->assertFalse($this->pdo->inTransaction());
        $result = $controller->postActionComplete($this->request());
        $this->assertSame(['id' => 'record-id', 'nextActionId' => null, 'nextActionType' => null], (array) $result);
        $status = $activityType === 'Task' ? 'Completed' : 'Held';
        $this->assertSame([[$activityType, 'activity-id', ['status' => $status]]], $this->updated);
        $this->assertSame($status, $this->pdo->query('SELECT status FROM activity')->fetchColumn());
    }

    public static function taskDefaults(): array
    {
        return [
            ['Opportunity', 'owner-id', ['opportunity-team'], '2026-10-05'],
            ['Initiative', 'owner-id', ['live-type-team'], '2026-10-05'],
            ['Initiative', null, ['live-type-team'], null],
        ];
    }

    #[DataProvider('taskDefaults')]
    public function testTaskCreationUsesRecordServicesAndLiveOwnership(string $type, ?string $owner, array $teams, ?string $date): void
    {
        $controller = $this->controller($type);
        $this->record->set('assignedUserId', $owner);
        $result = $controller->postActionSelect($this->request([
            'createTask' => true, 'name' => '  Collect documents  ', 'dateEndDate' => $date,
        ]));
        $this->assertSame([[
            'name' => 'Collect documents', 'status' => 'Planned', 'parentType' => $type, 'parentId' => 'record-id',
            'assignedUserId' => $owner ?: 'actor-id', 'teamsIds' => $teams, 'dateEndDate' => $date,
        ]], $this->created);
        $this->assertSame('created-id', $result->nextActionId);
        $this->assertSame('Task', $result->nextActionType);
    }

    #[DataProvider('activities')]
    public function testFailedClearRollsBackActivityCompletion(string $recordType, string $activityType): void
    {
        $controller = $this->controller($recordType);
        $activity = $this->addActivity($activityType);
        $status = $activity->get('status');
        $this->selectActivity($activityType);
        $this->failSave = true;
        try {
            $controller->postActionComplete($this->request());
            $this->fail('Expected the record save to fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('Record save failed.', $e->getMessage());
        }
        $this->assertSame($status, $this->pdo->query('SELECT status FROM activity')->fetchColumn());
        $this->assertSame('activity-id', $this->pdo->query('SELECT next_action_id FROM record')->fetchColumn());
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testFailedSelectionRollsBackNewTask(): void
    {
        $controller = $this->controller();
        $this->failSave = true;
        try {
            $controller->postActionSelect($this->request(['createTask' => true, 'name' => 'Collect documents']));
            $this->fail('Expected the record save to fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('Record save failed.', $e->getMessage());
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM activity')->fetchColumn());
        $this->assertNull($this->pdo->query('SELECT next_action_id FROM record')->fetchColumn());
    }

    public static function staleOperations(): array
    {
        return [['Opportunity', 'Select'], ['Opportunity', 'Complete'], ['Initiative', 'Select'], ['Initiative', 'Complete']];
    }

    #[DataProvider('staleOperations')]
    public function testStaleReferencesCannotCreateOrCompleteActivities(string $type, string $operation): void
    {
        $controller = $this->controller($type);
        $this->addActivity('Task');
        $this->selectActivity('Task');
        try {
            $controller->{'postAction' . $operation}($this->request([
                'expectedId' => null, 'expectedType' => null, 'createTask' => true, 'name' => 'Stale task',
            ]));
            $this->fail('Expected a stale-reference conflict.');
        } catch (Conflict) {
            $this->assertSame([], $this->created);
            $this->assertSame([], $this->updated);
            $this->assertSame('activity-id', $this->pdo->query('SELECT next_action_id FROM record')->fetchColumn());
        }
    }

    public static function invalidActivities(): array
    {
        return [
            ['Task', ['status' => 'Completed'], Conflict::class],
            ['Task', ['status' => 'Canceled'], Conflict::class],
            ['Task', ['status' => 'Deferred'], Conflict::class],
            ['Call', ['status' => 'Held'], Conflict::class],
            ['Meeting', ['status' => 'Not Held'], Conflict::class],
            ['Task', ['parentId' => 'other-id'], NotFound::class],
            ['Task', ['parentType' => 'Opportunity'], NotFound::class],
            ['Email', [], BadRequest::class],
        ];
    }

    #[DataProvider('invalidActivities')]
    public function testInitiativesOnlySelectTheirOwnPendingSupportedActivities(string $type, array $values, string $exception): void
    {
        $controller = $this->controller();
        $this->addActivity($type, $values);
        $this->expectException($exception);
        $controller->postActionSelect($this->request(['activityId' => 'activity-id', 'activityType' => $type]));
    }

    public static function deniedOperations(): array
    {
        return [
            ['recordRead', 'Select'], ['recordEdit', 'Select'], ['activityRead', 'Select'], ['statusRead', 'Select'],
            ['activityEdit', 'Complete'], ['statusEdit', 'Complete'], ['portal', 'Select'],
        ];
    }

    #[DataProvider('deniedOperations')]
    public function testInitiativeOperationsEnforceRecordAndActivityPermissions(string $denied, string $operation): void
    {
        $controller = $this->controller();
        $this->addActivity('Task');
        $this->selectActivity('Task');
        $this->denied = [$denied];
        $this->regular = $denied !== 'portal';
        $this->expectException(Forbidden::class);
        $controller->{'postAction' . $operation}($this->request(['activityId' => 'activity-id', 'activityType' => 'Task']));
    }

    public function testEveryInitiativeStageStatusAllowsClearingWithoutDeletingTheActivity(): void
    {
        $controller = $this->controller();
        $this->addActivity('Task');
        foreach (['On Hold', 'To Do', 'Doing', 'Done'] as $status) {
            $this->record->set('status', $status);
            $this->selectActivity('Task');
            $result = $controller->postActionSelect($this->request(['activityId' => null]));
            $this->assertNull($result->nextActionId);
            $this->assertSame($status, $this->record->get('status'));
            $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM activity')->fetchColumn());
        }
        $this->assertSame([], $this->updated);
    }

    public function testClosedOpportunityStillRejectsNextActionChanges(): void
    {
        $controller = $this->controller('Opportunity');
        $this->record->set('status', 'Won');
        $this->expectException(Conflict::class);
        $controller->postActionSelect($this->request(['createTask' => true, 'name' => 'Closed task']));
    }

    public function testExpectedReferenceIsRequired(): void
    {
        $controller = $this->controller();
        $request = $this->createMock(Request::class);
        $request->method('getRouteParam')->willReturn('record-id');
        $request->method('getParsedBody')->willReturn((object) ['activityId' => null]);
        $this->expectException(BadRequest::class);
        $controller->postActionSelect($request);
    }

    public function testTaskNameIsRequired(): void
    {
        $controller = $this->controller();
        $this->expectException(BadRequest::class);
        $controller->postActionSelect($this->request(['createTask' => true, 'name' => '  ']));
    }
}
