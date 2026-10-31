<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Tests;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\Service;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ActivityInbox;
use Espo\Modules\Chatwoot\Tools\Activities\Access;
use Espo\Modules\FeatureCatchUp\Services\Snoozes;
use Espo\Modules\FeatureCatchUp\Services\TaskFeed;
use Espo\Modules\FeatureCatchUp\Services\Workspace;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\ORM\TransactionManager;
use PHPUnit\Framework\TestCase;

class TaskFeedTest extends TestCase
{
    private function fixture(array $task, bool $canEdit = true): array
    {
        $user = $this->createMock(User::class);
        $user->method('isRegular')->willReturn(true);
        $user->method('getId')->willReturn('user-a');
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturn(true);
        $acl->method('checkField')->willReturn(true);
        $acl->method('checkEntityEdit')->willReturn($canEdit);
        $entity = $this->createMock(Entity::class);
        $entity->method('get')->willReturnCallback(fn ($field) => $task[$field] ?? null);
        $builder = $this->createMock(RDBSelectBuilder::class);
        $builder->method('forUpdate')->willReturnSelf();
        $builder->method('findOne')->willReturn($entity);
        $repository = $this->createMock(RDBRepository::class);
        $repository->method('where')->willReturn($builder);
        $transactions = $this->createMock(TransactionManager::class);
        $transactions->method('run')->willReturnCallback(fn ($work) => $work());
        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->willReturn($repository);
        $em->method('getTransactionManager')->willReturn($transactions);
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturnCallback(fn ($path) => match ($path) {
            ['scopes', 'Task', 'completedStatusList', 0] => 'Completed',
            ['entityDefs', 'Task', 'fields', 'status', 'notActualOptions'] => ['Completed', 'Canceled', 'Deferred'],
            default => null,
        });
        $workspace = $this->createMock(Workspace::class);
        $workspace->method('resolve')->willReturn(['tenant' => $this->createMock(Entity::class), 'policy' => []]);
        $records = $this->createMock(Service::class);
        $container = $this->createMock(ServiceContainer::class);
        $container->method('get')->with('Task')->willReturn($records);
        $access = $this->createMock(Access::class);
        $service = new TaskFeed($em, $acl, $user, $metadata, $this->createMock(ActivityInbox::class), $access,
            $container, $workspace, $this->createMock(Snoozes::class));
        return [$service, $records, $access];
    }

    private function request(array $body): Request
    {
        $request = $this->createMock(Request::class);
        $request->method('getParsedBody')->willReturn((object) $body);
        return $request;
    }

    private const DISPLAYED = ['expectedStatus' => 'Planned', 'expectedDateEnd' => null, 'expectedDateEndDate' => '2026-10-30'];

    public function testCompletesOnlyTheDisplayedPendingTask(): void
    {
        [$service, $records] = $this->fixture(['status' => 'Planned', 'dateEndDate' => '2026-10-30']);
        $records->expects(self::once())->method('update')->with('task', (object) ['status' => 'Completed']);
        self::assertSame(['id' => 'task', 'status' => 'Completed'], $service->complete($this->request(self::DISPLAYED), 'task'));
    }

    public function testNewerDueDateWinsOverAnOldCard(): void
    {
        [$service, $records] = $this->fixture(['status' => 'Planned', 'dateEndDate' => '2026-11-02']);
        $records->expects(self::never())->method('update');
        $this->expectException(Conflict::class);
        $service->reschedule($this->request([...self::DISPLAYED, 'dateEndDate' => '2026-11-01']), 'task');
    }

    public function testFinishedTaskIsNotChangedAgain(): void
    {
        [$service, $records] = $this->fixture(['status' => 'Completed', 'dateEndDate' => '2026-10-30']);
        $records->expects(self::never())->method('update');
        $this->expectException(Conflict::class);
        $service->complete($this->request([...self::DISPLAYED, 'expectedStatus' => 'Completed']), 'task');
    }

    public function testReadOnlyTaskCannotBeCompleted(): void
    {
        [$service, $records] = $this->fixture(['status' => 'Planned', 'dateEndDate' => '2026-10-30'], false);
        $records->expects(self::never())->method('update');
        $this->expectException(Forbidden::class);
        $service->complete($this->request(self::DISPLAYED), 'task');
    }

    public function testRescheduleStoresUtcForTimedTasks(): void
    {
        [$service, $records] = $this->fixture(['status' => 'Planned', 'dateEndDate' => '2026-10-30']);
        $records->expects(self::once())->method('update')
            ->with('task', (object) ['dateEnd' => '2026-11-01 13:00:00', 'dateEndDate' => null]);
        $service->reschedule($this->request([...self::DISPLAYED, 'dateEnd' => '2026-11-01T10:00:00-03:00']), 'task');
    }

    public function testMissingDisplayedStateIsRejected(): void
    {
        [$service, $records] = $this->fixture(['status' => 'Planned']);
        $records->expects(self::never())->method('update');
        $this->expectException(BadRequest::class);
        $service->complete($this->request([]), 'task');
    }
}
