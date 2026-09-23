<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Reports;

use Espo\Core\Select\SearchParams;
use Espo\Core\Select\SelectBuilder as AccessBuilder;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Select\Where\Item;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Language;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Reports\ModelUsageTotals;
use Espo\Modules\Chatwoot\Reports\ModelUsageByModel;
use Espo\Modules\Chatwoot\Reports\ModelUsagePerDay;
use Espo\Modules\Chatwoot\Tools\Billing\DayExpression;
use Espo\Modules\Chatwoot\Tools\Usage\Metrics;
use Espo\Modules\Advanced\Tools\Report\ListType\SubReportParams;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityFactory;
use Espo\ORM\EntityManager;
use Espo\ORM\Executor\QueryExecutor;
use Espo\ORM\Metadata;
use Espo\ORM\MetadataDataProvider;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\QueryComposer\MysqlQueryComposer;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PDO;
use PHPUnit\Framework\TestCase;

class ModelUsageTest extends TestCase
{
    private PDO $pdo;
    private EntityManager $em;
    private SelectBuilderFactory $factory;
    private Language $language;
    private DayExpression $day;
    private User $user;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->sqliteCreateFunction('DATE_FORMAT', static fn ($date, $format) => substr($date, 0, 10), 2);
        $this->pdo->sqliteCreateFunction('IF', static fn ($condition, $yes, $no) => $condition ? $yes : $no, 3);
        $fields = array_unique(array_merge(Metrics::SUM_FIELDS, [
            'id', 'runAt', 'model', 'tenantId', 'readable', 'deleted', 'usageMetricsVersion',
            'mainMaxInputTokens', 'searchMaxInputTokens',
        ]));
        $defs = [];
        $columns = [];
        foreach ($fields as $field) {
            $text = in_array($field, ['id', 'runAt', 'model', 'tenantId']);
            $defs['ChatwootAiAgentRun']['attributes'][$field] = ['type' => $text ? 'varchar' : 'int'];
            $name = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $field));
            $columns[] = "$name " . ($text ? 'TEXT' : 'INTEGER');
        }
        $defs['Visibility']['attributes'] = ['id' => ['type' => 'varchar'], 'runId' => ['type' => 'varchar']];
        $this->pdo->exec('CREATE TABLE chatwoot_ai_agent_run (' . implode(', ', $columns) . ')');
        $this->pdo->exec('CREATE TABLE visibility (id TEXT, run_id TEXT)');
        $metadata = $this->createMock(MetadataDataProvider::class);
        $metadata->method('get')->willReturn($defs);
        $entities = $this->createMock(EntityFactory::class);
        $entities->method('create')->willReturnCallback(fn ($type) => new BaseEntity($type, $defs[$type]));
        $composer = new MysqlQueryComposer($this->pdo, $entities, new Metadata($metadata));
        $executor = $this->createMock(QueryExecutor::class);
        $executor->method('execute')->willReturnCallback(fn ($query) => $this->pdo->query($composer->composeSelect($query)));
        $this->em = $this->createMock(EntityManager::class);
        $this->em->method('getQueryExecutor')->willReturn($executor);
        $repo = $this->createMock(RDBRepository::class);
        $this->em->method('getRDBRepository')->with('ChatwootAiAgentRun')->willReturn($repo);
        $repo->method('clone')->willReturnCallback(function ($query) use ($composer, $defs) {
            $selection = $this->createMock(RDBSelectBuilder::class);
            $selection->method('count')->willReturnCallback(fn () => (int) $this->pdo->query(
                'SELECT COUNT(*) FROM (' . $composer->composeSelect($query) . ') counted'
            )->fetchColumn());
            $selection->method('find')->willReturnCallback(function () use ($query, $composer, $defs) {
                $rows = $this->pdo->query($composer->composeSelect($query))->fetchAll(PDO::FETCH_ASSOC);
                return new EntityCollection(array_map(function ($row) use ($defs) {
                    $entity = new BaseEntity('ChatwootAiAgentRun', $defs['ChatwootAiAgentRun']);
                    $entity->set($row);
                    return $entity;
                }, $rows));
            });
            return $selection;
        });
        $this->user = $this->createMock(User::class);
        $this->factory = $this->createMock(SelectBuilderFactory::class);
        $this->factory->method('create')->willReturnCallback(function () {
            $access = $this->createMock(AccessBuilder::class);
            $access->expects(self::once())->method('withStrictAccessControl')->willReturnSelf();
            $access->expects(self::once())->method('forUser')->with($this->user)->willReturnSelf();
            $access->method('from')->with('ChatwootAiAgentRun')->willReturnSelf();
            $params = SearchParams::create();
            $access->method('withSearchParams')->willReturnCallback(function ($value) use (&$params, $access) {
                $params = $value;
                return $access;
            });
            $access->method('buildQueryBuilder')->willReturnCallback(function () use (&$params) {
                $query = SelectBuilder::create()->from('ChatwootAiAgentRun', 'chatwootAiAgentRun')
                    ->where(['readable' => 1])
                    ->join('Visibility', 'visibility', ['visibility.runId:' => 'chatwootAiAgentRun.id']);
                if ($params->getWhere()) {
                    $this->applyWhere($query, $params->getWhere());
                }
                return $query;
            });
            return $access;
        });
        $this->language = $this->createMock(Language::class);
        $this->language->method('translateLabel')->willReturnArgument(0);
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn('UTC');
        $this->day = new DayExpression($config);

        $this->insert('a', ['model' => 'A', 'inputTokens' => 100, 'cachedInputTokens' => 100,
            'usageMetricsVersion' => 1, 'modelRequestCount' => 1, 'mainRequestCount' => 1,
            'mainUsageRequestCount' => 1, 'mainCacheHitRequestCount' => 1,
            'mainInputTokens' => 100, 'mainCachedInputTokens' => 100, 'mainMaxInputTokens' => 100]);
        $this->insert('b', ['model' => 'B', 'runAt' => '2026-09-02 12:00:00', 'inputTokens' => 1100, 'cachedInputTokens' => 50,
            'usageMetricsVersion' => 1, 'modelRequestCount' => 11, 'mainRequestCount' => 9, 'searchRequestCount' => 2,
            'mainUsageRequestCount' => 9, 'searchUsageRequestCount' => 2, 'searchCacheHitRequestCount' => 1,
            'mainInputTokens' => 900, 'searchInputTokens' => 200, 'searchCachedInputTokens' => 50,
            'mainMaxInputTokens' => 200, 'searchMaxInputTokens' => 120]);
        $this->insert('legacy', ['model' => 'old', 'inputTokens' => 1000, 'cachedInputTokens' => 500]);
        $this->insert('hidden', ['readable' => 0, 'inputTokens' => 999999]);
        $this->insert('deleted', ['deleted' => 1, 'inputTokens' => 999999]);
        // A team/link join duplicates a run. The semi-join must keep all counters correct.
        $this->pdo->exec("INSERT INTO visibility VALUES ('duplicate', 'a')");
    }

    private function insert(string $id, array $values): void
    {
        $values += ['id' => $id, 'runAt' => '2026-09-01 12:00:00', 'model' => 'A', 'tenantId' => 'tenant', 'readable' => 1, 'deleted' => 0];
        $columns = array_map(fn ($f) => strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $f)), array_keys($values));
        $this->pdo->prepare('INSERT INTO chatwoot_ai_agent_run (' . implode(',', $columns) . ') VALUES ('
            . implode(',', array_fill(0, count($values), '?')) . ')')->execute(array_values($values));
        $this->pdo->prepare('INSERT INTO visibility VALUES (?, ?)')->execute([$id, $id]);
    }

    private function applyWhere(SelectBuilder $query, Item $where): void
    {
        if ($where->getType() === 'and') {
            foreach ($where->getItemList() as $item) {
                $this->applyWhere($query, $item);
            }
            return;
        }
        $query->where([$where->getAttribute() => $where->getValue()]);
    }

    public function testWeightedTotalsRespectAclAndDoNotMultiplyRelationshipJoins(): void
    {
        $report = new ModelUsageByModel($this->em, $this->factory, $this->language, $this->day);
        $result = $report->run(null, $this->user);
        $sums = $result->getSums();
        self::assertSame(3, $sums->runs);
        self::assertSame(2, $sums->meteredRuns);
        self::assertSame(66.67, $sums->telemetryCoveragePct);
        self::assertSame(12, $sums->modelRequestCount);
        self::assertSame(16.67, $sums->requestCacheHitPct);
        self::assertSame(29.55, $sums->tokenCacheHitPct);
        self::assertSame(10.0, $sums->mainTokenCacheHitPct);
        self::assertSame(25.0, $sums->searchTokenCacheHitPct);
        self::assertSame(100.0, $sums->mainAvgInputTokens);
        self::assertSame(200, $sums->mainMaxInputTokens);
        $data = $result->getReportData();
        self::assertSame(100.0, $data->A->requestCacheHitPct);
        self::assertSame(9.09, $data->B->requestCacheHitPct);
        self::assertNull($data->old->requestCacheHitPct);
        self::assertNull($data->old->modelRequestCount);
    }

    public function testLegacyCohortIsUnknownNotZeroAndRuntimeFilterIsApplied(): void
    {
        $where = Item::fromRaw(['type' => 'equals', 'attribute' => 'model', 'value' => 'old']);
        $report = new ModelUsageTotals($this->em, $this->factory, $this->language, $this->day);
        $sums = $report->run($where, $this->user)->getSums();
        self::assertSame(1, $sums->runs);
        self::assertSame(0.0, $sums->telemetryCoveragePct);
        self::assertSame(50.0, $sums->tokenCacheHitPct);
        self::assertNull($sums->mainRequestCount);
        self::assertNull($sums->mainAvgInputTokens);
    }

    public function testDailyBucketsUseTheSameCohortAsTheGrandTotal(): void
    {
        $report = new ModelUsagePerDay($this->em, $this->factory, $this->language, $this->day);
        $result = $report->run(null, $this->user);
        $data = $result->getReportData();
        self::assertSame(2, $data->{'2026-09-01'}->runs);
        self::assertSame(1, $data->{'2026-09-02'}->runs);
        self::assertSame(3, $result->getSums()->runs);
    }

    public function testMissingProviderUsageDoesNotDiluteHitRateAndEmptyDenominatorsStayNull(): void
    {
        $metrics = Metrics::fromAggregates(['runs' => 1, 'meteredRuns' => 1, 'modelRequestCount' => 3,
            'mainRequestCount' => 3, 'mainUsageRequestCount' => 1, 'mainCacheHitRequestCount' => 1,
            'mainInputTokens' => 100, 'mainCachedInputTokens' => 80]);
        self::assertSame(2, $metrics['requestsWithoutUsage']);
        self::assertSame(100.0, $metrics['requestCacheHitPct']);
        self::assertSame(80.0, $metrics['mainTokenCacheHitPct']);
        self::assertNull($metrics['searchAvgInputTokens']);
        self::assertNull(Metrics::fromAggregates([])['tokenCacheHitPct']);
    }

    public function testDrillDownRetainsAclRuntimeFilterAndUniquePaginatedRuns(): void
    {
        $report = new ModelUsagePerDay($this->em, $this->factory, $this->language, $this->day);
        $params = SearchParams::create()->withSelect(['id'])->withMaxSize(1)->withOrderBy('id')
            ->withOrder('ASC')->withWhere(Item::fromRaw(['type' => 'equals', 'attribute' => 'tenantId', 'value' => 'tenant']));
        $result = $report->runSubReport($params, new SubReportParams(0, '2026-09-01'), $this->user);
        self::assertSame(2, $result->getTotal());
        self::assertSame(['a'], array_map(fn ($e) => $e->getId(), iterator_to_array($result->getCollection())));
    }
}
