<?php

namespace Espo\Modules\Chatwoot\Reports;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Select\Where\Item;
use Espo\Core\Utils\Language;
use Espo\Entities\User;
use Espo\Modules\Advanced\Reports\GridReport;
use Espo\Modules\Advanced\Tools\Report\GridType\Result;
use Espo\Modules\Advanced\Tools\Report\ListType\Result as ListResult;
use Espo\Modules\Advanced\Tools\Report\ListType\SubReportParams;
use Espo\Modules\Chatwoot\Tools\Billing\DayExpression;
use Espo\Modules\Chatwoot\Tools\Usage\Metrics;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\SelectBuilder;
use PDO;

/** One ACL-filtered cohort for counts, weighted rates, totals and drill-down. */
class ModelUsageTotals implements GridReport
{
    private const ENTITY = 'ChatwootAiAgentRun';

    public function __construct(
        private EntityManager $entityManager,
        private SelectBuilderFactory $selectBuilderFactory,
        private Language $language,
        private DayExpression $dayExpression,
    ) {}

    protected function groupColumn(): ?string
    {
        return null;
    }

    private function groupExpression(): ?Expr
    {
        return match ($this->groupColumn()) {
            'DAY:runAt' => Expr::create($this->dayExpression->build()),
            'model' => Expr::column('model'),
            default => null,
        };
    }

    private function cohort(SearchParams $params, ?User $user): SelectBuilder
    {
        $scope = $this->selectBuilderFactory->create()->from(self::ENTITY)
            ->withStrictAccessControl()->withSearchParams(
                $params->withSelect(['id'])->withOrderBy(null)->withOffset(null)->withMaxSize(null)
            );
        if ($user) {
            $scope->forUser($user);
        }
        $ids = $scope->buildQueryBuilder()->select(['id'])->order([])->limit(null, null)->build();
        // Relationship filters/ACL joins must not multiply either usage or requests.
        return SelectBuilder::create()->from(self::ENTITY)->where(['id=s' => $ids]);
    }

    public function run(?Item $where, ?User $user): Result
    {
        $params = SearchParams::create();
        if ($where) {
            $params = $params->withWhere($where);
        }
        $query = $this->cohort($params, $user)->select([])
            ->select(Expr::create('COUNT:id'), 'runs')
            ->select(Expr::create('SUM:IF:(EQUAL:(usageMetricsVersion, 1), 1, 0)'), 'meteredRuns');
        foreach (Metrics::SUM_FIELDS as $field) {
            $query->select(Expr::create('SUM:' . $field), $field);
        }
        foreach (['mainMaxInputTokens', 'searchMaxInputTokens'] as $field) {
            $query->select(Expr::create('MAX:' . $field), $field);
        }
        // Execute the ungrouped aggregate as well: grand-total ratios are weighted.
        $total = $this->entityManager->getQueryExecutor()->execute($query->build())->fetch(PDO::FETCH_ASSOC);
        $sums = Metrics::fromAggregates($total ?: []);
        $group = $this->groupColumn();
        $keys = [];
        $data = [];
        $labels = [];
        if ($group !== null) {
            $expr = $this->groupExpression();
            $query->select($expr, 'bucket')->group($expr)->order($expr)->limit(0, 5001);
            $rows = $this->entityManager->getQueryExecutor()->execute($query->build())->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) > 5000) {
                throw new BadRequest('Reduce the usage report date range.');
            }
            foreach ($rows as $row) {
                $key = (string) ($row['bucket'] ?? '-');
                $keys[] = $key;
                $labels[$key] = $key === '-' ? '—' : $key;
                $data[$key] = (object) Metrics::fromAggregates($row);
            }
        }
        $columns = array_keys($sums);
        $types = [];
        $names = [];
        $decimals = [];
        foreach ($columns as $column) {
            $decimal = str_ends_with($column, 'Pct') || str_contains($column, 'Avg');
            $types[$column] = $decimal ? 'float' : 'int';
            $decimals[$column] = $decimal ? 2 : 0;
            $names[$column] = $this->language->translateLabel($column, 'usageColumnLabels', self::ENTITY);
        }
        $result = new Result(
            entityType: self::ENTITY,
            groupByList: $group ? [$group] : [],
            columnList: $columns,
            numericColumnList: $columns,
            summaryColumnList: $columns,
            aggregatedColumnList: $columns,
            sums: (object) $sums,
            groupValueMap: $group ? [$group => $labels] : [],
            columnNameMap: $names,
            columnTypeMap: $types,
            grouping: $group ? [$keys] : [],
            reportData: (object) $data,
            columnDecimalPlacesMap: (object) $decimals,
        );
        $result->setGroup1NonSummaryColumnList([]);
        return $result;
    }

    public function runSubReport(SearchParams $searchParams, SubReportParams $subReportParams, ?User $user): ListResult
    {
        $query = $this->cohort($searchParams, $user);
        $expr = $this->groupExpression();
        if ($expr !== null) {
            $value = (string) $subReportParams->getGroupValue();
            $query->where($value === '-' ? Expr::isNull($expr) : Expr::equal($expr, $value));
        }
        $count = $this->entityManager->getRDBRepository(self::ENTITY)->clone($query->build())->count();
        $query->select($searchParams->getSelect() ?? ['*'])
            ->order($searchParams->getOrderBy() ?? 'runAt', $searchParams->getOrder() ?? 'DESC')
            ->limit($searchParams->getOffset() ?? 0, $searchParams->getMaxSize() ?? 50);
        return new ListResult(
            $this->entityManager->getRDBRepository(self::ENTITY)->clone($query->build())->find(), $count
        );
    }
}
