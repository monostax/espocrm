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
use Espo\Modules\Chatwoot\Controllers\AppointmentInbox;
use Espo\Modules\Chatwoot\Services\ActivityInbox as Inbox;
use Espo\Modules\Chatwoot\Services\ActivityDiscussion;
use Espo\Modules\Chatwoot\Tools\Activities\Access;
use Espo\Modules\FeatureTaskRecurrence\Services\Recurrence;
use Espo\ORM\BaseEntity;
use Espo\Tools\Stream\MassNotePreparator;
use PHPUnit\Framework\TestCase;

final class AppointmentInboxTest extends TestCase
{
    private Access $access;
    private ActivityDiscussion $discussion;
    private Service $records;
    private Inbox $inbox;
    private BaseEntity $appointment;
    private BaseEntity $tenant;
    private AppointmentInbox $controller;
    private ActivityInbox $legacy;

    protected function setUp(): void
    {
        $this->tenant = new BaseEntity('Tenant', ['attributes' => ['id' => ['type' => 'varchar']]]);
        $this->tenant->set('id', 'tenant-a');
        $this->appointment = new BaseEntity('Appointment', ['attributes' => array_fill_keys(['id', 'name', 'customerId', 'locationId'], ['type' => 'varchar'])]);
        $this->appointment->set(['id' => 'one', 'name' => 'Visit', 'customerId' => 'customer', 'locationId' => 'location']);
        $this->access = $this->createMock(Access::class);
        $this->access->method('workspace')->willReturnCallback(function ($accountId) {
            if ($accountId !== 6) throw new Forbidden();
            return $this->tenant;
        });
        $this->access->method('record')->willReturnCallback(function ($type, $id, $tenant) {
            self::assertSame($this->tenant, $tenant);
            if ($type !== 'Appointment' || $id !== 'one') throw new NotFound();
            return $this->appointment;
        });
        $this->discussion = $this->createMock(ActivityDiscussion::class);
        $this->records = $this->createMock(Service::class);
        $this->records->method('read')->with('one')->willReturn(new ReadResult($this->appointment));
        $services = $this->createMock(ServiceContainer::class);
        $services->method('get')->with('Appointment')->willReturn($this->records);
        $this->inbox = $this->createMock(Inbox::class);
        $this->inbox->method('present')->willReturnCallback(fn ($entity) => (object) ((array) $entity->getValueMap() + ['entityType' => $entity->getEntityType()]));
        $dependencies = [$this->inbox, $this->access, $this->discussion, $services, $this->createMock(MassNotePreparator::class), $this->createMock(Recurrence::class)];
        $this->controller = new AppointmentInbox(...$dependencies);
        $this->legacy = new ActivityInbox(...$dependencies);
    }

    private function request(array $body = [], array $query = [], string $id = 'one'): Request
    {
        $request = $this->createMock(Request::class);
        $request->method('getQueryParam')->willReturnCallback(fn ($name) => ($query + ['accountId' => '6'])[$name] ?? null);
        $request->method('getRouteParam')->willReturnCallback(fn ($name) => ['type' => 'Appointment', 'id' => $id][$name] ?? null);
        $request->method('getParsedBody')->willReturn((object) $body);
        $request->method('getHeader')->willReturnCallback(fn ($name) => $name === 'X-Version-Number' ? '5' : null);
        return $request;
    }

    public function testUsesNativeServiceReferenceValidationAndVersionPreconditions(): void
    {
        $this->access->expects(self::exactly(2))->method('validateAppointment')->with(self::anything(), $this->tenant);
        $this->records->expects(self::once())->method('create')->willReturn(new CreateResult($this->appointment));
        $this->records->expects(self::once())->method('update')->willReturnCallback(function ($id, $data, $params) {
            self::assertSame(5, $params->getVersionNumber());
            self::assertTrue($params->requiresVersion());
            return new UpdateResult($this->appointment);
        });
        self::assertSame('Appointment', $this->controller->postActionCreate($this->request())->entityType);
        self::assertSame('one', $this->controller->putActionUpdate($this->request())->id);
        self::assertSame('customer', $this->controller->getActionRead($this->request())->customerId);
    }

    public function testReferenceDenialStopsNativeMutation(): void
    {
        $this->access->method('validateAppointment')->willThrowException(new Forbidden());
        $this->records->expects(self::never())->method('create');
        $this->records->expects(self::never())->method('update');
        foreach (['postActionCreate', 'putActionUpdate'] as $method) {
            try { $this->controller->$method($this->request(['customerId' => 'foreign'])); self::fail(); }
            catch (Forbidden) { self::assertTrue(true); }
        }
    }

    public function testAppointmentCannotWidenLegacyRecordOperations(): void
    {
        foreach (['getActionRead', 'postActionCreate', 'putActionUpdate', 'deleteActionDelete', 'postActionReadState', 'getActionStream'] as $method) {
            try { $this->legacy->$method($this->request()); self::fail(); }
            catch (BadRequest) { self::assertTrue(true); }
        }
    }

    public function testRejectsOtherTypesUnsupportedFiltersReservedOperationsAndForeignAccounts(): void
    {
        foreach (['Task', 'Call', 'Meeting'] as $type) {
            foreach ([$this->request(query: ['type' => $type]), $this->request(['entityType' => $type])] as $request) {
                foreach (['getActionRead', 'postActionCreate', 'putActionUpdate', 'deleteActionDelete', 'getActionList', 'getActionMetadata', 'getActionOptions', 'getActionStream', 'postActionReadState', 'postActionBulkReadState'] as $method) {
                    try { $this->controller->$method($request); self::fail(); }
                    catch (BadRequest) { self::assertTrue(true); }
                }
            }
        }
        foreach (['assignee_tab', 'assigned_user', 'assignedUserId'] as $filter) {
            try { $this->controller->getActionList($this->request(query: [$filter => 'me'])); self::fail(); }
            catch (BadRequest) { self::assertTrue(true); }
        }
        foreach (['counts', 'metadata', 'options', 'bulkReadState', 'action', 'posts', 'readState', 'stream', 'recurrence'] as $id) {
            try { $this->controller->getActionRead($this->request(id: $id)); self::fail(); }
            catch (BadRequest) { self::assertTrue(true); }
        }
        foreach (['getActionRead', 'postActionCreate', 'putActionUpdate', 'deleteActionDelete', 'getActionList', 'getActionMetadata', 'getActionStream', 'postActionReadState'] as $method) {
            try { $this->controller->$method($this->request(query: ['accountId' => '7'])); self::fail(); }
            catch (Forbidden) { self::assertTrue(true); }
        }
    }

    public function testEmbeddedApiHasNoCountsOrRecurrenceAndConflictsPropagate(): void
    {
        foreach (['getActionCounts', 'postActionRecurrencePreview'] as $method) {
            try { $this->controller->$method($this->request()); self::fail(); }
            catch (NotFound) { self::assertTrue(true); }
        }
        $this->records->method('update')->willThrowException(new Conflict());
        $this->expectException(Conflict::class);
        $this->controller->putActionUpdate($this->request());
    }

    public function testDiscussionAndBulkKeepIdentityPartialFailuresAndHundredIdLimit(): void
    {
        $this->discussion->method('stream')->with($this->appointment, self::anything(), 'root', 42)->willReturn((object) ['list' => []]);
        self::assertSame([], $this->controller->getActionStream($this->request(query: ['rootId' => 'root', 'beforeNumber' => '42']))->list);
        $this->discussion->method('mark')->with($this->appointment)->willReturn(['version' => 4]);
        $result = $this->controller->postActionBulkReadState($this->request(['ids' => ['Appointment:one', 'Appointment:missing', 'Task:one', 'Call:one', 'Meeting:one'], 'unread' => false]));
        self::assertSame(['Appointment:one'], array_keys((array) $result->states));
        self::assertSame(['Appointment:missing', 'Task:one', 'Call:one', 'Meeting:one'], $result->failedIds);
        $this->expectException(BadRequest::class);
        $this->controller->postActionBulkReadState($this->request(['ids' => array_fill(0, 101, 'Appointment:one'), 'unread' => false]));
    }
}
