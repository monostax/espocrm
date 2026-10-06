<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Record\SearchParamsFetcher;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\SelectBuilder as AccessBuilder;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Classes\Select\Note\LatestOpportunityEntry;
use Espo\Modules\Chatwoot\Classes\Select\Opportunity\StreamActivity;
use Espo\Modules\Chatwoot\Services\OpportunityInboxFilter;
use Espo\Modules\Chatwoot\Services\OpportunityReadStateService;
use Espo\Modules\Chatwoot\Services\OpportunityThreadState;
use Espo\Modules\Chatwoot\Tools\Stream\OpportunityEventAccess;
use Espo\Modules\Global\Tools\CrmTags;
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
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PDO;
use PHPUnit\Framework\TestCase;

/** Execute both dialects on disposable rows, including personal cursors and event visibility. */
class OpportunityStreamQueryTest extends TestCase
{
    private PDO $pdo;
    private array $composers;
    private OpportunityReadStateService $readStates;
    private LatestOpportunityEntry $latest;
    private EntityManager $em;
    private SelectBuilderFactory $select;
    private SearchParamsFetcher $fetcher;
    private CrmTags $tags;
    private OpportunityInboxFilter $inboxes;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $defs = [];
        foreach ([
            'Opportunity' => ['id', 'tenantId', 'status', 'assignedUserId', 'opportunityStageId', 'funnelId',
                'createdAt', 'modifiedAt', 'deleted'],
            'Note' => ['id', 'parentId', 'parentType', 'type', 'number', 'createdAt', 'createdById',
                'opportunityMentionUserIds', 'opportunityThreadRootId', 'visible', 'deleted'],
            'OpportunityReadState' => ['id', 'opportunityId', 'userId', 'lastSeenAt', 'lastSeenNumber',
                'isParticipant', 'isMarkedUnread', 'deleted'],
            'OpportunityThreadReadState' => ['id', 'rootNoteId', 'userId', 'lastSeenNumber', 'deleted'],
        ] as $type => $fields) {
            $columns = [];
            foreach ($fields as $field) {
                $numeric = in_array($field, ['number', 'lastSeenNumber', 'isParticipant', 'isMarkedUnread', 'visible', 'deleted']);
                $defs[$type]['attributes'][$field] = ['type' => $numeric ? 'int' : 'varchar'];
                $column = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $field));
                $columns[] = "$column " . ($numeric ? 'INTEGER' : 'TEXT') . ($field === 'deleted' ? ' DEFAULT 0' : '');
            }
            $table = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $type));
            $this->pdo->exec("CREATE TABLE $table (" . implode(', ', $columns) . ')');
        }
        $provider = $this->createMock(MetadataDataProvider::class);
        $provider->method('get')->willReturn($defs);
        $entities = $this->createMock(EntityFactory::class);
        $entities->method('create')->willReturnCallback(fn ($type) => new BaseEntity($type, $defs[$type]));
        $metadata = new Metadata($provider);
        $this->composers = [new MysqlQueryComposer($this->pdo, $entities, $metadata),
            new PostgresqlQueryComposer($this->pdo, $entities, $metadata)];
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('me');
        $user->method('isRegular')->willReturn(true);
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturn(true);
        $tenants = $this->createMock(UserTenantResolver::class);
        $tenants->method('resolveTenantIds')->willReturn(['tenant-a']);
        $access = $this->createMock(OpportunityEventAccess::class);
        $access->method('where')->willReturn(['visible' => 1]);
        $this->readStates = new OpportunityReadStateService(
            $this->em = $this->createMock(EntityManager::class), $user, $acl, $tenants,
            $this->select = $this->createMock(SelectBuilderFactory::class),
            $this->fetcher = $this->createMock(SearchParamsFetcher::class),
            $access, $this->createMock(OpportunityThreadState::class), $this->tags = $this->createMock(CrmTags::class),
            $this->inboxes = $this->createMock(OpportunityInboxFilter::class),
        );
        $this->latest = new LatestOpportunityEntry($user, $access);
    }

    private function insert(string $table, array $row): void
    {
        $this->pdo->prepare("INSERT INTO $table (" . implode(',', array_keys($row)) . ') VALUES (' .
            implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
    }

    private function opportunity(string $id, array $extra = []): void
    {
        $this->insert('opportunity', $extra + ['id' => $id, 'tenant_id' => 'tenant-a', 'status' => 'Open',
            'assigned_user_id' => 'me', 'created_at' => '2026-01-01', 'modified_at' => '2026-01-02']);
    }

    private function note(string $parent, int $number, array $extra = []): void
    {
        $this->insert('note', $extra + ['id' => "note-$number", 'parent_id' => $parent, 'parent_type' => 'Opportunity',
            'type' => 'Post', 'number' => $number, 'created_at' => '2026-02-01', 'created_by_id' => 'other', 'visible' => 1]);
    }

    private function state(string $parent, array $extra = []): void
    {
        $this->insert('opportunity_read_state', $extra + ['id' => "state-$parent", 'opportunity_id' => $parent,
            'user_id' => 'me', 'last_seen_at' => '2026-01-01', 'last_seen_number' => 10,
            'is_participant' => 0, 'is_marked_unread' => 0]);
    }

    private function assertIds(array $expected, SelectBuilder $query): void
    {
        foreach ($this->composers as $composer) {
            $sql = $composer->composeSelect($query->build());
            // SQLite's LIKE is already case-insensitive for these ASCII fixture IDs.
            $actual = $this->pdo->query(str_replace(' ILIKE ', ' LIKE ', $sql))->fetchAll(PDO::FETCH_COLUMN);
            self::assertSame($expected, $actual, $sql);
        }
    }

    public function testUnreadPreservesCutoffsMentionsThreadsAndManualMarks(): void
    {
        foreach (['mine', 'read', 'no-cutoff', 'closed', 'mention', 'colleague', 'participant', 'manual',
            'thread', 'seen-thread', 'hidden', 'self', 'legacy', 'other-tenant', 'deleted-note'] as $id) {
            $this->opportunity($id, match ($id) {
                'closed', 'mention', 'manual' => ['status' => 'Won'],
                'colleague', 'participant' => ['assigned_user_id' => 'other'],
                'other-tenant' => ['tenant_id' => 'tenant-b'],
                default => [],
            });
            if ($id !== 'no-cutoff') {
                $this->state($id, match ($id) {
                    'read' => ['last_seen_number' => 1000],
                    'participant' => ['is_participant' => 1],
                    'manual' => ['is_marked_unread' => 1, 'last_seen_at' => null],
                    'legacy' => ['last_seen_number' => null],
                    default => [],
                });
            }
        }
        $number = 20;
        foreach (['mine', 'read', 'no-cutoff', 'closed', 'mention', 'colleague', 'participant',
            'thread', 'seen-thread', 'hidden', 'self', 'legacy', 'other-tenant', 'deleted-note'] as $id) {
            $this->note($id, $number++, match ($id) {
                'mention' => ['opportunity_mention_user_ids' => '["me"]'],
                'thread', 'seen-thread' => ['opportunity_thread_root_id' => $id],
                'hidden' => ['visible' => 0],
                'self' => ['created_by_id' => 'me'],
                'deleted-note' => ['deleted' => 1],
                default => [],
            });
        }
        // Multiple qualifying notes must not multiply list rows or aggregate counts.
        $this->note('mine', 99);
        $this->insert('opportunity_thread_read_state', ['id' => 'seen', 'root_note_id' => 'seen-thread',
            'user_id' => 'me', 'last_seen_number' => 1000]);
        $query = SelectBuilder::create()->from('Opportunity')->select(['id'])->order('id');
        $this->readStates->applyListFilter($query, true);
        $this->assertIds(['legacy', 'manual', 'mention', 'mine', 'participant', 'thread'], $query);
    }

    public function testMentionsRetainReadAndClosedPostsButExcludeOwnPosts(): void
    {
        foreach (['read-mention', 'own-mention', 'unread-mention'] as $id) {
            $this->opportunity($id, ['status' => 'Lost']);
            $this->state($id, ['last_seen_number' => $id === 'read-mention' ? 100 : 0]);
        }
        $this->note('read-mention', 20, ['opportunity_mention_user_ids' => '["me"]']);
        $this->note('own-mention', 21, ['opportunity_mention_user_ids' => '["me"]', 'created_by_id' => 'me']);
        $this->note('unread-mention', 22, ['opportunity_mention_user_ids' => '["me"]']);
        $query = SelectBuilder::create()->from('Opportunity')->select(['id'])->order('id');
        $this->readStates->applyListFilter($query, false);
        $this->assertIds(['read-mention', 'unread-mention'], $query);
        $query = SelectBuilder::create()->from('Opportunity')->select(['id'])->order('id');
        $this->readStates->applyMentionFilter($query, true);
        $this->assertIds(['unread-mention'], $query);
    }

    public function testLatestAccessibleEntryDrivesSortingBeforePaginationAndPreviews(): void
    {
        foreach (['a', 'b', 'empty'] as $id) $this->opportunity($id);
        $this->opportunity('other', ['tenant_id' => 'tenant-b']);
        $this->note('a', 1, ['created_at' => '2026-03-01']);
        $this->note('a', 2, ['created_at' => '2026-04-01', 'visible' => 0]);
        $this->note('a', 3, ['created_at' => '2026-05-01', 'deleted' => 1]);
        $this->note('b', 4, ['created_at' => '2026-03-02']);
        $this->note('other', 5, ['created_at' => '2026-06-01']);
        $query = SelectBuilder::create()->from('Opportunity')->select(['id'])
            ->where(['tenantId' => 'tenant-a'])->limit(0, 2);
        (new StreamActivity($this->latest))->apply($query,
            SearchParams::fromRaw(['orderBy' => 'chatwootStreamUpdatedAt', 'order' => 'DESC']));
        $this->assertIds(['b', 'a'], $query);
        $query->limit(null, null);
        $this->assertIds(['b', 'a', 'empty'], $query);
        $preview = SelectBuilder::create()->from('Note')->select(['id'])
            ->where(['parentId' => ['a', 'b']])->order('number');
        $this->latest->apply($preview);
        $this->assertIds(['note-1', 'note-4'], $preview);
    }

    public function testNavigationReusesUnreadScopeForStatusesAndTagsAcrossAssigneeTabs(): void
    {
        $execute = fn ($query) => $this->pdo->query($this->composers[0]->composeSelect($query));
        $executor = $this->createMock(QueryExecutor::class);
        $executor->method('execute')->willReturnCallback($execute);
        $this->em->method('getQueryExecutor')->willReturn($executor);
        $repository = $this->createMock(RDBRepository::class);
        $repository->method('clone')->willReturnCallback(function ($query) use ($execute) {
            $select = $this->createMock(RDBSelectBuilder::class);
            $select->method('count')->willReturnCallback(fn () => count($execute(
                SelectBuilder::create()->clone($query)->select(['id'])->build()
            )->fetchAll(PDO::FETCH_COLUMN)));
            return $select;
        });
        $this->em->method('getRDBRepository')->with('Opportunity')->willReturn($repository);
        $this->fetcher->method('fetch')->willReturn(SearchParams::fromRaw([]));
        $this->select->method('create')->willReturnCallback(function () {
            $builder = $this->createMock(AccessBuilder::class);
            $builder->method('from')->willReturnSelf();
            $builder->method('withSearchParams')->willReturnSelf();
            $builder->method('withStrictAccessControl')->willReturnSelf();
            $builder->method('buildQueryBuilder')->willReturnCallback(fn () =>
                SelectBuilder::create()->from('Opportunity')->where(['tenantId' => 'tenant-a']));
            return $builder;
        });
        // Treat every authorized opportunity as tagged to verify the reused ID scope.
        $this->tags->method('counts')->willReturnCallback(function ($type, $scope) use ($execute) {
            $rows = $execute((clone $scope)->select(['id'])->build())->fetchAll(PDO::FETCH_COLUMN);
            return $rows ? ['tag' => count($rows)] : [];
        });
        $this->inboxes->method('counts')->willReturn([]);
        foreach (['mine', 'unassigned', 'closed', 'other-tenant'] as $id) {
            $this->opportunity($id, match ($id) {
                'unassigned' => ['assigned_user_id' => null],
                'closed' => ['status' => 'Won'],
                'other-tenant' => ['tenant_id' => 'tenant-b'],
                default => [],
            });
            $this->state($id);
        }
        $this->note('mine', 20);
        $this->note('mine', 21);
        $this->note('unassigned', 22);
        $this->note('closed', 23);
        $this->note('other-tenant', 24);
        foreach (['all' => [3, 2], 'me' => [2, 1], 'unassigned' => [1, 1]] as $tab => [$all, $unread]) {
            $request = $this->createMock(Request::class);
            $request->method('getQueryParam')->willReturnCallback(fn ($key) => $key === 'assigneeTab' ? $tab : null);
            $counts = $this->readStates->getNavigationCounts($request);
            self::assertSame($all, $counts['all']);
            self::assertSame($unread, $counts['unread']);
            self::assertSame(['Open' => $unread, 'Won' => 0, 'Lost' => 0], $counts['statusUnread']);
            self::assertSame(['tag' => $unread], $counts['tagsUnread']);
        }
        $this->pdo->exec('UPDATE opportunity_read_state SET last_seen_number = 1000');
        $counts = $this->readStates->getNavigationCounts($request);
        self::assertSame(0, $counts['unread']);
        self::assertSame([], $counts['tagsUnread']);
        self::assertSame(['Open' => 0, 'Won' => 0, 'Lost' => 0], $counts['statusUnread']);
    }
}
