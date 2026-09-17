<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Core\Acl;
use Espo\Core\AclManager;
use Espo\Core\InjectableFactory;
use Espo\Core\Binding\BindingContainerBuilder;
use Espo\Core\Container;
use Espo\Core\Record\SearchParamsFetcher;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\Text\MetadataProvider as TextMetadataProvider;
use Espo\Core\Utils\Config;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Classes\Select\Note\LatestOpportunityEntry;
use Espo\Modules\Chatwoot\Classes\Select\Opportunity\StreamActivity;
use Espo\Modules\Chatwoot\Services\OpportunityMessageEvents;
use Espo\Modules\Chatwoot\Services\OpportunityStreamEvents;
use Espo\Modules\Chatwoot\Services\OpportunityReadStateService;
use Espo\Modules\Chatwoot\Services\OpportunityThreadState;
use Espo\Modules\Chatwoot\Tools\Stream\OpportunityEventAccess;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityFactory;
use Espo\ORM\EntityManager;
use Espo\ORM\Executor\QueryExecutor;
use Espo\ORM\Metadata;
use Espo\ORM\MetadataDataProvider;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\QueryComposer\MysqlQueryComposer;
use Espo\ORM\QueryComposer\PostgresqlQueryComposer;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/** Execute the real ORM predicates on a disposable in-memory dataset, not a CRM database. */
class OpportunityStreamQueriesTest extends TestCase
{
    private PDO $pdo;
    private MysqlQueryComposer $composer;
    private PostgresqlQueryComposer $pgComposer;
    private OpportunityReadStateService $service;
    private User $user;
    private OpportunityEventAccess $access;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required for the disposable query dataset.');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->sqliteCreateFunction('CONCAT', fn (...$parts) => implode('', $parts));
        $defs = [];
        $tables = [
            'Note' => ['id', 'parentId', 'parentType', 'type', 'relatedType', 'relatedId', 'createdById',
                'createdAt', 'opportunityMentionUserIds', 'opportunityThreadRootId', 'number', 'deleted'],
            'Opportunity' => ['id', 'status', 'assignedUserId', 'tenantId', 'deleted',
                'createdAt', 'modifiedAt', 'streamUpdatedAt'],
            'OpportunityReadState' => ['id', 'opportunityId', 'userId', 'lastSeenAt', 'lastSeenNumber',
                'isParticipant', 'isMarkedUnread', 'deleted'],
            'OpportunityThreadReadState' => ['id', 'rootNoteId', 'opportunityId', 'userId', 'lastSeenNumber', 'deleted'],
            'User' => ['id', 'firstName', 'lastName', 'deleted'],
        ];
        foreach ($tables as $type => $fields) {
            $columns = [];
            foreach ($fields as $field) {
                $column = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $field));
                $numeric = in_array($field, ['number', 'lastSeenNumber', 'deleted', 'isParticipant', 'isMarkedUnread']);
                $columns[] = "$column " . ($numeric ? 'INTEGER DEFAULT 0' : 'TEXT');
                $defs[$type]['attributes'][$field] = ['type' => $numeric ? 'int' : 'varchar'];
            }
            $table = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $type));
            $this->pdo->exec("CREATE TABLE $table (" . implode(', ', $columns) . ')');
        }
        $defs['Opportunity']['attributes']['chatwootStreamUpdatedAt'] = ['type' => 'datetime', 'notStorable' => true];
        $defs['User']['attributes']['name'] = [
            'type' => 'varchar',
            'notStorable' => true,
            'select' => ['select' => "CONCAT:(firstName, ' ', lastName)"],
        ];
        $provider = $this->createMock(MetadataDataProvider::class);
        $provider->method('get')->willReturn($defs);
        $metadata = new Metadata($provider);
        $entityFactory = $this->createMock(EntityFactory::class);
        $entityFactory->method('create')->willReturnCallback(fn ($type) => new BaseEntity($type, $defs[$type]));
        $this->composer = new MysqlQueryComposer($this->pdo, $entityFactory, $metadata);
        $this->pgComposer = new PostgresqlQueryComposer($this->pdo, $entityFactory, $metadata);

        $this->user = $this->createMock(User::class);
        $this->user->method('getId')->willReturn('agent');
        $this->user->method('isRegular')->willReturn(true);
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturn(true);
        $tenants = $this->createMock(UserTenantResolver::class);
        $tenants->method('resolveTenantIds')->willReturn(['tenant']);
        $this->access = $this->createMock(OpportunityEventAccess::class);
        $this->access->method('where')->willReturn(['OR' => [
            ['type!=' => OpportunityStreamEvents::EVENT_TYPES],
            ['relatedType' => 'ChatwootConversation', 'relatedId' => 'visible-conversation'],
            ['relatedType' => 'Task', 'relatedId' => 'visible-task'],
        ]]);
        $this->service = new OpportunityReadStateService(
            $this->createMock(EntityManager::class), $this->user, $acl, $tenants,
            $this->createMock(SelectBuilderFactory::class), $this->createMock(SearchParamsFetcher::class), $this->access,
            $this->createMock(OpportunityThreadState::class),
        );

        $this->pdo->exec("INSERT INTO opportunity (id, status, assigned_user_id, tenant_id) VALUES ('opp', 'Open', 'agent', 'tenant')");
        $this->pdo->exec("INSERT INTO opportunity_read_state
            (id, opportunity_id, user_id, last_seen_at, last_seen_number, is_participant)
            VALUES ('state', 'opp', 'agent', '2026-09-06 14:32:00', 1, 1)");
        $this->insert(1, 'Post');
        for ($number = 2; $number <= 11; $number++) {
            $this->insert($number, OpportunityMessageEvents::TYPE);
        }
        $this->insert(12, OpportunityMessageEvents::TYPE, 'hidden-conversation');
        $this->insert(13, 'Update');
    }

    private function insert(int $number, string $type, string $conversation = 'visible-conversation', string $author = 'system'): void
    {
        $this->pdo->prepare('INSERT INTO note
            (id, parent_id, parent_type, type, related_type, related_id, created_by_id, created_at, number)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                "note-$number", 'opp', 'Opportunity', $type, 'ChatwootConversation', $conversation,
                $author, '2026-09-06 14:32:00', $number,
            ]);
    }

    public function testUnreadCountsTenMessagesNotOnePillAndExcludesHiddenEventsAndOwnPosts(): void
    {
        $this->insert(14, 'Post', author: 'agent');
        $where = (new ReflectionMethod($this->service, 'otherPostsWhere'))->invoke($this->service, ['opp'], 'agent');
        $query = SelectBuilder::create()->from('Note')->select([['COUNT:id', 'count']])
            ->where($where)->where(['number>' => 1])->build();
        self::assertSame(10, (int) $this->pdo->query($this->composer->composeSelect($query))->fetchColumn());
    }

    public function testCardParticipantsIncludeThreadAuthorsAndExcludeEventsDeletedUsersAndOtherStreams(): void
    {
        $this->pdo->exec("INSERT INTO user (id, first_name, last_name, deleted) VALUES
            ('alice', 'Same', 'Name', 0), ('bob', 'Same', 'Name', 0), ('carol', 'Carol', 'Example', 0),
            ('removed', 'Deleted', 'User', 1), ('automation', 'Event', 'Author', 0)");
        $this->insert(14, 'Post', author: 'alice');
        $this->insert(15, 'Post', author: 'bob');
        $this->insert(16, 'Post', author: 'alice');
        $this->pdo->exec("UPDATE note SET opportunity_thread_root_id = 'note-14' WHERE id = 'note-16'");
        $this->insert(17, 'Post', author: 'removed');
        $this->insert(18, 'Post', author: 'missing-user');
        $this->insert(19, 'Post', author: 'carol');
        $this->pdo->exec("UPDATE note SET deleted = 1 WHERE id = 'note-19'");
        $this->insert(20, OpportunityStreamEvents::MESSAGE_RECEIVED, author: 'automation');
        $this->insert(21, 'Post', author: 'carol');
        $this->pdo->exec("UPDATE note SET parent_id = 'second' WHERE id = 'note-21'");
        $this->insert(22, 'Post', author: 'bob');
        $this->pdo->exec("UPDATE note SET parent_type = 'Case', parent_id = 'second' WHERE id = 'note-22'");
        $this->insert(23, 'Post', author: 'bob');
        $this->pdo->exec("UPDATE note SET parent_id = 'outside-batch' WHERE id = 'note-23'");
        $this->insert(24, 'Post');
        $this->pdo->exec("UPDATE note SET created_by_id = NULL WHERE id = 'note-24'");

        $method = new ReflectionMethod($this->service, 'discussionParticipants');
        $composer = $this->composer;
        $queryCount = 0;
        $executor = $this->createMock(QueryExecutor::class);
        $executor->method('execute')->willReturnCallback(function ($query) use (&$composer, &$queryCount) {
            $queryCount++;
            return $this->pdo->query($composer->composeSelect($query));
        });
        $entityManager = (new ReflectionProperty($this->service, 'entityManager'))->getValue($this->service);
        $entityManager->method('getQueryExecutor')->willReturn($executor);

        foreach ([$this->composer, $this->pgComposer] as $composer) {
            $queryCount = 0;
            $participants = $method->invoke($this->service, ['opp', 'second', 'empty']);
            self::assertSame([
                ['id' => 'alice', 'name' => 'Same Name'],
                ['id' => 'bob', 'name' => 'Same Name'],
            ], $participants['opp']);
            self::assertSame([['id' => 'carol', 'name' => 'Carol Example']], $participants['second']);
            self::assertArrayNotHasKey('empty', $participants);
            self::assertArrayNotHasKey('outside-batch', $participants);
            self::assertSame(2, $queryCount, 'Participant data must be batched, not queried per card.');
            self::assertSame([], $method->invoke($this->service, ['empty']));
            self::assertSame(3, $queryCount);
        }
    }

    public function testEspoFactoryConstructsRequiredAccessDependenciesWithoutExplicitOverrides(): void
    {
        $factory = new InjectableFactory($this->createMock(Container::class));
        $bindings = BindingContainerBuilder::create()
            ->bindInstance(EntityManager::class, $this->createMock(EntityManager::class))
            ->bindInstance(User::class, $this->user)
            ->bindInstance(Acl::class, $this->createMock(Acl::class))
            ->bindInstance(UserTenantResolver::class, $this->createMock(UserTenantResolver::class))
            ->bindInstance(InjectableFactory::class, $factory)
            ->bindInstance(AclManager::class, $this->createMock(AclManager::class))
            ->bindInstance(Config::class, $this->createMock(Config::class))
            ->bindInstance(TextMetadataProvider::class, $this->createMock(TextMetadataProvider::class))
            ->build();

        // Do NOT bind these three dependencies: nullable params made the real factory skip them.
        $service = $factory->createWithBinding(OpportunityReadStateService::class, $bindings);
        foreach ([
            'eventAccess' => OpportunityEventAccess::class,
            'selectBuilderFactory' => SelectBuilderFactory::class,
            'searchParamsFetcher' => SearchParamsFetcher::class,
        ] as $property => $class) {
            self::assertInstanceOf($class, (new ReflectionProperty($service, $property))->getValue($service));
        }
    }

    public function testNavigationUnreadAndReadCutoffUseTheSameSequenceDespiteEqualTimestamps(): void
    {
        $builder = SelectBuilder::create()->from('Opportunity', 'opportunity')->select('id');
        $this->service->applyListFilter($builder, true);
        $sql = $this->composer->composeSelect($builder->build());
        self::assertSame(['opp'], $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
        $this->pdo->exec('UPDATE opportunity_read_state SET last_seen_number = 11');
        self::assertSame([], $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
        $this->insert(15, OpportunityMessageEvents::TYPE);
        self::assertSame(['opp'], $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testPreviewIsLatestAccessibleEntryOnMysqlAndPostgresql(): void
    {
        $filter = new LatestOpportunityEntry($this->user, $this->access);
        $builder = SelectBuilder::create()->from('Note')->select('id');
        $filter->apply($builder);
        foreach ([$this->composer, $this->pgComposer] as $composer) {
            $sql = $composer->composeSelect($builder->build());
            self::assertSame(['note-11'], $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN), $sql);
        }
    }

    public function testNoConversationScopeFailsClosedBeforeBuildingAnAccessQuery(): void
    {
        $factory = $this->createMock(InjectableFactory::class);
        $factory->expects($this->never())->method('createWith');
        $acl = $this->createMock(AclManager::class);
        $acl->method('checkScope')->willReturn(false);
        $access = new OpportunityEventAccess($factory, $acl);
        self::assertSame(['type!=' => OpportunityStreamEvents::EVENT_TYPES], $access->where($this->user));
    }

    public function testActivitySortUsesPreviewDatesBeforePaginationOnMysqlAndPostgresql(): void
    {
        // Native streamUpdatedAt puts Nowle first, but its visible preview is older.
        $this->pdo->exec("INSERT INTO opportunity (id, created_at, modified_at, stream_updated_at) VALUES
            ('nowle', '2026-09-16 14:21:35', '2026-09-16 19:54:25', '2026-09-16 21:50:19'),
            ('drogapi', '2026-09-02 14:12:33', '2026-09-16 17:12:34', '2026-09-16 19:49:17'),
            ('michele', '2026-08-31 14:38:43', '2026-09-15 18:56:55', '2026-09-16 21:20:36')");
        $this->pdo->exec("INSERT INTO note (id, parent_id, parent_type, type, related_type, related_id, created_at, number) VALUES
            ('nowle-visible', 'nowle', 'Opportunity', 'ChatwootMessageReceived', 'ChatwootConversation', 'visible-conversation', '2026-09-16 14:44:42', 33086),
            ('nowle-hidden', 'nowle', 'Opportunity', 'ChatwootMessageReceived', 'ChatwootConversation', 'hidden-conversation', '2026-09-16 21:50:19', 34036),
            ('nowle-update', 'nowle', 'Opportunity', 'Update', NULL, NULL, '2026-09-16 22:00:00', 34037),
            ('drogapi-visible', 'drogapi', 'Opportunity', 'Post', NULL, NULL, '2026-09-16 19:49:17', 33804),
            ('michele-visible', 'michele', 'Opportunity', 'Post', NULL, NULL, '2026-09-16 21:20:36', 34021)");
        $applier = new StreamActivity(new LatestOpportunityEntry($this->user, $this->access));

        foreach ([$this->composer, $this->pgComposer] as $composer) {
            foreach (['desc' => ['michele', 'drogapi', 'nowle'], 'asc' => ['nowle', 'drogapi', 'michele']] as $order => $ids) {
                $builder = SelectBuilder::create()->from('Opportunity')
                    ->where(['id' => ['nowle', 'drogapi', 'michele']]);
                $applier->apply($builder, SearchParams::fromRaw([
                    'orderBy' => 'chatwootStreamUpdatedAt', 'order' => $order,
                ]));
                $sql = $composer->composeSelect($builder->build());
                $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
                self::assertSame($ids, array_column($rows, 'id'), $sql);
                $dates = array_column($rows, 'chatwootStreamUpdatedAt', 'id');
                self::assertSame('2026-09-16 14:44:42', $dates['nowle']);
                self::assertSame('2026-09-16 19:49:17', $dates['drogapi']);
                foreach ($ids as $offset => $id) {
                    $sql = $composer->composeSelect($builder->limit($offset, 1)->build());
                    self::assertSame([$id], array_column($this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC), 'id'), $sql);
                }
            }
        }
    }

    public function testActivitySortFallsBackAfterDeletionAndKeepsEqualDatesStable(): void
    {
        $this->pdo->exec("INSERT INTO opportunity (id, created_at, modified_at) VALUES
            ('a', '2026-09-16 10:00:00', NULL),
            ('b', '2026-09-15 10:00:00', '2026-09-16 10:00:00')");
        $this->pdo->exec("INSERT INTO note (id, parent_id, parent_type, type, created_at, number, deleted) VALUES
            ('deleted', 'a', 'Opportunity', 'Post', '2026-09-16 23:00:00', 40000, 1)");
        $applier = new StreamActivity(new LatestOpportunityEntry($this->user, $this->access));
        foreach ([$this->composer, $this->pgComposer] as $composer) {
            foreach (['asc', 'desc'] as $order) {
                $builder = SelectBuilder::create()->from('Opportunity')
                    ->select(['id', 'chatwootStreamUpdatedAt'])->where(['id' => ['a', 'b']]);
                $applier->apply($builder, SearchParams::fromRaw([
                    'orderBy' => 'chatwootStreamUpdatedAt', 'order' => $order,
                ]));
                $rows = $this->pdo->query($composer->composeSelect($builder->build()))->fetchAll(PDO::FETCH_ASSOC);
                self::assertSame(['a', 'b'], array_column($rows, 'id'));
                self::assertSame(['2026-09-16 10:00:00', '2026-09-16 10:00:00'], array_column($rows, 'chatwootStreamUpdatedAt'));
            }
        }
    }

    public function testOverdueActivityCountsAsOneUnreadEntryAndBecomesThePreview(): void
    {
        $this->insert(14, OpportunityStreamEvents::ACTIVITY_OVERDUE, 'visible-task');
        $this->pdo->exec("UPDATE note SET related_type = 'Task' WHERE id = 'note-14'");
        $this->insert(15, OpportunityStreamEvents::ACTIVITY_OVERDUE, 'hidden-task');
        $this->pdo->exec("UPDATE note SET related_type = 'Task' WHERE id = 'note-15'");
        $where = (new ReflectionMethod($this->service, 'otherPostsWhere'))->invoke($this->service, ['opp'], 'agent');
        $query = SelectBuilder::create()->from('Note')->select([['COUNT:id', 'count']])
            ->where($where)->where(['number>' => 11])->build();
        self::assertSame(1, (int) $this->pdo->query($this->composer->composeSelect($query))->fetchColumn());
        $builder = SelectBuilder::create()->from('Note')->select('id');
        (new LatestOpportunityEntry($this->user, $this->access))->apply($builder);
        self::assertSame(['note-14'], $this->pdo->query($this->composer->composeSelect($builder->build()))->fetchAll(PDO::FETCH_COLUMN));
    }
}
