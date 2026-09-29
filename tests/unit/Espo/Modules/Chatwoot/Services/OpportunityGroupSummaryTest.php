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
use Espo\Modules\Chatwoot\Services\OpportunitySpreadsheetSummary;
use Espo\Modules\Chatwoot\Classes\Select\Opportunity\StreamActivity;
use Espo\Modules\Chatwoot\Services\OpportunityReadStateService;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityFactory;
use Espo\ORM\EntityManager;
use Espo\ORM\Executor\QueryExecutor;
use Espo\ORM\Executor\SqlExecutor;
use Espo\ORM\Metadata;
use Espo\ORM\MetadataDataProvider;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\QueryComposer\MysqlQueryComposer;
use Espo\ORM\QueryComposer\PostgresqlQueryComposer;
use Espo\ORM\QueryComposer\QueryComposerWrapper;
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
    private OpportunitySpreadsheetSummary $spreadsheet;
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
                'amount', 'amountCurrency', 'name', 'probability', 'accountId', 'nextActionId', 'nextActionType', 'readable', 'unread', 'deleted'],
            'Account' => ['id', 'name', 'deleted'],
            'Visibility' => ['id', 'opportunityId', 'deleted'],
            'Meeting' => ['id', 'parentId', 'parentType', 'dateEnd', 'dateEndDate', 'pending', 'readable', 'deleted'],
            'Call' => ['id', 'parentId', 'parentType', 'dateEnd', 'pending', 'readable', 'deleted'],
            'Task' => ['id', 'parentId', 'parentType', 'dateEnd', 'dateEndDate', 'pending', 'readable', 'deleted'],
        ];
        foreach ($tables as $type => $fields) {
            $columns = [];
            foreach ($fields as $field) {
                $column = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $field));
                $numeric = in_array($field, ['amount', 'probability', 'pending', 'readable', 'unread', 'deleted']);
                $columns[] = "$column " . ($numeric ? 'NUMERIC DEFAULT ' . ($field === 'pending' ? 1 : 0) : 'TEXT');
                $defs[$type]['attributes'][$field] = ['type' => $numeric ? 'float' : 'varchar'];
            }
            $this->pdo->exec('CREATE TABLE ' . strtolower($type) . ' (' . implode(', ', $columns) . ')');
        }
        $defs['Opportunity']['attributes']['accountName'] = [
            'type' => 'foreign', 'notStorable' => true, 'relation' => 'account', 'foreign' => 'name',
        ];
        $defs['Opportunity']['relations']['account'] = [
            'type' => 'belongsTo', 'entity' => 'Account', 'key' => 'accountId', 'foreignKey' => 'id',
        ];
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
        $em->method('getQueryComposer')->willReturnCallback(fn () => new QueryComposerWrapper($this->composer));
        $sqlExecutor = $this->createMock(SqlExecutor::class);
        $sqlExecutor->method('execute')->willReturnCallback(function ($sql) {
            $this->queryCount++;
            $this->lastSql = $sql;
            return $this->pdo->query($sql);
        });
        $em->method('getSqlExecutor')->willReturn($sqlExecutor);
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
                } else {
                    $query->where(['pending' => 1]);
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
        $this->spreadsheet = new OpportunitySpreadsheetSummary(
            $em, $this->service, $currency, $this->createMock(StreamActivity::class), $acl
        );
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

    private function spreadsheetSummary(array $calculations, array $params = []): array
    {
        $params += ['chatwootAccountId' => '1', 'calculations' => json_encode($calculations)];
        $request = $this->createMock(Request::class);
        $request->method('getQueryParam')->willReturnCallback(fn ($key) => $params[$key] ?? null);
        return $this->spreadsheet->get($request);
    }

    public function testSpreadsheetSummariesCoverAllPagesWithoutDuplicateRows(): void
    {
        foreach ([$this->mysql, $this->postgres] as $this->composer) {
            foreach (['sum' => 1398.0, 'avg' => 466.0, 'min' => 400.0, 'max' => 499.0,
                'count' => 4, 'filled' => 3, 'empty' => 1, 'unique' => 2] as $operation => $expected) {
                $this->queryCount = 0;
                $result = $this->spreadsheetSummary(['name' => 'count', 'amount' => $operation]);
                self::assertSame(4, $result['total']);
                self::assertSame(4, $result['values']->name);
                self::assertSame($expected, $result['values']->amount);
                self::assertSame('BRL', $result['currency']);
                self::assertSame(1, $this->queryCount);
                self::assertStringNotContainsString('LIMIT', $this->lastSql);
            }
        }
    }

    public function testSpreadsheetBlanksAndZeroes(): void
    {
        $this->pdo->exec("UPDATE opportunity SET name = CASE id WHEN 'a' THEN 'Deal' WHEN 'b' THEN 'Deal' WHEN 'c' THEN '  ' END,
            probability = CASE id WHEN 'a' THEN 0 WHEN 'b' THEN 100 WHEN 'c' THEN 50 END");
        foreach (['count' => 4, 'filled' => 2, 'empty' => 2, 'unique' => 1] as $operation => $expected) {
            self::assertSame($expected, $this->spreadsheetSummary(['name' => $operation])['values']->name);
        }
        foreach (['sum' => 150.0, 'avg' => 50.0, 'min' => 0.0, 'max' => 100.0, 'filled' => 3] as $operation => $expected) {
            self::assertSame($expected, $this->spreadsheetSummary(['probability' => $operation])['values']->probability);
        }
        $this->filter = ['id' => 'null'];
        self::assertNull($this->spreadsheetSummary(['amount' => 'avg'])['values']->amount);
        self::assertSame(0.0, $this->spreadsheetSummary(['amount' => 'sum'])['values']->amount);
    }

    public function testSpreadsheetLinkedNamesAreProjectedBeforeAggregation(): void
    {
        $this->pdo->exec("INSERT INTO account (id, name) VALUES ('acme', 'Acme'), ('beta', 'Beta')");
        $this->pdo->exec("UPDATE opportunity SET account_id = CASE id WHEN 'a' THEN 'acme' WHEN 'b' THEN 'acme' WHEN 'c' THEN 'beta' END");
        foreach ([$this->mysql, $this->postgres] as $this->composer) {
            foreach (['count' => 4, 'filled' => 3, 'empty' => 1, 'unique' => 2] as $operation => $expected) {
                self::assertSame($expected, $this->spreadsheetSummary(['accountName' => $operation])['values']->accountName);
            }
        }
    }

    public function testSpreadsheetFilteredEmptyScopeAndFieldAccess(): void
    {
        $this->filter = ['id' => 'missing'];
        foreach (['sum' => 0.0, 'avg' => null, 'min' => null, 'max' => null, 'count' => 0, 'empty' => 0] as $operation => $expected) {
            $result = $this->spreadsheetSummary(['amount' => $operation]);
            self::assertSame(0, $result['total']);
            self::assertSame($expected, $result['values']->amount);
        }
        $this->filter = ['unread' => 1];
        self::assertSame(499.0, $this->spreadsheetSummary(['amount' => 'sum'])['values']->amount);
        $this->hiddenFields = ['Opportunity.amount'];
        $result = $this->spreadsheetSummary(['name' => 'count', 'amount' => 'sum']);
        self::assertNull($result['currency']);
        self::assertNull($result['values']->amount);
        self::assertSame(1, $result['values']->name);
        self::assertSame(['amount'], $result['unavailable']);
        self::assertStringNotContainsString('amount', $this->lastSql);
    }

    public function testSpreadsheetUsesActivityAndWorkspaceFilters(): void
    {
        self::assertSame(4, $this->spreadsheetSummary(['name' => 'count'], ['activity' => 'noNextAction'])['values']->name);
        self::assertSame(0, $this->spreadsheetSummary(['name' => 'count'], ['activity' => 'overdue'])['values']->name);
        $this->accounts[0]->set('tenantId', 'tenant-b');
        self::assertSame(9999.0, $this->spreadsheetSummary(['amount' => 'sum'])['values']->amount);
        $this->tenantMember = false;
        $this->expectException(Forbidden::class);
        $this->spreadsheetSummary(['name' => 'count']);
    }

    public function testSpreadsheetRejectsUnsupportedFieldsAndOperations(): void
    {
        foreach ([['name' => 'sum'], ['tenantId' => 'unique'], ['amount' => 'SUM(id)'], ['name' => []]] as $calculations) {
            try {
                $this->spreadsheetSummary($calculations);
                self::fail('Invalid calculation was accepted.');
            } catch (BadRequest) {
                self::assertSame(0, $this->queryCount);
            }
        }
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
        $this->pdo->exec("UPDATE opportunity SET next_action_id = 'c2', next_action_type = 'Call' WHERE id = 'a'");
        $this->pdo->exec("UPDATE opportunity SET next_action_id = 't3', next_action_type = 'Task' WHERE id = 'b'");
        $this->pdo->exec("UPDATE opportunity SET next_action_id = 't4', next_action_type = 'Task' WHERE id = 'c'");
        $scope = SelectBuilder::create()->from('Opportunity')->select(['id'])->where(['tenantId' => 'tenant-a', 'readable' => 1])->build();
        $query = SelectBuilder::create()->from('Opportunity')->where(['id=s' => $scope]);
        $bucket = $this->buckets->apply($query, $scope, $now);
        $query->select(['id'])->select($bucket, 'bucket');
        $activities = $this->pdo->query("SELECT id, 'Task' AS entityType, parent_id AS parentId, date_end AS dateEnd, date_end_date AS dateEndDate FROM task WHERE readable = 1
            UNION ALL SELECT id, 'Call', parent_id, date_end, NULL FROM call WHERE readable = 1")->fetchAll(PDO::FETCH_ASSOC);
        $expected = OpportunityActivitySummary::summarize($activities, $now, true, [
            ['id' => 'a', 'nextActionId' => 'c2', 'nextActionType' => 'Call'],
            ['id' => 'b', 'nextActionId' => 't3', 'nextActionType' => 'Task'],
            ['id' => 'c', 'nextActionId' => 't4', 'nextActionType' => 'Task'],
            ['id' => 'null'],
        ]);
        self::assertSame([], $expected['today']['opportunityIds']);
        self::assertEqualsCanonicalizing(['a', 'b'], $expected['tomorrow']['opportunityIds']);
        self::assertSame(['c'], $expected['overdue']['opportunityIds']);
        self::assertSame(['null'], $expected['noNextAction']['opportunityIds']);
        self::assertSame(4, array_sum(array_column($expected, 'count')));
        foreach ([$this->mysql, $this->postgres] as $composer) {
            $rows = $this->pdo->query($composer->composeSelect($query->build()))->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($expected as $key => $group) {
                foreach ($group['opportunityIds'] as $id) self::assertSame($key, $rows[$id]);
            }
        }
    }

    public function testNextSevenDaysAndFutureBoundariesMatchSummaryAndSqlAcrossDst(): void
    {
        $now = new DateTimeImmutable('2026-03-07 12:00:00', new \DateTimeZone('America/New_York'));
        $opportunities = [
            ['id' => 'a', 'nextActionId' => 'task', 'nextActionType' => 'Task'],
            ['id' => 'b', 'nextActionId' => 'call', 'nextActionType' => 'Call'],
            ['id' => 'c', 'nextActionId' => 'meeting', 'nextActionType' => 'Meeting'],
        ];
        foreach ($opportunities as $opportunity) {
            $table = strtolower($opportunity['nextActionType']);
            $this->pdo->prepare("INSERT INTO $table (id, parent_id, parent_type, readable) VALUES (?, ?, 'Opportunity', 1)")
                ->execute([$opportunity['nextActionId'], $opportunity['id']]);
            $this->pdo->prepare('UPDATE opportunity SET next_action_id = ?, next_action_type = ? WHERE id = ?')
                ->execute([$opportunity['nextActionId'], $opportunity['nextActionType'], $opportunity['id']]);
        }
        $scope = SelectBuilder::create()->from('Opportunity')->select(['id'])->where(['id' => ['a', 'b', 'c']])->build();
        $query = SelectBuilder::create()->from('Opportunity')->where(['id=s' => $scope]);
        $bucket = $this->buckets->apply($query, $scope, $now);
        $query->select(['id'])->select($bucket, 'bucket')->order('id');

        foreach ([
            ['2026-03-07', '2026-03-08 04:59:59', 'today'],
            ['2026-03-08', '2026-03-08 05:00:00', 'tomorrow'],
            ['2026-03-10', '2026-03-10 16:00:00', 'tomorrow'],
            ['2026-03-14', '2026-03-15 03:59:59', 'tomorrow'],
            ['2026-03-15', '2026-03-15 04:00:00', 'upcoming'],
        ] as [$date, $timestamp, $expected]) {
            $this->pdo->prepare('UPDATE task SET date_end_date = ?')->execute([$date]);
            $this->pdo->prepare('UPDATE call SET date_end = ?')->execute([$timestamp]);
            $this->pdo->prepare('UPDATE meeting SET date_end = ?')->execute([$timestamp]);
            $summary = OpportunityActivitySummary::summarize([
                ['id' => 'task', 'parentId' => 'a', 'entityType' => 'Task', 'dateEndDate' => $date],
                ['id' => 'call', 'parentId' => 'b', 'entityType' => 'Call', 'dateEnd' => $timestamp],
                ['id' => 'meeting', 'parentId' => 'c', 'entityType' => 'Meeting', 'dateEnd' => $timestamp],
            ], $now, true, $opportunities);
            self::assertSame(['count' => 3, 'opportunityIds' => ['a', 'b', 'c']], $summary[$expected]);
            self::assertSame(3, array_sum(array_column($summary, 'count')));
            foreach ([$this->mysql, $this->postgres] as $composer) {
                $rows = $this->pdo->query($composer->composeSelect($query->build()))->fetchAll(PDO::FETCH_KEY_PAIR);
                self::assertSame(['a' => $expected, 'b' => $expected, 'c' => $expected], $rows);
            }
        }
    }

    public function testActivitySummaryAndFilterStayInOneAggregateQuery(): void
    {
        $this->pdo->exec("INSERT INTO task (id, parent_id, parent_type, date_end_date, readable) VALUES
            ('t1', 'a', 'Opportunity', '2000-01-01', 1), ('t2', 'a', 'Opportunity', '2000-01-02', 1),
            ('t3', 'other', 'Opportunity', '2000-01-01', 1)");
        $this->pdo->exec("UPDATE opportunity SET next_action_id = 't1', next_action_type = 'Task' WHERE id = 'a'");
        $this->queryCount = 0;
        $groups = $this->summary(['groupBy' => 'activity'])['groups'];
        self::assertSame(['count' => 1, 'amount' => 499.0], $groups->{'activity:overdue'});
        self::assertSame(['count' => 3, 'amount' => 899.0], $groups->{'activity:noNextAction'});
        self::assertSame(1, $this->queryCount);
        $filtered = $this->summary(['activity' => 'overdue']);
        self::assertSame(['count' => 1, 'amount' => 499.0], $filtered['groups']->{'stage:stage'});
    }

    public function testActivityScopeAndDeadlineFieldAcl(): void
    {
        $this->hiddenScopes = ['Meeting', 'Call', 'Task'];
        $groups = $this->summary(['groupBy' => 'activity'])['groups'];
        self::assertSame(['count' => 4, 'amount' => 1398.0], $groups->{'activity:noNextAction'});
        $this->hiddenScopes = [];
        $this->hiddenFields = ['Task.dateEnd'];
        $this->expectException(Forbidden::class);
        $this->summary(['groupBy' => 'activity']);
    }

    public function testMissingNextActionsOutrankAllDatesWithMatchingFiltersAndAmounts(): void
    {
        $this->pdo->exec("INSERT INTO task (id, parent_id, parent_type, date_end_date, readable) VALUES
            ('overdue', 'a', 'Opportunity', '2000-01-01', 1),
            ('future', 'b', 'Opportunity', '2999-01-01', 1),
            ('selected', 'c', 'Opportunity', '2999-01-01', 1)");
        $this->pdo->exec("UPDATE opportunity SET next_action_id = 'unavailable', next_action_type = 'Task' WHERE id = 'b'");
        $this->pdo->exec("UPDATE opportunity SET next_action_id = 'selected', next_action_type = 'Task' WHERE id = 'c'");
        $summary = OpportunityActivitySummary::summarize([
            ['id' => 'overdue', 'entityType' => 'Task', 'parentId' => 'a', 'dateEndDate' => '2000-01-01'],
            ['id' => 'future', 'entityType' => 'Task', 'parentId' => 'b', 'dateEndDate' => '2999-01-01'],
            ['id' => 'selected', 'entityType' => 'Task', 'parentId' => 'c', 'dateEndDate' => '2999-01-01'],
        ], new DateTimeImmutable('now'), true, [
            ['id' => 'a'],
            ['id' => 'b', 'nextActionId' => 'unavailable', 'nextActionType' => 'Task'],
            ['id' => 'c', 'nextActionId' => 'selected', 'nextActionType' => 'Task'],
            ['id' => 'null'],
        ]);
        self::assertSame(['count' => 0, 'opportunityIds' => []], $summary['overdue']);
        self::assertSame(['count' => 3, 'opportunityIds' => ['a', 'b', 'null']], $summary['noNextAction']);
        self::assertSame(['count' => 1, 'opportunityIds' => ['c']], $summary['upcoming']);

        foreach ([$this->mysql, $this->postgres] as $this->composer) {
            $groups = $this->summary(['groupBy' => 'activity'])['groups'];
            self::assertObjectNotHasProperty('activity:overdue', $groups);
            self::assertSame(['count' => 3, 'amount' => 998.0], $groups->{'activity:noNextAction'});
            self::assertSame(['count' => 1, 'amount' => 400.0], $groups->{'activity:upcoming'});
            $filtered = $this->summary(['activity' => 'noNextAction', 'groupBy' => 'none']);
            self::assertSame(['count' => 3, 'amount' => 998.0], $filtered['groups']->all);
            $filteredGroups = $this->summary(['activity' => 'noNextAction', 'groupBy' => 'activity']);
            self::assertSame(['activity:noNextAction' => ['count' => 3, 'amount' => 998.0]], (array) $filteredGroups['groups']);
            $legacyGroups = $this->summary(['activity' => 'noActivities', 'groupBy' => 'activity'])['groups'];
            self::assertSame((array) $filteredGroups['groups'], (array) $legacyGroups);
        }
    }

    public function testNoNextActionExcludesWonAndLostFromIdsCountsAndAmounts(): void
    {
        $this->pdo->exec("UPDATE opportunity SET status = CASE id WHEN 'a' THEN 'Won' WHEN 'b' THEN 'Lost' ELSE 'Open' END");
        // A stale selected step on a closed opportunity must not put it back in noNextAction.
        $this->pdo->exec("UPDATE opportunity SET next_action_id = 'missing', next_action_type = 'Task' WHERE id = 'b'");
        $opportunities = [
            ['id' => 'a', 'status' => 'Won'],
            ['id' => 'b', 'status' => 'Lost', 'nextActionId' => 'missing', 'nextActionType' => 'Task'],
            ['id' => 'c', 'status' => 'Open'],
            ['id' => 'null', 'status' => 'Open'],
        ];
        $summary = OpportunityActivitySummary::summarize([], new DateTimeImmutable('now'), true, $opportunities);
        self::assertSame(['count' => 2, 'opportunityIds' => ['c', 'null']], $summary['noNextAction']);
        $counts = OpportunityActivitySummary::summarize([], new DateTimeImmutable('now'), false, $opportunities);
        self::assertSame(['count' => 2], $counts['noNextAction']);

        foreach ([$this->mysql, $this->postgres] as $this->composer) {
            foreach ([[], ['Meeting', 'Call', 'Task']] as $this->hiddenScopes) {
                $this->queryCount = 0;
                $groups = $this->summary(['groupBy' => 'activity'])['groups'];
                self::assertSame(['activity:noNextAction' => ['count' => 2, 'amount' => 400.0]], (array) $groups);
                self::assertSame(1, $this->queryCount);
                foreach (['noNextAction', 'noActivities'] as $activity) {
                    $filtered = $this->summary(['activity' => $activity, 'groupBy' => 'none']);
                    self::assertSame(['count' => 2, 'amount' => 400.0], $filtered['groups']->all);
                }
            }
            self::assertSame(['count' => 4, 'amount' => 1398.0], $this->summary(['groupBy' => 'none'])['groups']->all);
        }
    }

    public function testClosedOpportunitiesWithPendingNextActionsKeepTheirActivityBuckets(): void
    {
        $this->pdo->exec("INSERT INTO task (id, parent_id, parent_type, date_end_date, readable) VALUES
            ('selected', 'a', 'Opportunity', '2999-01-01', 1)");
        $this->pdo->exec("UPDATE opportunity SET next_action_id = 'selected', next_action_type = 'Task' WHERE id = 'a'");
        foreach (['Won', 'Lost'] as $status) {
            $this->pdo->prepare('UPDATE opportunity SET status = ? WHERE id = ?')->execute([$status, 'a']);
            $summary = OpportunityActivitySummary::summarize([
                ['id' => 'selected', 'entityType' => 'Task', 'parentId' => 'a', 'dateEndDate' => '2999-01-01'],
            ], new DateTimeImmutable('now'), true, [
                ['id' => 'a', 'status' => $status, 'nextActionId' => 'selected', 'nextActionType' => 'Task'],
            ]);
            self::assertSame(['a'], $summary['upcoming']['opportunityIds']);
            self::assertSame([], $summary['noNextAction']['opportunityIds']);
            foreach ([$this->mysql, $this->postgres] as $this->composer) {
                $groups = $this->summary(['groupBy' => 'activity'])['groups'];
                self::assertSame(['count' => 1, 'amount' => 499.0], $groups->{'activity:upcoming'});
                self::assertSame(['count' => 3, 'amount' => 899.0], $groups->{'activity:noNextAction'});
            }
        }
    }

    public function testSelectedStepAloneDeterminesOneBucketAndAmountPerOpportunity(): void
    {
        $this->pdo->exec("INSERT INTO task (id, parent_id, parent_type, date_end_date, readable) VALUES
            ('future', 'a', 'Opportunity', '2999-01-01', 1),
            ('overdue-a', 'a', 'Opportunity', '2000-01-01', 1),
            ('undated', 'b', 'Opportunity', NULL, 1),
            ('overdue-b', 'b', 'Opportunity', '2000-01-01', 1),
            ('overdue-c', 'c', 'Opportunity', '2000-01-01', 1)");
        // IDs are only unique within an activity type; this Call is not selected.
        $this->pdo->exec("INSERT INTO call (id, parent_id, parent_type, date_end, readable) VALUES
            ('future', 'a', 'Opportunity', '2000-01-01 12:00:00', 1)");
        $this->pdo->exec("UPDATE opportunity SET next_action_id = 'future', next_action_type = 'Task' WHERE id = 'a'");
        $this->pdo->exec("UPDATE opportunity SET next_action_id = 'undated', next_action_type = 'Task' WHERE id = 'b'");
        $activities = $this->pdo->query("SELECT id, 'Task' AS entityType, parent_id AS parentId, date_end AS dateEnd, date_end_date AS dateEndDate FROM task
            UNION ALL SELECT id, 'Call', parent_id, date_end, NULL FROM call")->fetchAll(PDO::FETCH_ASSOC);
        $opportunities = [
            ['id' => 'a', 'nextActionId' => 'future', 'nextActionType' => 'Task'],
            ['id' => 'b', 'nextActionId' => 'undated', 'nextActionType' => 'Task'],
            ['id' => 'c'],
            ['id' => 'null'],
        ];
        $summary = OpportunityActivitySummary::summarize($activities, new DateTimeImmutable('now'), true, $opportunities);
        self::assertSame(['a'], $summary['upcoming']['opportunityIds']);
        self::assertSame(['b'], $summary['noDate']['opportunityIds']);
        self::assertSame(['c', 'null'], $summary['noNextAction']['opportunityIds']);
        self::assertArrayNotHasKey('noActivities', $summary);
        $ids = array_merge(...array_column($summary, 'opportunityIds'));
        self::assertCount(4, $ids);
        self::assertEqualsCanonicalizing(['a', 'b', 'c', 'null'], $ids);
        $counts = OpportunityActivitySummary::summarize($activities, new DateTimeImmutable('now'), false, $opportunities);
        foreach ($summary as $key => $group) {
            self::assertSame(['count' => $group['count']], $counts[$key]);
        }

        foreach ([$this->mysql, $this->postgres] as $this->composer) {
            $this->queryCount = 0;
            $groups = (array) $this->summary(['groupBy' => 'activity'])['groups'];
            self::assertCount(3, $groups);
            self::assertSame(['count' => 1, 'amount' => 499.0], $groups['activity:upcoming']);
            self::assertSame(['count' => 1, 'amount' => 499.0], $groups['activity:noDate']);
            self::assertSame(['count' => 2, 'amount' => 400.0], $groups['activity:noNextAction']);
            self::assertSame(4, array_sum(array_column($groups, 'count')));
            self::assertSame(1398.0, array_sum(array_column($groups, 'amount')));
            self::assertSame(1, $this->queryCount);
            foreach ($summary as $key => $group) {
                $filtered = $this->summary(['activity' => $key, 'groupBy' => 'activity'])['groups'];
                $expected = $group['count'] ? ['activity:' . $key => $groups['activity:' . $key]] : [];
                self::assertSame($expected, (array) $filtered);
            }
        }
    }

    public function testOnlyReadablePendingNextStepsOfTheCorrectTypeAndParentCount(): void
    {
        $this->filter = ['id' => 'a'];
        $this->pdo->exec("INSERT INTO task (id, parent_id, parent_type, date_end_date, pending, readable, deleted) VALUES
            ('task', 'a', 'Opportunity', '2999-01-01', 1, 1, 0),
            ('completed', 'a', 'Opportunity', '2000-01-01', 0, 1, 0),
            ('hidden', 'a', 'Opportunity', '2000-01-01', 1, 0, 0),
            ('deleted', 'a', 'Opportunity', '2000-01-01', 1, 1, 1),
            ('moved', 'b', 'Opportunity', '2999-01-01', 1, 1, 0)");
        $this->pdo->exec("INSERT INTO meeting (id, parent_id, parent_type, date_end_date, readable) VALUES
            ('meeting', 'a', 'Opportunity', '2999-01-01', 1)");
        $this->pdo->exec("INSERT INTO call (id, parent_id, parent_type, date_end, readable) VALUES
            ('call', 'a', 'Opportunity', '2999-01-01 12:00:00', 1)");
        $cases = [
            ['completed', 'Task', 'noNextAction'], ['hidden', 'Task', 'noNextAction'],
            ['deleted', 'Task', 'noNextAction'], ['missing', 'Task', 'noNextAction'],
            ['moved', 'Task', 'noNextAction'], ['task', 'Call', 'noNextAction'],
            ['task', null, 'noNextAction'], [null, 'Task', 'noNextAction'],
            ['task', 'Task', 'upcoming'], ['meeting', 'Meeting', 'upcoming'], ['call', 'Call', 'upcoming'],
        ];
        $pendingRows = [
            ['id' => 'task', 'entityType' => 'Task', 'parentId' => 'a', 'dateEndDate' => '2999-01-01'],
            ['id' => 'meeting', 'entityType' => 'Meeting', 'parentId' => 'a', 'dateEndDate' => '2999-01-01'],
            ['id' => 'call', 'entityType' => 'Call', 'parentId' => 'a', 'dateEnd' => '2999-01-01 12:00:00'],
        ];
        foreach ($cases as [$id, $type, $expected]) {
            $this->pdo->prepare('UPDATE opportunity SET next_action_id = ?, next_action_type = ? WHERE id = ?')
                ->execute([$id, $type, 'a']);
            $summary = OpportunityActivitySummary::summarize($pendingRows, new DateTimeImmutable('now'), true, [
                ['id' => 'a', 'nextActionId' => $id, 'nextActionType' => $type],
            ]);
            self::assertSame(['a'], $summary[$expected]['opportunityIds']);
            foreach ([$this->mysql, $this->postgres] as $this->composer) {
                $this->queryCount = 0;
                $groups = $this->summary(['groupBy' => 'activity'])['groups'];
                self::assertSame(['activity:' . $expected => ['count' => 1, 'amount' => 499.0]], (array) $groups);
                self::assertSame(1, $this->queryCount);
            }
        }
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
