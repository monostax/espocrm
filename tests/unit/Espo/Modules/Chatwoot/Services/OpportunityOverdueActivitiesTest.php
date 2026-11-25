<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use DateTimeImmutable;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\Entities\Note;
use Espo\Modules\Chatwoot\Hooks\Note\KeepOpportunityEventsInternal;
use Espo\Modules\Chatwoot\Services\OpportunityOverdueActivities;
use Espo\Modules\Chatwoot\Services\OpportunityStreamEvents;
use Espo\Modules\Chatwoot\Tools\Stream\NoteHookProcessor;
use Espo\ORM\Entity;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityFactory;
use Espo\ORM\EntityManager;
use Espo\ORM\Metadata as OrmMetadata;
use Espo\ORM\MetadataDataProvider;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\QueryComposer\MysqlQueryComposer;
use Espo\ORM\QueryComposer\PostgresqlQueryComposer;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\ORM\TransactionManager;
use Espo\Tools\Notification\HookProcessor\Params;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PDO;
use ReflectionClass;
use ReflectionMethod;
use tests\unit\Espo\Modules\Chatwoot\Support\EntityDouble;

require_once __DIR__ . '/../Support/EntityDouble.php';

class OpportunityOverdueActivitiesTest extends TestCase
{
    private array $writes = [];
    private array $attributes;
    private bool $stillPending = true;
    private Entity $activity;
    private OpportunityOverdueActivities $service;

    protected function setUp(): void
    {
        $this->attributes = [
            'id' => 'task', 'name' => 'Enviar proposta', 'parentType' => 'Opportunity', 'parentId' => 'opp',
            'dateEnd' => '2026-09-06 14:00:00', 'dateEndDate' => null, 'tenantId' => 'tenant',
            'createdAt' => '2026-09-01 14:00:00',
        ];
        $em = $this->createMock(EntityManager::class);
        $config = $this->createMock(Config::class);
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturn(['Completed', 'Canceled', 'Deferred']);
        $events = $this->createMock(OpportunityStreamEvents::class);
        $events->method('write')->willReturnCallback(function (
            string $type, Entity $opportunity, Entity $related, array $data, string $key, array $teams, bool $addressed = false,
        ): bool {
            $this->writes[$key] = compact('type', 'opportunity', 'related', 'data', 'teams', 'addressed');
            return true;
        });
        $transaction = $this->createMock(TransactionManager::class);
        $transaction->method('run')->willReturnCallback(fn ($fn) => $fn());
        $em->method('getTransactionManager')->willReturn($transaction);

        $activity = new class($this->attributes, 'Task') extends EntityDouble {
            public function getLinkMultipleIdList(string $link): array { return ['team']; }
        };
        $this->activity = $activity;
        $select = $this->createMock(RDBSelectBuilder::class);
        $select->method('where')->with(['status!=' => ['Completed', 'Canceled', 'Deferred']])->willReturnSelf();
        $select->method('forUpdate')->willReturnSelf();
        $select->method('findOne')->willReturnCallback(fn () => $this->stillPending ? $activity : null);
        $repo = $this->createMock(RDBRepository::class);
        // The task's own Mural locks the task alone; parent Murals also pin the parent type.
        $repo->method('where')->with(self::callback(fn ($where) => in_array($where, [
            ['id' => 'task', 'parentType' => $activity->get('parentType')], ['id' => 'task'],
        ], true)))->willReturn($select);
        $em->method('getRDBRepository')->with('Task')->willReturn($repo);
        $em->method('getEntityById')->willReturnMap([
            ['Opportunity', 'opp', new EntityDouble(['id' => 'opp', 'tenantId' => 'tenant'], 'Opportunity')],
            ['Account', 'opp', new EntityDouble(['id' => 'opp', 'tenantId' => 'tenant'], 'Account')],
            ['Initiative', 'opp', new EntityDouble(['id' => 'opp', 'tenantId' => 'tenant'], 'Initiative')],
            ['Tenant', 'tenant', new EntityDouble(['timeZone' => 'America/Sao_Paulo'], 'Tenant')],
        ]);
        $this->service = new OpportunityOverdueActivities($em, $config, $metadata, $events, $this->createMock(Log::class));
    }

    private function tick(string $now): void
    {
        $this->activity->set($this->attributes);
        (new ReflectionMethod($this->service, 'record'))->invoke(
            $this->service, $this->activity, new DateTimeImmutable($now), '2026-09-06 00:00:00',
        );
    }

    private function tickTask(string $now, string $startedAt = '2026-09-06 00:00:00'): void
    {
        $this->activity->set($this->attributes);
        (new ReflectionMethod($this->service, 'recordOnTask'))->invoke(
            $this->service, $this->activity, new DateTimeImmutable($now), $startedAt,
        );
    }

    public function testTaskMuralPostsOncePerDeadlineAddressedToTheTaskPeople(): void
    {
        $this->attributes['parentType'] = null;
        $this->attributes['parentId'] = null;
        $this->tickTask('2026-09-06T13:59:59Z');
        self::assertCount(0, $this->writes);
        $this->tickTask('2026-09-06T14:01:00Z');
        $this->tickTask('2026-09-06T14:02:00Z');
        self::assertCount(1, $this->writes);
        $event = array_values($this->writes)[0];
        self::assertSame(OpportunityStreamEvents::ACTIVITY_OVERDUE, $event['type']);
        self::assertSame($this->activity, $event['opportunity']);
        self::assertSame($this->activity, $event['related']);
        self::assertTrue($event['addressed']);
        self::assertSame(['team'], $event['teams']);
        self::assertSame('2026-09-06 14:00:00', $event['data']['dueAt']);
        // Its own key: the same task may also post on its deal's Mural.
        self::assertSame(hash('sha256', implode(':', [
            OpportunityStreamEvents::ACTIVITY_OVERDUE, 'self', 'Task', 'task', '2026-09-06 14:00:00',
        ])), array_key_first($this->writes));
        $this->attributes['dateEnd'] = '2026-09-06 15:00:00';
        $this->tickTask('2026-09-06T15:01:00Z');
        self::assertCount(2, $this->writes);
    }

    public function testTaskMuralDateOnlyWaitsForTheTenantDayToEnd(): void
    {
        $this->attributes['dateEndDate'] = '2026-09-06';
        $this->tickTask('2026-09-07T02:59:59Z');
        self::assertCount(0, $this->writes);
        $this->tickTask('2026-09-07T03:01:00Z');
        self::assertSame('2026-09-07 03:00:00', array_values($this->writes)[0]['data']['dueAt']);
    }

    public function testTaskMuralSkipsFinishedTasksAndTheBacklogBeforeItsRollout(): void
    {
        $this->stillPending = false;
        $this->tickTask('2026-09-06T14:01:00Z');
        $this->stillPending = true;
        $this->tickTask('2026-09-06T14:01:00Z', '2026-09-06 14:30:00');
        self::assertCount(0, $this->writes);
    }

    public function testTaskMuralScanSkipsTasksWhoseCurrentDeadlineAlreadyPosted(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required for the disposable query dataset.');
        }
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $defs = [];
        foreach ([
            'Task' => ['id', 'tenantId', 'status', 'dateEnd', 'dateEndDate', 'createdAt', 'modifiedAt', 'deleted'],
            'Note' => ['id', 'parentType', 'parentId', 'type', 'createdAt', 'deleted'],
        ] as $type => $fields) {
            $columns = [];
            foreach ($fields as $field) {
                $defs[$type]['attributes'][$field] = ['type' => $field === 'deleted' ? 'bool' : 'varchar'];
                $column = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $field));
                $columns[] = $column . ($field === 'deleted' ? ' INTEGER DEFAULT 0' : ' TEXT');
            }
            $pdo->exec('CREATE TABLE ' . strtolower($type) . ' (' . implode(', ', $columns) . ')');
        }
        $provider = $this->createMock(MetadataDataProvider::class);
        $provider->method('get')->willReturn($defs);
        $metadata = new OrmMetadata($provider);
        $entityFactory = $this->createMock(EntityFactory::class);
        $entityFactory->method('create')->willReturnCallback(fn ($type) => new BaseEntity($type, $defs[$type]));
        $due = '2026-09-07 11:00:00';
        $tasks = [
            ['due', 'tenant', 'Not Started', $due, null, '2026-09-01 00:00:00', '2026-09-01 00:00:00'],
            ['posted', 'tenant', 'Started', $due, null, '2026-09-01 00:00:00', '2026-09-01 00:00:00'],
            ['edited-after-post', 'tenant', 'Started', $due, null, '2026-09-01 00:00:00', '2026-09-07 11:30:00'],
            ['only-a-post', 'tenant', 'Started', $due, null, '2026-09-01 00:00:00', '2026-09-01 00:00:00'],
            ['completed', 'tenant', 'Completed', $due, null, '2026-09-01 00:00:00', '2026-09-01 00:00:00'],
            ['future', 'tenant', 'Started', '2026-09-08 11:00:00', null, '2026-09-01 00:00:00', '2026-09-01 00:00:00'],
            ['no-tenant', null, 'Started', $due, null, '2026-09-01 00:00:00', '2026-09-01 00:00:00'],
            ['backlog', 'tenant', 'Started', '2026-09-03 11:00:00', null, '2026-09-01 00:00:00', '2026-09-01 00:00:00'],
        ];
        foreach ($tasks as $row) {
            $pdo->prepare('INSERT INTO task (id, tenant_id, status, date_end, date_end_date, created_at, modified_at) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute($row);
        }
        foreach ([
            ['n1', 'Task', 'posted', OpportunityStreamEvents::ACTIVITY_OVERDUE, '2026-09-07 11:01:00'],
            ['n2', 'Task', 'edited-after-post', OpportunityStreamEvents::ACTIVITY_OVERDUE, '2026-09-07 11:01:00'],
            ['n3', 'Task', 'only-a-post', 'Post', '2026-09-07 11:01:00'],
            ['n4', 'Opportunity', 'due', OpportunityStreamEvents::ACTIVITY_OVERDUE, '2026-09-07 11:01:00'],
        ] as $row) {
            $pdo->prepare('INSERT INTO note (id, parent_type, parent_id, type, created_at) VALUES (?, ?, ?, ?, ?)')->execute($row);
        }
        $query = (new ReflectionMethod($this->service, 'taskMuralCandidates'))->invoke(
            $this->service, new DateTimeImmutable('2026-09-07T12:00:00Z'), '2026-09-06 19:50:51', '',
        );
        foreach ([MysqlQueryComposer::class, PostgresqlQueryComposer::class] as $class) {
            $sql = (new $class($pdo, $entityFactory, $metadata))->composeSelect($query);
            self::assertSame(['due', 'edited-after-post', 'only-a-post'], $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN), $sql);
        }
    }

    public function testPostsOncePerDeadlineAndAllowsARescheduledDeadline(): void
    {
        $this->tick('2026-09-06T13:59:59Z');
        self::assertCount(0, $this->writes);
        $this->tick('2026-09-06T14:01:00Z');
        $this->tick('2026-09-06T14:02:00Z');
        self::assertCount(1, $this->writes);
        $event = array_values($this->writes)[0];
        self::assertSame(OpportunityStreamEvents::ACTIVITY_OVERDUE, $event['type']);
        self::assertSame('Enviar proposta', $event['data']['activityName']);
        self::assertSame('2026-09-06 14:00:00', $event['data']['dueAt']);
        self::assertSame('task', $event['related']->getId());
        $this->attributes['dateEnd'] = '2026-09-06 15:00:00';
        $this->tick('2026-09-06T14:59:00Z');
        self::assertCount(1, $this->writes);
        $this->tick('2026-09-06T15:01:00Z');
        self::assertCount(2, $this->writes);
    }

    public function testDateOnlyTaskIsNotOverdueUntilTheTenantDayHasEnded(): void
    {
        $this->attributes['dateEndDate'] = '2026-09-06';
        $this->tick('2026-09-07T02:59:59Z');
        self::assertCount(0, $this->writes);
        $this->tick('2026-09-07T03:01:00Z');
        self::assertCount(1, $this->writes);
        self::assertSame('2026-09-07 03:00:00', array_values($this->writes)[0]['data']['dueAt']);
    }

    public function testDeadlineIsIndependentForEachParentTypeWithSameId(): void
    {
        foreach (['Opportunity', 'Account', 'Initiative'] as $type) {
            $this->attributes['parentType'] = $type;
            $this->tick('2026-09-06T15:01:00Z');
            $this->tick('2026-09-06T15:02:00Z');
        }
        self::assertSame(['Opportunity', 'Account', 'Initiative'], array_values(array_map(
            fn ($event) => $event['opportunity']->getEntityType(), $this->writes,
        )));
    }

    public function testCompletionRaceAndHistoricalDeadlinesDoNotPost(): void
    {
        $this->stillPending = false;
        $this->tick('2026-09-06T14:01:00Z');
        $this->stillPending = true;
        $this->attributes['dateEnd'] = '2026-09-05 14:00:00';
        $this->tick('2026-09-06T14:01:00Z');
        self::assertCount(0, $this->writes);
    }

    public function testCrossTenantActivityIsNotPosted(): void
    {
        $this->attributes['tenantId'] = 'foreign';
        $this->tick('2026-09-06T14:01:00Z');
        self::assertCount(0, $this->writes);
    }

    public function testNewAlreadyOverdueTaskPostsAtCreationWithoutChangingItsDeadlineKey(): void
    {
        $this->attributes['dateEnd'] = '2026-09-03 03:00:00';
        $this->attributes['dateEndDate'] = '2026-09-02';
        $this->attributes['createdAt'] = '2026-09-06 20:22:29';
        $this->tick('2026-09-07T12:00:00Z');
        $this->tick('2026-09-07T12:01:00Z');
        self::assertCount(1, $this->writes);
        $event = array_values($this->writes)[0];
        self::assertSame('2026-09-03 03:00:00', $event['data']['dueAt']);
        self::assertSame('2026-09-06 20:22:29', $event['data']['occurredAt']);
        self::assertSame(hash('sha256', implode(':', [
            OpportunityStreamEvents::ACTIVITY_OVERDUE, 'opp', 'Task', 'task', '2026-09-03 03:00:00',
        ])), array_key_first($this->writes));
    }

    public function testNewAlreadyOverdueTimedTaskPostsButOldBacklogWithRecentEditsDoesNot(): void
    {
        $this->attributes['dateEnd'] = '2026-09-02 14:00:00';
        $this->attributes['modifiedAt'] = '2026-09-06 20:22:29';
        $this->tick('2026-09-07T12:00:00Z');
        self::assertCount(0, $this->writes);
        $this->attributes['createdAt'] = '2026-09-06 20:22:29';
        $this->tick('2026-09-07T12:00:00Z');
        self::assertCount(1, $this->writes);
        self::assertSame('2026-09-06 20:22:29', array_values($this->writes)[0]['data']['occurredAt']);
    }

    public static function activityTypes(): array
    {
        return [['Task'], ['Meeting'], ['Call']];
    }

    #[DataProvider('activityTypes')]
    public function testScheduledScanIncludesNewPastDeadlinesButNotHistoricalBacklogOrFutureDeadlines(string $type): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required for the disposable query dataset.');
        }
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $table = strtolower($type);
        $pdo->exec("CREATE TABLE \"$table\" (id TEXT, date_end TEXT, date_end_date TEXT, created_at TEXT, deleted INTEGER DEFAULT 0)");
        $fields = ['id', 'dateEnd', 'dateEndDate', 'createdAt', 'deleted'];
        $defs = [];
        foreach ($fields as $field) {
            $defs['attributes'][$field] = ['type' => $field === 'deleted' ? 'bool' : 'varchar'];
        }
        $provider = $this->createMock(MetadataDataProvider::class);
        $provider->method('get')->willReturn([$type => $defs]);
        $metadata = new OrmMetadata($provider);
        $entityFactory = $this->createMock(EntityFactory::class);
        $entityFactory->method('create')->willReturn(new BaseEntity($type, $defs));
        $rows = [
            ['new-past', '2026-09-03 03:00:00', null, '2026-09-06 20:22:29'],
            ['old-past', '2026-09-03 03:00:00', null, '2026-09-01 14:00:00'],
            ['new-future', '2026-09-08 14:00:00', null, '2026-09-06 20:22:29'],
            ['old-new-deadline', '2026-09-07 11:00:00', null, '2026-09-01 14:00:00'],
        ];
        $expected = ['new-past', 'old-new-deadline'];
        if ($type === 'Task') {
            $rows[] = ['new-date-only', '2026-09-03 03:00:00', '2026-09-02', '2026-09-06 20:22:29'];
            $rows[] = ['old-date-only', '2026-09-03 03:00:00', '2026-09-02', '2026-09-01 14:00:00'];
            $rows[] = ['new-future-date', null, '2026-10-01', '2026-09-06 20:22:29'];
            array_unshift($expected, 'new-date-only');
        }
        foreach ($rows as $row) {
            $pdo->prepare("INSERT INTO \"$table\" (id, date_end, date_end_date, created_at) VALUES (?, ?, ?, ?)")->execute($row);
        }
        $where = (new ReflectionMethod($this->service, 'dueWhere'))->invoke(
            $this->service, $type, new DateTimeImmutable('2026-09-07T12:00:00Z'), '2026-09-06 19:50:51',
        );
        $query = SelectBuilder::create()->from($type)->select('id')->where($where)->order('id')->build();
        foreach ([MysqlQueryComposer::class, PostgresqlQueryComposer::class] as $class) {
            $sql = (new $class($pdo, $entityFactory, $metadata))->composeSelect($query);
            self::assertSame($expected, $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN), $sql);
        }
    }

    public function testDateOnlyDeadlineHonorsDaylightSavingTime(): void
    {
        $activity = new EntityDouble(['dateEndDate' => '2026-03-08'], 'Task');
        self::assertSame('2026-03-09 04:00:00', $this->service->deadline($activity, 'America/New_York'));
    }

    public static function eventTypes(): array
    {
        return array_map(fn ($type) => [$type], OpportunityStreamEvents::EVENT_TYPES);
    }

    #[DataProvider('eventTypes')]
    public function testEventsRemainInternalAndDoNotFanOutCrmNotifications(string $type): void
    {
        $entity = new EntityDouble(['type' => $type, 'isInternal' => false], 'Note');
        (new KeepOpportunityEventsInternal())->beforeSave($entity, []);
        self::assertTrue($entity->get('isInternal'));
        $note = $this->createMock(Note::class);
        $note->method('getType')->willReturn($type);
        $note->method('getParentType')->willReturn('Opportunity');
        // No parent dependencies are initialized: reaching notification fan-out would fail.
        $processor = (new ReflectionClass(NoteHookProcessor::class))->newInstanceWithoutConstructor();
        $processor->afterSave($note, new Params());
    }
}
