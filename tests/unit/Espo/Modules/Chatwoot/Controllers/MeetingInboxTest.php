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
use Espo\Modules\Chatwoot\Controllers\MeetingInbox;
use Espo\Modules\Chatwoot\Services\ActivityInbox as Inbox;
use Espo\Modules\Chatwoot\Services\ActivityDiscussion;
use Espo\Modules\Chatwoot\Tools\Activities\Access;
use Espo\Modules\FeatureTaskRecurrence\Services\Recurrence;
use Espo\ORM\BaseEntity;
use Espo\Tools\Stream\MassNotePreparator;
use PHPUnit\Framework\TestCase;

final class MeetingInboxTest extends TestCase
{
    private Access $access;
    private ActivityDiscussion $discussion;
    private Service $records;
    private Inbox $inbox;
    private BaseEntity $meeting;
    private BaseEntity $tenant;
    private array $controllers;

    protected function setUp(): void
    {
        $this->tenant = new BaseEntity('Tenant', ['attributes' => ['id' => ['type' => 'varchar']]]);
        $this->tenant->set('id', 'tenant-a');
        $this->meeting = new BaseEntity('Meeting', ['attributes' => [
            'id' => ['type' => 'varchar'], 'name' => ['type' => 'varchar'], 'versionNumber' => ['type' => 'int'],
        ]]);
        $this->meeting->set(['id' => 'one', 'name' => 'Demo', 'versionNumber' => 5]);
        $this->access = $this->createMock(Access::class);
        $this->access->method('type')->willReturnArgument(0);
        $this->access->method('workspace')->willReturnCallback(function ($accountId) {
            if ($accountId !== 6) throw new Forbidden();
            return $this->tenant;
        });
        $this->access->method('record')->willReturnCallback(function ($type, $id, $tenant, $stream = false) {
            self::assertSame($this->tenant, $tenant);
            if ($stream && $id === 'forbidden') throw new Forbidden();
            if ($type !== 'Meeting' || $id !== 'one') throw new NotFound();
            return $this->meeting;
        });
        $this->discussion = $this->createMock(ActivityDiscussion::class);
        $this->records = $this->createMock(Service::class);
        $this->records->method('read')->with('one')->willReturn(new ReadResult($this->meeting));
        $services = $this->createMock(ServiceContainer::class);
        $services->method('get')->with('Meeting')->willReturn($this->records);
        $this->inbox = $this->createMock(Inbox::class);
        $this->inbox->method('present')->willReturnCallback(fn ($entity) => (object) ((array) $entity->getValueMap() + ['entityType' => $entity->getEntityType()]));
        $this->inbox->method('conversations')->willReturn([]);
        $dependencies = [$this->inbox, $this->access, $this->discussion, $services, $this->createMock(MassNotePreparator::class), $this->createMock(Recurrence::class)];
        $this->controllers = [new MeetingInbox(...$dependencies), new ActivityInbox(...$dependencies)];
    }

    private function request(array $body = [], array $query = [], string $id = 'one', ?string $version = null): Request
    {
        $request = $this->createMock(Request::class);
        $request->method('getQueryParam')->willReturnCallback(fn ($name) => ($query + ['accountId' => '6'])[$name] ?? null);
        $request->method('getRouteParam')->willReturnCallback(fn ($name) => ['type' => 'Meeting', 'id' => $id][$name] ?? null);
        $request->method('getParsedBody')->willReturn((object) $body);
        $request->method('getHeader')->willReturnCallback(fn ($name) => $name === 'X-Version-Number' ? $version : null);
        return $request;
    }

    public function testCanonicalAndCompatibilityReadUseTheSameIdentityAndDiscussion(): void
    {
        $this->discussion->expects(self::exactly(2))->method('stream')->with($this->meeting, self::anything(), 'root', 42)
            ->willReturn((object) ['list' => [(object) ['id' => 'post']], 'readState' => ['version' => 3]]);
        $request = $this->request(query: ['rootId' => 'root', 'beforeNumber' => '42']);
        self::assertEquals($this->controllers[0]->getActionRead($request), $this->controllers[1]->getActionRead($request));
        self::assertEquals($this->controllers[0]->getActionStream($request), $this->controllers[1]->getActionStream($request));
    }

    public function testCreateAndUpdatesShareTeamValidationAndVersionProtection(): void
    {
        $this->access->expects(self::exactly(4))->method('validateTeams')->with(self::anything(), $this->tenant, self::anything());
        $this->records->expects(self::exactly(2))->method('create')->willReturn(new CreateResult($this->meeting));
        $this->records->expects(self::exactly(2))->method('update')->willReturnCallback(function ($id, $data, $params) {
            self::assertSame('one', $id);
            self::assertSame('Changed', $data->name);
            self::assertSame(5, $params->getVersionNumber());
            self::assertTrue($params->requiresVersion());
            return new UpdateResult($this->meeting);
        });
        foreach ($this->controllers as $controller) {
            self::assertSame('one', $controller->postActionCreate($this->request(['name' => 'Demo']))->id);
            self::assertSame('one', $controller->putActionUpdate($this->request(['name' => 'Changed'], version: '5'))->id);
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

    public function testReadStateAndBulkCompatibilityKeepPerItemAuthorization(): void
    {
        $this->discussion->method('mark')->willReturnCallback(function ($parent, $data) {
            self::assertSame($this->meeting, $parent);
            return ['version' => 4, 'isMarkedUnread' => $data->unread];
        });
        $request = $this->request(['unread' => true, 'expectedVersion' => 3]);
        self::assertEquals($this->controllers[0]->postActionReadState($request), $this->controllers[1]->postActionReadState($request));
        foreach ($this->controllers as $controller) {
            $result = $controller->postActionBulkReadState($this->request(['ids' => ['Meeting:one', 'Task:one', 'Meeting:missing', 'Meeting:forbidden'], 'unread' => false]));
            self::assertSame(['Meeting:one'], array_keys((array) $result->states));
            self::assertSame(['Task:one', 'Meeting:missing', 'Meeting:forbidden'], $result->failedIds);
        }
    }

    public function testForeignAccountCannotReadOrMutate(): void
    {
        $this->records->expects(self::never())->method('update');
        foreach ($this->controllers as $controller) {
            foreach (['getActionRead', 'putActionUpdate', 'deleteActionDelete', 'postActionReadState'] as $method) {
                try {
                    $controller->$method($this->request(query: ['accountId' => '7']));
                    self::fail('Foreign workspace accepted.');
                } catch (Forbidden) { self::assertTrue(true); }
            }
        }
    }

    public function testMeetingControllerRejectsOtherTypesBeforeMutation(): void
    {
        $this->records->expects(self::never())->method('create');
        $this->records->expects(self::never())->method('update');
        foreach (['Task', 'Call'] as $type) {
            foreach ([$this->request(['entityType' => $type]), $this->request(query: ['type' => $type])] as $request) {
                try {
                    $this->controllers[0]->postActionCreate($request);
                    self::fail('Other type accepted.');
                } catch (BadRequest) { self::assertTrue(true); }
            }
        }
    }

    public function testMeetingBulkReadRetainsTheHundredIdLimit(): void
    {
        $this->expectException(BadRequest::class);
        $this->controllers[0]->postActionBulkReadState($this->request(['ids' => array_fill(0, 101, 'Meeting:one'), 'unread' => false]));
    }
}
