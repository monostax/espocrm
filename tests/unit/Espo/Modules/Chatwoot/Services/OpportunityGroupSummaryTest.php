<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use DateTimeImmutable;
use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Currency\ConfigDataProvider;
use Espo\Core\Currency\Rates;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\SearchParamsFetcher;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\SelectBuilder as AccessBuilder;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\OpportunityActivityBuckets;
use Espo\Modules\Chatwoot\Services\OpportunityActivitySummary;
use Espo\Modules\Chatwoot\Services\OpportunityBulkPostAccess;
use Espo\Modules\Chatwoot\Services\OpportunityGroupSummary;
use Espo\Modules\Chatwoot\Services\OpportunityReadStateService;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityCollection;
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

/** Execute real aggregate SQL on disposable data; authorization inputs are controlled explicitly. */
class OpportunityGroupSummaryTest extends TestCase
{
    private PDO $pdo;
    private MysqlQueryComposer $mysql;
    private PostgresqlQueryComposer $postgres;
    private MysqlQueryComposer|PostgresqlQueryComposer $composer;
    private OpportunityGroupSummary $service;
    private OpportunityActivityBuckets $buckets;
    private array $accounts;
    private array $hiddenFields = [];
    private array $hiddenScopes = [];
    private array $filter = [];
    private bool $admin = false;
    private bool $accountReadable = true;
    private bool $tenantMember = true;
    private int $queryCount = 0;
    private string $lastSql = '';

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->sqliteCreateFunction('CONCAT', fn (...$parts) => implode('', $parts));
        $this->pdo->sqliteCreateFunction('IF', fn ($condition, $yes, $no) => $condition ? $yes : $no);
        $this->pdo->sqliteCreateFunction('LEAST', fn (...$values) => min($values));
        $defs = [];
        $tables = [
            'Opportunity' => ['id', 'tenantId', 'opportunityStageId', 'funnelId', 'assignedUserId', 'status',
                'amount', 'amountCurrency', 'readable', 'unread', 'deleted'],
            'Visibility' => ['id', 'opportunityId', 'deleted'],
            'Meeting' => ['id', 'parentId', 'parentType', 'dateEnd', 'dateEndDate', 'readable', 'deleted'],
            'Call' => ['id', 'parentId', 'parentType', 'dateEnd', 'readable', 'deleted'],
            'Task' => ['id', 'parentId', 'parentType', 'dateEnd', 'dateEndDate', 'readable', 'deleted'],
        ];
        foreach ($tables as $type => $fields) {
            $columns = [];
            foreach ($fields as $field) {
                $column = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $field));
                $numeric = in_array($field, ['amount', 'readable', 'unread', 'deleted']);
                $columns[] = "$column " . ($numeric ? 'NUMERIC DEFAULT 0' : 'TEXT');
                $defs[$type]['attributes'][$field] = ['type' => $numeric ? 'float' : 'varchar'];
            }
            $this->pdo->exec('CREATE TABLE ' . strtolower($type) . ' (' . implode(', ', $columns) . ')');
        }
        $provider = $this->createMock(MetadataDataProvider::class);
        $provider->method('get')->willReturn($defs);
        $metadata = new Metadata($provider);
        $entities = $this->createMock(EntityFactory::class);
        $entities->method('create')->willReturnCallback(fn ($type) => new BaseEntity($type, $defs[$type]));
        $this->mysql = new MysqlQueryComposer($this->pdo, $entities, $metadata);
        $this->postgres = new PostgresqlQueryComposer($this->pdo, $entities, $metadata);
        $this->composer = $this->mysql;

        $em = $this->createMock(EntityManager::class);
        $executor = $this->createMock(QueryExecutor::class);
        $executor->method('execute')->willReturnCallback(function ($query) {
            $this->queryCount++;
            $this->lastSql = $this->composer->composeSelect($query);
            return $this->pdo->query($this->lastSql);
        });
        $em->method('getQueryExecutor')->willReturn($executor);
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturnCallback(fn ($type) => !in_array($type, $this->hiddenScopes));
        $acl->method('checkField')->willReturnCallback(fn ($type, $field) => !in_array("$type.$field", $this->hiddenFields));
        $acl->method('checkEntityRead')->willReturnCallback(fn () => $this->accountReadable);
        $user = $this->createMock(User::class);
        $user->method('isActive')->willReturn(true);
        $user->method('isRegular')->willReturn(true);
        $user->method('isAdmin')->willReturnCallback(fn () => $this->admin);
        $tenants = $this->createMock(UserTenantResolver::class);
        $tenants->method('canActForTenant')->willReturnCallback(fn () => $this->tenantMember);
        $account = new BaseEntity('ChatwootAccount', ['attributes' => array_fill_keys(['id', 'tenantId', 'platformId'], ['type' => 'varchar'])]);
        $account->set(['id' => 'workspace', 'tenantId' => 'tenant-a', 'platformId' => 'platform']);
        $this->accounts = [$account];
        $repo = $this->createMock(RDBRepository::class);
        $accountQuery = $this->createMock(RDBSelectBuilder::class);
        $repo->method('where')->with(['chatwootAccountId' => 1])->willReturn($accountQuery);
        $accountQuery->method('limit')->with(0, 2)->willReturnSelf();
        $accountQuery->method('find')->willReturnCallback(fn () => new EntityCollection($this->accounts));
        $em->method('getRDBRepository')->with('ChatwootAccount')->willReturn($repo);
        $workspace = new OpportunityBulkPostAccess($em, $user, $acl, $tenants);

        $factory = $this->createMock(SelectBuilderFactory::class);
        $factory->method('create')->willReturnCallback(function () {
            $builder = $this->createMock(AccessBuilder::class);
            $type = null;
            $strict = false;
            $builder->method('from')->willReturnCallback(function ($entity) use (&$type, $builder) {
                $type = $entity;
                return $builder;
            });
            $builder->method('withSearchParams')->willReturnCallback(function ($params) use ($builder) {
                self::assertSame(['id'], $params->getSelect());
                self::assertNull($params->getMaxSize());
                self::assertNull($params->getOffset());
                return $builder;
            });
            $builder->method('withPrimaryFilter')->willReturnCallback(function ($filter) use (&$type, $builder) {
                self::assertSame($type === 'Task' ? 'actual' : 'planned', $filter);
                return $builder;
            });
            $builder->method('withStrictAccessControl')->willReturnCallback(function () use (&$strict, $builder) {
                $strict = true;
                return $builder;
            });
            $builder->method('buildQueryBuilder')->willReturnCallback(function () use (&$strict, &$type) {
                self::assertTrue($strict, 'Every opportunity/activity scope must apply strict ACL.');
                $query = SelectBuilder::create()->from($type);
                if (!$this->admin) $query->where(['readable' => 1]);
                if ($type === 'Opportunity') {
                    // A relationship filter duplicates opp-a, exercising the semi-join.
                    $query->join('Visibility', 'visibility', ['visibility.opportunityId:' => 'opportunity.id']);
                    $query->where($this->filter);
                }
                return $query;
            });
            return $builder;
        });
        $fetcher = $this->createMock(SearchParamsFetcher::class);
        $fetcher->method('fetch')->willReturn(SearchParams::fromRaw(['maxSize' => 1, 'offset' => 50]));
        $readStates = $this->createMock(OpportunityReadStateService::class);
        $readStates->method('applyListFilter')->willReturnCallback(fn ($query) => $query->where(['unread' => 1]));
        $currency = $this->createMock(ConfigDataProvider::class);
        $currency->method('getBaseCurrency')->willReturn('BRL');
        $currency->method('getDefaultCurrency')->willReturn('USD');
        $currency->method('getCurrencyRates')->willReturn(Rates::fromAssoc(['BRL' => 1.0, 'USD' => 5.0], 'BRL'));
        $this->buckets = new OpportunityActivityBuckets($factory, $acl);
        $this->service = new OpportunityGroupSummary($em, $factory, $fetcher, $workspace, $readStates, $this->buckets, $currency, $acl);
        $this->pdo->exec("INSERT INTO opportunity (id, tenant_id, opportunity_stage_id, amount, amount_currency, readable, unread) VALUES
            ('a', 'tenant-a', 'stage', 499, 'BRL', 1, 1), ('b', 'tenant-a', 'stage', 499, 'BRL', 1, 0),
            ('c', 'tenant-a', 'stage', 80, 'USD', 1, 0), ('null', 'tenant-a', NULL, NULL, 'BRL', 1, 0),
            ('hidden', 'tenant-a', 'stage', 9000, 'BRL', 0, 0), ('other', 'tenant-b', 'stage', 9999, 'BRL', 1, 0)");
        $this->pdo->exec("INSERT INTO visibility (id, opportunity_id) VALUES
            ('1', 'a'), ('2', 'a'), ('3', 'b'), ('4', 'c'), ('5', 'hidden'), ('6', 'other'), ('7', 'null')");
    }

    private function summary(array $params = []): array
    {
        $params += ['chatwootAccountId' => '1', 'groupBy' => 'stage'];
        $request = $this->createMock(Request::class);
        $request->method('getQueryParam')->willReturnCallback(fn ($key) => $params[$key] ?? null);
        return $this->service->get($request);
    }

    public function testAllPagesAclTenantDeduplicationAndBaseCurrencyInOneQuery(): void
    {
        foreach ([$this->mysql, $this->postgres] as $this->composer) {
            $this->queryCount = 0;
            $result = $this->summary();
            self::assertSame('BRL', $result['currency']);
            self::assertSame(['count' => 3, 'amount' => 1398.0], $result['groups']->{'stage:stage'});
            self::assertSame(['count' => 1, 'amount' => 0.0], $result['groups']->{'stage:'});
            self::assertSame(1, $this->queryCount);
            self::assertStringNotContainsString('LIMIT', $this->lastSql);
        }
    }

    public function testAdminRemainsInsideSelectedWorkspaceAndClientCannotBroadenIt(): void
    {
        $this->admin = true;
        self::assertSame(4, $this->summary()['groups']->{'stage:stage'}['count']);
        $this->filter = ['tenantId' => 'tenant-b'];
        self::assertSame([], (array) $this->summary()['groups']);
    }

    public function testMultiTenantUserReceivesOnlyTheResolvedWorkspace(): void
    {
        // Membership permits both tenants; the workspace still selects exactly one.
        self::assertSame(1398.0, $this->summary()['groups']->{'stage:stage'}['amount']);
        $this->accounts[0]->set('tenantId', 'tenant-b');
        self::assertSame(['count' => 1, 'amount' => 9999.0], $this->summary()['groups']->{'stage:stage'});
    }

    public function testAllDirectGroupingsPreserveTheSameCountAndAmount(): void
    {
        foreach (['funnel', 'assignee', 'status'] as $groupBy) {
            $groups = (array) $this->summary(['groupBy' => $groupBy])['groups'];
            self::assertSame([$groupBy . ':' => ['count' => 4, 'amount' => 1398.0]], $groups);
        }
    }

    public function testReadStatusDoesNotMultiplyDuplicatesAndNoGroupingHasOneTotal(): void
    {
        foreach ([$this->mysql, $this->postgres] as $this->composer) {
            $this->queryCount = 0;
            $groups = $this->summary(['groupBy' => 'readStatus'])['groups'];
            self::assertSame(['count' => 1, 'amount' => 499.0], $groups->unread);
            self::assertSame(['count' => 3, 'amount' => 899.0], $groups->read);
            self::assertSame(1, $this->queryCount);
            self::assertSame(['count' => 4, 'amount' => 1398.0], $this->summary(['groupBy' => 'none'])['groups']->all);
        }
    }

    public function testAmountFieldAclReturnsCountsWithoutSelectingMoney(): void
    {
        $this->hiddenFields = ['Opportunity.amount'];
        $result = $this->summary();
        self::assertNull($result['currency']);
        self::assertSame(['count' => 3, 'amount' => null], $result['groups']->{'stage:stage'});
        self::assertStringNotContainsString('amount_currency', $this->lastSql);
    }

    public function testGroupingFieldAclIsRequired(): void
    {
        $this->hiddenFields = ['Opportunity.opportunityStage'];
        $this->expectException(Forbidden::class);
        $this->summary();
    }

    public function testOpportunityReadScopeIsRequired(): void
    {
        $this->hiddenScopes = ['Opportunity'];
        $this->expectException(Forbidden::class);
        $this->summary();
    }

    public function testWorkspaceAccountAclIsRequired(): void
    {
        $this->accountReadable = false;
        $this->expectException(Forbidden::class);
        $this->summary();
    }

    public function testTenantMembershipIsRequiredEvenWithAccountReadAccess(): void
    {
        $this->tenantMember = false;
        $this->expectException(Forbidden::class);
        $this->summary();
    }

    public function testAmbiguousCrossPlatformWorkspaceFailsClosed(): void
    {
        $this->accounts[] = clone $this->accounts[0];
        $this->expectException(NotFound::class);
        $this->summary();
    }

    public function testMissingWorkspaceFailsClosed(): void
    {
        $this->expectException(BadRequest::class);
        $this->summary(['chatwootAccountId' => null]);
    }

    public function testActivityBucketsMatchExistingRulesAndActivityAclAcrossDst(): void
    {
        $now = new DateTimeImmutable('2026-03-08 12:00:00', new \DateTimeZone('America/New_York'));
        $this->pdo->exec("INSERT INTO task (id, parent_id, parent_type, date_end, date_end_date, readable) VALUES
            ('t1', 'a', 'Opportunity', NULL, '2026-03-08', 1),
            ('t2', 'a', 'Opportunity', NULL, NULL, 1),
            ('t3', 'b', 'Opportunity', NULL, '2026-03-09', 1),
            ('t4', 'c', 'Opportunity', '2026-03-08 15:59:59', NULL, 1),
            ('secret', 'b', 'Opportunity', NULL, '2026-03-01', 0)");
        $this->pdo->exec("INSERT INTO call (id, parent_id, parent_type, date_end, readable) VALUES
            ('c1', 'b', 'Opportunity', '2026-03-09 03:59:59', 1),
            ('c2', 'a', 'Opportunity', '2026-03-09 04:00:00', 1)");
        $scope = SelectBuilder::create()->from('Opportunity')->select(['id'])->where(['tenantId' => 'tenant-a', 'readable' => 1])->build();
        $query = SelectBuilder::create()->from('Opportunity')->where(['id=s' => $scope]);
        $bucket = $this->buckets->apply($query, $scope, $now);
        $query->select(['id'])->select($bucket, 'bucket');
        $activities = $this->pdo->query("SELECT parent_id AS parentId, date_end AS dateEnd, date_end_date AS dateEndDate FROM task WHERE readable = 1
            UNION ALL SELECT parent_id, date_end, NULL FROM call WHERE readable = 1")->fetchAll(PDO::FETCH_ASSOC);
        $expected = OpportunityActivitySummary::summarize($activities, $now, true, ['a', 'b', 'c', 'null']);
        foreach ([$this->mysql, $this->postgres] as $composer) {
            $rows = $this->pdo->query($composer->composeSelect($query->build()))->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($expected as $key => $group) {
                foreach ($group['opportunityIds'] as $id) self::assertSame($key, $rows[$id]);
            }
        }
    }

    public function testActivitySummaryAndFilterStayInOneAggregateQuery(): void
    {
        $this->pdo->exec("INSERT INTO task (id, parent_id, parent_type, date_end_date, readable) VALUES
            ('t1', 'a', 'Opportunity', '2000-01-01', 1), ('t2', 'a', 'Opportunity', '2000-01-02', 1),
            ('t3', 'other', 'Opportunity', '2000-01-01', 1)");
        $this->queryCount = 0;
        $groups = $this->summary(['groupBy' => 'activity'])['groups'];
        self::assertSame(['count' => 1, 'amount' => 499.0], $groups->{'activity:overdue'});
        self::assertSame(['count' => 3, 'amount' => 899.0], $groups->{'activity:noActivities'});
        self::assertSame(1, $this->queryCount);
        $filtered = $this->summary(['activity' => 'overdue']);
        self::assertSame(['count' => 1, 'amount' => 499.0], $filtered['groups']->{'stage:stage'});
    }

    public function testActivityScopeAndDeadlineFieldAcl(): void
    {
        $this->hiddenScopes = ['Meeting', 'Call', 'Task'];
        $groups = $this->summary(['groupBy' => 'activity'])['groups'];
        self::assertSame(['count' => 4, 'amount' => 1398.0], $groups->{'activity:noActivities'});
        $this->hiddenScopes = [];
        $this->hiddenFields = ['Task.dateEnd'];
        $this->expectException(Forbidden::class);
        $this->summary(['groupBy' => 'activity']);
    }

    public function testLargeScopeKeepsAConstantQueryCountAndSmallResponse(): void
    {
        $this->pdo->exec('CREATE INDEX opportunity_id ON opportunity (id)');
        $this->pdo->exec('CREATE INDEX opportunity_tenant ON opportunity (tenant_id)');
        $this->pdo->exec('CREATE INDEX visibility_parent ON visibility (opportunity_id)');
        $this->pdo->exec("WITH RECURSIVE numbers(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM numbers WHERE n < 25000)
            INSERT INTO opportunity (id, tenant_id, opportunity_stage_id, amount, amount_currency, readable)
            SELECT 'bulk-' || n, CASE WHEN n % 2 = 0 THEN 'tenant-a' ELSE 'tenant-b' END, 'stage', 10, 'BRL', 1 FROM numbers");
        $this->pdo->exec("INSERT INTO visibility (id, opportunity_id) SELECT id, id FROM opportunity WHERE id LIKE 'bulk-%'");
        $this->queryCount = 0;
        $result = $this->summary();
        self::assertSame(['count' => 12503, 'amount' => 126398.0], $result['groups']->{'stage:stage'});
        self::assertCount(2, (array) $result['groups']);
        self::assertSame(1, $this->queryCount);
        $plan = $this->pdo->query('EXPLAIN QUERY PLAN ' . $this->lastSql)->fetchAll(PDO::FETCH_ASSOC);
        self::assertStringContainsString('opportunity_tenant', implode(' ', array_column($plan, 'detail')));
    }
}
