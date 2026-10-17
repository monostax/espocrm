<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\CreateResult;
use Espo\Core\Record\ReadResult;
use Espo\Core\Record\UpdateResult;
use Espo\Core\Record\Service;
use Espo\Core\Record\ServiceContainer;
use Espo\Modules\Chatwoot\Controllers\ActivityInbox;
use Espo\Modules\Chatwoot\Controllers\CallInbox;
use Espo\Modules\Chatwoot\Services\ActivityInbox as Inbox;
use Espo\Modules\Chatwoot\Services\ActivityDiscussion;
use Espo\Modules\Chatwoot\Tools\Activities\Access;
use Espo\Modules\Chatwoot\Tools\Activities\MeetingCalendarLink;
use Espo\Modules\FeatureTaskRecurrence\Services\Recurrence;
use Espo\ORM\BaseEntity;
use Espo\Tools\Stream\MassNotePreparator;
use PHPUnit\Framework\TestCase;

final class CallInboxTest extends TestCase
{
    private Access $access;
    private ActivityDiscussion $discussion;
    private Service $records;
    private Inbox $inbox;
    private BaseEntity $call;
    private BaseEntity $tenant;
    private array $controllers;

    protected function setUp(): void
    {
        $this->tenant = new BaseEntity('Tenant', ['attributes' => ['id' => ['type' => 'varchar']]]);
        $this->tenant->set('id', 'tenant-a');
        $this->call = new BaseEntity('Call', ['attributes' => [
            'id' => ['type' => 'varchar'], 'name' => ['type' => 'varchar'], 'versionNumber' => ['type' => 'int'],
        ]]);
        $this->call->set(['id' => 'one', 'name' => 'Demo', 'versionNumber' => 5]);
        $this->access = $this->createMock(Access::class);
        $this->access->method('type')->willReturnArgument(0);
        $this->access->method('workspace')->willReturnCallback(function ($accountId) {
            if ($accountId !== 6) throw new Forbidden();
            return $this->tenant;
        });
        $this->access->method('record')->willReturnCallback(function ($type, $id, $tenant, $stream = false) {
            self::assertSame($this->tenant, $tenant);
            if ($stream && $id === 'forbidden') throw new Forbidden();
            if ($type !== 'Call' || $id !== 'one') throw new NotFound();
            return $this->call;
        });
        $this->discussion = $this->createMock(ActivityDiscussion::class);
        $this->records = $this->createMock(Service::class);
        $this->records->method('read')->with('one')->willReturn(new ReadResult($this->call));
        $services = $this->createMock(ServiceContainer::class);
        $services->method('get')->with('Call')->willReturn($this->records);
        $this->inbox = $this->createMock(Inbox::class);
        $this->inbox->method('present')->willReturnCallback(fn ($entity) => (object) ((array) $entity->getValueMap() + ['entityType' => $entity->getEntityType()]));
        $this->inbox->method('conversations')->willReturn([]);
        $dependencies = [$this->inbox, $this->access, $this->discussion, $services, $this->createMock(MassNotePreparator::class), $this->createMock(Recurrence::class), $this->createMock(MeetingCalendarLink::class)];
        $this->controllers = [new CallInbox(...$dependencies), new ActivityInbox(...$dependencies)];
    }

    private function request(array $body = [], array $query = [], string $id = 'one', ?string $version = null): Request
    {
        $request = $this->createMock(Request::class);
        $request->method('getQueryParam')->willReturnCallback(fn ($name) => ($query + ['accountId' => '6'])[$name] ?? null);
        $request->method('getRouteParam')->willReturnCallback(fn ($name) => ['type' => 'Call', 'id' => $id][$name] ?? null);
        $request->method('getParsedBody')->willReturn((object) $body);
        $request->method('getHeader')->willReturnCallback(fn ($name) => $name === 'X-Version-Number' ? $version : null);
        return $request;
    }

    public function testCanonicalAndCompatibilityReadShareIdentityDiscussionAndReadState(): void
    {
        $this->discussion->expects(self::exactly(2))->method('stream')->with($this->call, self::anything(), 'root', 42)
            ->willReturn((object) ['list' => [(object) ['id' => 'post']], 'readState' => ['version' => 3]]);
        $this->discussion->expects(self::exactly(2))->method('mark')->with($this->call, (object) ['unread' => true])->willReturn(['version' => 4]);
        $request = $this->request(['unread' => true], ['rootId' => 'root', 'beforeNumber' => '42']);
        foreach (['getActionRead', 'getActionStream', 'postActionReadState'] as $method) {
            self::assertEquals($this->controllers[0]->$method($request), $this->controllers[1]->$method($request));
        }
    }

    public function testCreateUpdateDeleteShareTeamValidationAndVersionProtection(): void
    {
        $this->access->expects(self::exactly(4))->method('validateTeams')->with(self::anything(), $this->tenant, self::anything());
        $this->records->expects(self::exactly(2))->method('create')->willReturn(new CreateResult($this->call));
        $this->records->expects(self::exactly(2))->method('delete')->with('one');
        $this->records->expects(self::exactly(2))->method('update')->willReturnCallback(function ($id, $data, $params) {
            self::assertSame('one', $id);
            self::assertSame('Changed', $data->name);
            self::assertSame(5, $params->getVersionNumber());
            self::assertTrue($params->requiresVersion());
            return new UpdateResult($this->call);
        });
        foreach ($this->controllers as $controller) {
            self::assertSame('Call', $controller->postActionCreate($this->request(['name' => 'Demo']))->entityType);
            self::assertSame('one', $controller->putActionUpdate($this->request(['name' => 'Changed'], version: '5'))->id);
            self::assertTrue($controller->deleteActionDelete($this->request()));
        }
    }

    public function testConflictsPropagateThroughBothEndpointFamilies(): void
    {
        $this->records->method('update')->willThrowException(new Conflict());
        foreach ($this->controllers as $controller) {
            try {
                $controller->putActionUpdate($this->request(['name' => 'Changed'], version: '4'));
                self::fail('Conflict swallowed.');
            } catch (Conflict) { self::assertTrue(true); }
        }
    }

    public function testForeignAccountCannotReadOrMutate(): void
    {
        $this->records->expects(self::never())->method('update');
        foreach ($this->controllers as $controller) {
            foreach (['getActionRead', 'postActionCreate', 'putActionUpdate', 'deleteActionDelete', 'postActionReadState', 'getActionStream'] as $method) {
                try {
                    $controller->$method($this->request(query: ['accountId' => '7']));
                    self::fail('Foreign workspace accepted.');
                } catch (Forbidden) { self::assertTrue(true); }
            }
        }
    }

    public function testCallOperationsRejectExplicitIncompatibleTypes(): void
    {
        $this->records->expects(self::never())->method('create');
        $this->records->expects(self::never())->method('update');
        foreach (['Task', 'Meeting'] as $type) {
            foreach ([$this->request(['entityType' => $type]), $this->request(['type' => $type]), $this->request(query: ['type' => $type])] as $request) {
                foreach (['getActionRead', 'postActionCreate', 'putActionUpdate', 'deleteActionDelete', 'postActionReadState', 'getActionStream', 'postActionPost', 'getActionOptions', 'postActionBulkReadState'] as $method) {
                    try {
                        $this->controllers[0]->$method($request);
                        self::fail('Other type accepted.');
                    } catch (BadRequest) { self::assertTrue(true); }
                }
            }
        }
    }

    public function testMissingCallDoesNotSearchOtherEntityTypes(): void
    {
        $this->access->expects(self::once())->method('record')->with('Call', 'missing', $this->tenant, false);
        $this->expectException(NotFound::class);
        $this->controllers[0]->getActionRead($this->request(id: 'missing'));
    }

    public function testReservedPathsCannotBeUsedAsRecordIds(): void
    {
        $this->access->expects(self::never())->method('record');
        foreach (['counts', 'metadata', 'options', 'bulkReadState', 'action', 'posts', 'readState'] as $id) {
            try {
                $this->controllers[0]->getActionRead($this->request(id: $id));
                self::fail('Reserved inbox path accepted as record.');
            } catch (BadRequest) { self::assertTrue(true); }
        }
    }

    public function testBulkKeepsPartialResultsAuthorizationAndLimit(): void
    {
        $this->discussion->method('mark')->with($this->call)->willReturn(['version' => 4]);
        foreach ($this->controllers as $controller) {
            $result = $controller->postActionBulkReadState($this->request(['ids' => ['Call:one', 'Task:one', 'Meeting:one', 'Call:missing', 'Call:forbidden'], 'unread' => false]));
            self::assertSame(['Call:one'], array_keys((array) $result->states));
            self::assertSame(['Task:one', 'Meeting:one', 'Call:missing', 'Call:forbidden'], $result->failedIds);
        }
        $this->expectException(BadRequest::class);
        $this->controllers[0]->postActionBulkReadState($this->request(['ids' => array_fill(0, 101, 'Call:one'), 'unread' => false]));
    }

    public function testControllersOwnBoundariesBeforeCallingSharedServices(): void
    {
        $this->inbox->expects(self::exactly(2))->method('list')->willReturnCallback(function ($tenant, $filters, $types) {
            self::assertContains($types, [['Call'], ['Task']]);
            return (object) ['types' => $types];
        });
        self::assertSame(['Call'], $this->controllers[0]->getActionList($this->request())->types);
        self::assertSame(['Task'], $this->controllers[1]->getActionList($this->request())->types);
        $this->inbox->expects(self::once())->method('metadata')->with(['Call'], [])->willReturn((object) []);
        $this->inbox->expects(self::once())->method('counts')->with($this->tenant, [], ['Call'])->willReturn((object) []);
        $this->controllers[0]->getActionMetadata($this->request());
        $this->controllers[0]->getActionCounts($this->request());
    }

    public function testHundredItemBulkAndDuplicateHandlingRemainCompatible(): void
    {
        $this->discussion->expects(self::exactly(2))->method('mark')->with($this->call)->willReturn(['version' => 4]);
        $result = $this->controllers[0]->postActionBulkReadState($this->request([
            'ids' => ['Call:one', ...array_map(fn ($i) => 'Call:missing-' . $i, range(1, 99))], 'unread' => true,
        ]));
        self::assertSame(['Call:one'], array_keys((array) $result->states));
        self::assertCount(99, $result->failedIds);
        $duplicates = $this->controllers[0]->postActionBulkReadState($this->request(['ids' => ['Call:one', 'Call:one'], 'unread' => false]));
        self::assertSame(['Call:one'], array_keys((array) $duplicates->states));
        self::assertSame([], $duplicates->failedIds);
    }
}
