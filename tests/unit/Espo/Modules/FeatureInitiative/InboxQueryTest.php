<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureInitiative;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Record\SearchParamsFetcher;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\SelectBuilder as CoreSelectBuilder;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Utils\Metadata as AppMetadata;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ActivityDiscussion;
use Espo\Modules\Chatwoot\Services\OpportunityBulkPostAccess;
use Espo\Modules\Chatwoot\Tools\Activities\Access as ActivityAccess;
use Espo\Modules\Chatwoot\Tools\Stream\OpportunityAccess;
use Espo\Modules\FeatureInitiative\Services\Inbox;
use Espo\Modules\Global\Services\RecordActivityBuckets;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityFactory;
use Espo\ORM\EntityManager;
use Espo\ORM\Metadata;
use Espo\ORM\MetadataDataProvider;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\Select;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\QueryComposer\MysqlQueryComposer;
use Espo\ORM\QueryComposer\PostgresqlQueryComposer;
use PDO;

/** Exercise production grouping SQL on disposable data in both supported dialects. */
class InboxQueryTest extends TestCase
{
    private PDO $pdo;
    private array $composers;
    private Inbox $inbox;
    private Select $scope;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->sqliteCreateFunction('CONCAT', fn (...$parts) => implode('', $parts));
        $this->pdo->sqliteCreateFunction('IF', fn ($condition, $yes, $no) => $condition ? $yes : $no);
        $tables = [
            'Initiative' => ['id', 'tenantId', 'initiativeTypeId', 'stageId', 'assignedUserId', 'status', 'readable', 'deleted'],
            'Note' => ['id', 'parentType', 'parentId', 'type', 'createdById', 'opportunityThreadRootId', 'opportunityPostDeleted', 'number', 'deleted'],
            'ActivityReadState' => ['id', 'parentType', 'parentId', 'userId', 'threadKey', 'lastSeenNumber', 'isMarkedUnread', 'deleted'],
        ];
        $defs = [];
        foreach ($tables as $type => $fields) {
            $columns = [];
            foreach ($fields as $field) {
                $numeric = in_array($field, ['readable', 'deleted', 'number', 'lastSeenNumber', 'isMarkedUnread', 'opportunityPostDeleted'], true);
                $columns[] = $this->sqlName($field) . ($numeric ? ' INTEGER DEFAULT 0' : ' TEXT');
                $defs[$type]['attributes'][$field] = ['type' => $numeric ? 'int' : 'varchar'];
            }
            $this->pdo->exec('CREATE TABLE ' . $this->sqlName($type) . ' (' . implode(', ', $columns) . ')');
        }
        $provider = $this->createMock(MetadataDataProvider::class);
        $provider->method('get')->willReturn($defs);
        $entities = $this->createMock(EntityFactory::class);
        $entities->method('create')->willReturnCallback(fn ($type) => new BaseEntity($type, $defs[$type]));
        $metadata = new Metadata($provider);
        $this->composers = [
            new MysqlQueryComposer($this->pdo, $entities, $metadata),
            new PostgresqlQueryComposer($this->pdo, $entities, $metadata),
        ];
        $em = $this->createMock(EntityManager::class);
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturnCallback(fn ($type) => in_array($type, ['Initiative', 'Note'], true));
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('agent');
        $factory = $this->createMock(SelectBuilderFactory::class);
        $this->scope = SelectBuilder::create()->from('Initiative')->select('id')->where(['tenantId' => 'tenant-a', 'readable' => 1])->build();
        $builder = $this->createMock(CoreSelectBuilder::class);
        foreach (['from', 'withSearchParams', 'withStrictAccessControl', 'withWherePermissionCheck', 'withComplexExpressionsForbidden'] as $method) {
            $builder->method($method)->willReturnSelf();
        }
        // Production selection adds mandatory attributes even when the caller requests only id.
        $builder->method('buildQueryBuilder')->willReturnCallback(fn () => SelectBuilder::create()
            ->from('Initiative')->select(['assignedUserId', 'initiativeTypeId', 'tenantId', 'id'])
            ->where(['readable' => 1]));
        $factory->method('create')->willReturn($builder);
        $searchParams = $this->createMock(SearchParamsFetcher::class);
        $searchParams->method('fetch')->willReturn(SearchParams::fromRaw([]));
        $workspaces = $this->createMock(OpportunityBulkPostAccess::class);
        $workspace = new BaseEntity('ChatwootAccount', ['attributes' => ['tenantId' => ['type' => 'varchar']]]);
        $workspace->set('tenantId', 'tenant-a');
        $workspaces->method('workspace')->willReturn($workspace);
        $streamAccess = $this->createMock(OpportunityAccess::class);
        $streamAccess->method('readableInitiatives')->willReturn($this->scope);
        $this->inbox = new Inbox(
            $em, $acl, $user, $factory, $searchParams,
            $this->createMock(ServiceContainer::class), $workspaces,
            new ActivityDiscussion($em, $user, $acl), new RecordActivityBuckets($factory, $acl),
            $this->createMock(AppMetadata::class), $streamAccess, $this->createMock(ActivityAccess::class),
            $this->createMock(\Espo\Modules\Global\Tools\CrmTags::class),
        );
        $request = $this->createMock(Request::class);
        $request->method('getQueryParam')->willReturnCallback(fn ($name) => $name === 'accountId' ? '6' : null);
        $this->scope = $this->inbox->scope($request);
        $this->pdo->exec("INSERT INTO initiative (id, tenant_id, initiative_type_id, status, readable) VALUES
            ('a', 'tenant-a', 'type-a', 'Open', 1), ('b', 'tenant-a', 'type-a', 'Completed', 1),
            ('c', 'tenant-b', 'type-b', 'In Progress', 1), ('d', 'tenant-a', 'type-b', 'In Progress', 0)");
        $this->pdo->exec("INSERT INTO note (id, parent_type, parent_id, type, created_by_id, number)
            VALUES ('post-a', 'Initiative', 'a', 'Post', 'other', 1), ('post-c', 'Initiative', 'c', 'Post', 'other', 2)");
    }

    private function sqlName(string $name): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }

    public function testScopeProjectsOnlyIdsAfterMandatorySelection(): void
    {
        self::assertSame(['id'], $this->scope->getRaw()['select']);
        foreach ($this->composers as $composer) {
            $query = SelectBuilder::create()->from('Initiative')->select(['id'])->where(['id=s' => $this->scope])->order('id')->build();
            self::assertSame(['a', 'b'], $this->pdo->query($composer->composeSelect($query))->fetchAll(PDO::FETCH_COLUMN));
        }
    }

    private function queryGroups(string $group): array
    {
        $request = $this->createMock(Request::class);
        [$query, $key] = $this->inbox->groupedQuery($this->scope, $request, $group);
        $query->select($key, 'groupKey')->select(Expr::count(Expr::column('id')), 'count')->group(Expr::alias('groupKey'))->order(Expr::alias('groupKey'));
        $result = null;
        foreach ($this->composers as $composer) {
            $rows = $this->pdo->query($composer->composeSelect($query->build()))->fetchAll(PDO::FETCH_KEY_PAIR);
            if ($result !== null) self::assertSame($result, $rows);
            $result = $rows;
        }
        return $result;
    }

    public function testGroupTotalsRespectTheAuthorizedScopeAndKeepCompletedRecords(): void
    {
        self::assertSame(['status:Completed' => 1, 'status:Open' => 1], $this->queryGroups('status'));
        self::assertSame(['initiativeType:type-a' => 2], $this->queryGroups('initiativeType'));
        self::assertSame(['all' => 2], $this->queryGroups('none'));
        self::assertSame(['activity:noNextAction' => 2], $this->queryGroups('activity'));
    }

    public function testReadStatusGroupingRanksUnreadBeforePagination(): void
    {
        self::assertSame(['read' => 1, 'unread' => 1], $this->queryGroups('readStatus'));
        $request = $this->createMock(Request::class);
        [$query, $key] = $this->inbox->groupedQuery($this->scope, $request, 'readStatus');
        $query->select('id')->select($key, 'groupKey')->order(Expr::alias('groupKey'), 'DESC')->limit(0, 1);
        foreach ($this->composers as $composer) {
            self::assertSame(['a'], $this->pdo->query($composer->composeSelect($query->build()))->fetchAll(PDO::FETCH_COLUMN));
        }
    }
}
