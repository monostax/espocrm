<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Core\Acl;
use Espo\Core\AclManager;
use Espo\Core\InjectableFactory;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Classes\Select\Note\LatestOpportunityEntry;
use Espo\Modules\Chatwoot\Services\OpportunityMessageEvents;
use Espo\Modules\Chatwoot\Services\OpportunityStreamEvents;
use Espo\Modules\Chatwoot\Services\OpportunityReadStateService;
use Espo\Modules\Chatwoot\Tools\Stream\OpportunityEventAccess;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityFactory;
use Espo\ORM\EntityManager;
use Espo\ORM\Metadata;
use Espo\ORM\MetadataDataProvider;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\QueryComposer\MysqlQueryComposer;
use Espo\ORM\QueryComposer\PostgresqlQueryComposer;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

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
        $defs = [];
        $tables = [
            'Note' => ['id', 'parentId', 'parentType', 'type', 'relatedType', 'relatedId', 'createdById',
                'createdAt', 'opportunityMentionUserIds', 'number', 'deleted'],
            'Opportunity' => ['id', 'status', 'assignedUserId', 'tenantId', 'deleted'],
            'OpportunityReadState' => ['id', 'opportunityId', 'userId', 'lastSeenAt', 'lastSeenNumber',
                'isParticipant', 'isMarkedUnread', 'deleted'],
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
            eventAccess: $this->access,
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
