<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Reports;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Core\Utils\Language;
use Espo\Entities\User;
use Espo\Modules\Advanced\Reports\GridReport;
use Espo\Modules\Advanced\Tools\Report\GridType\Result;
use Espo\Modules\Advanced\Tools\Report\ListType\Result as ListResult;
use Espo\Modules\Advanced\Tools\Report\ListType\SubReportParams;
use Espo\Modules\Chatwoot\Tools\Billing\BillingCurrency;
use Espo\Modules\Chatwoot\Tools\Billing\ConversationDayGrain;
use Espo\Modules\Chatwoot\Tools\Billing\ConversationDayGrainFetcher;
use Espo\Modules\Chatwoot\Tools\Billing\PlanIncludedApplier;
use Espo\Modules\Chatwoot\Tools\Billing\Pricing;
use Espo\Modules\Chatwoot\Tools\Billing\RateCard;
use Espo\Modules\Chatwoot\Tools\Billing\TenantRateBook;
use Espo\Modules\Chatwoot\Tools\Billing\TenantRateLookup;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Order;
use RuntimeException;
use stdClass;

/**
 * Shared GridReport body for conversation-day AI billing aggregates.
 *
 * Concrete subclasses only declare pricing model + whether to split by tenant.
 *
 * Chart / primary numeric column is always `amount` (system default currency).
 * Secondary columns document the composition so Ops and customers can reconcile.
 * Per-Tenant dated AI Billing rate periods ({@see TenantAiBillingRate})
 * override platform defaults; Tenant flat fields remain a legacy fallback.
 */
abstract class AbstractBillingGrid implements GridReport
{
    protected const ENTITY_TYPE = 'ChatwootAiAgentRun';
    protected const CONVERSATION_ENTITY_TYPE = 'ChatwootConversation';
    protected const TENANT_ENTITY_TYPE = 'Tenant';

    protected const GROUP_DAY = 'DAY:runAt';
    protected const GROUP_TENANT = 'tenant';

    protected const COL_AMOUNT = 'amount';
    protected const COL_AMOUNT_DEAL = 'amountDeal';

    /** @var 'pack199'|'extra049' */
    abstract protected function pricingModel(): string;

    abstract protected function byTenant(): bool;

    public function __construct(
        protected EntityManager $entityManager,
        protected SelectBuilderFactory $selectBuilderFactory,
        protected Language $language,
        protected ConversationDayGrainFetcher $grainFetcher,
        protected TenantRateLookup $tenantRateLookup,
        protected BillingCurrency $billingCurrency,
    ) {}

    public function run(?WhereItem $where, ?User $user): Result
    {
        $grains = $this->grainFetcher->fetch($where, $user);
        $rateBook = $this->tenantRateLookup->forTenants(
            array_map(static fn (ConversationDayGrain $g) => $g->tenantId, $grains)
        );
        $metrics = $this->metricsFromGrains($grains, $rateBook);

        if ($this->byTenant()) {
            return $this->buildByTenantResult($metrics, $user, $rateBook);
        }

        return $this->buildPerDayResult($metrics);
    }

    public function runSubReport(
        SearchParams $searchParams,
        SubReportParams $subReportParams,
        ?User $user
    ): ListResult {
        $groupIndex = $subReportParams->getGroupIndex();
        /** @var string $groupValue */
        $groupValue = (string) $subReportParams->getGroupValue();

        if (!$this->byTenant() || $groupIndex === 0) {
            $day = $groupValue;
            $tenantId = null;
        } else {
            $tenantId = $groupValue;
            $day = $subReportParams->hasGroupValue2()
                ? (string) $subReportParams->getGroupValue2()
                : null;
        }

        $conversationIds = $this->grainFetcher->fetchConversationIdsForBucket(
            $searchParams,
            $user,
            $day,
            $tenantId,
        );

        if (empty($conversationIds)) {
            return new ListResult(
                $this->entityManager->getRDBRepository(self::CONVERSATION_ENTITY_TYPE)
                    ->where(['id' => '__none__'])
                    ->find(),
                0
            );
        }

        $listBuilder = $this->selectBuilderFactory
            ->create()
            ->from(self::CONVERSATION_ENTITY_TYPE)
            ->withStrictAccessControl();

        if ($user) {
            $listBuilder->forUser($user);
        }

        try {
            $listQueryBuilder = $listBuilder->buildQueryBuilder();
        } catch (BadRequest|Forbidden $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        $listQueryBuilder
            ->where(['id' => $conversationIds])
            ->order('chatwootCreatedAt', Order::DESC);

        $query = $listQueryBuilder->build();
        $repository = $this->entityManager->getRDBRepository(self::CONVERSATION_ENTITY_TYPE);

        return new ListResult(
            $repository->clone($query)->find(),
            $repository->clone($query)->count()
        );
    }

    /**
     * @param list<ConversationDayGrain> $grains
     * @return list<array{dayBucket: string, tenantId: string, metrics: array<string, int|float>}>
     */
    private function metricsFromGrains(array $grains, TenantRateBook $rateBook): array
    {
        $rows = [];
        $keepDeal = $this->byTenant();

        foreach ($grains as $grain) {
            $rates = $rateBook->get($grain->tenantId, $grain->dayBucket);
            $metrics = $this->priceGrain($grain, $rates);

            $rows[] = [
                'dayBucket' => $grain->dayBucket,
                'tenantId' => $grain->tenantId ?? ConversationDayGrainFetcher::noTenantKey(),
                'rates' => $rates,
                'metrics' => $metrics,
            ];
        }

        $applied = PlanIncludedApplier::apply($rows, $this->pricingModel());
        $out = [];

        foreach ($applied as $row) {
            $metrics = $row['metrics'];
            $deal = (float) ($metrics['__dealRaw'] ?? $metrics[self::COL_AMOUNT_DEAL] ?? 0.0);
            $currency = (string) ($metrics['__currency'] ?? RateCard::DEFAULT_CURRENCY);
            $metrics[self::COL_AMOUNT] = $this->billingCurrency->toDefault($deal, $currency);
            $metrics[self::COL_AMOUNT_DEAL] = $deal;
            unset($metrics['__dealRaw'], $metrics['__currency']);

            if (!$keepDeal) {
                unset($metrics[self::COL_AMOUNT_DEAL]);
            }

            $out[] = [
                'dayBucket' => $row['dayBucket'],
                'tenantId' => $row['tenantId'],
                'metrics' => $metrics,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, int|float>
     */
    private function priceGrain(ConversationDayGrain $grain, RateCard $rates): array
    {
        if ($this->pricingModel() === 'pack199') {
            $priced = $grain->pricePack199($rates);
            $deal = (float) $priced['amount'];

            return [
                self::COL_AMOUNT => $this->billingCurrency->toDefault($deal, $rates->currency),
                self::COL_AMOUNT_DEAL => $deal,
                'packs' => $priced['packs'],
                'turns' => $priced['turns'],
                'conversationDays' => $priced['packs'] > 0 ? 1 : 0,
            ];
        }

        $priced = $grain->priceExtra049($rates);
        $deal = (float) $priced['amount'];

        return [
            self::COL_AMOUNT => $this->billingCurrency->toDefault($deal, $rates->currency),
            self::COL_AMOUNT_DEAL => $deal,
            'bases' => $priced['bases'],
            'turnOverages' => $priced['turnOverages'],
            'kindExtras' => $priced['kindExtras'],
            'extras' => $priced['extras'],
            'customerTurns' => $priced['customerTurns'],
            'turns' => $grain->totalTurns(),
            'conversationDays' => $priced['bases'],
        ];
    }

    /**
     * @return list<string>
     */
    private function columnList(): array
    {
        // `amount` = summable system-default currency (Administration → Currency).
        // `amountDeal` = contract currency; only meaningful per-tenant (single currency).
        if ($this->pricingModel() === 'pack199') {
            $cols = [
                self::COL_AMOUNT,
                'packs',
                PlanIncludedApplier::COL_PLAN_INCLUDED_USED,
                PlanIncludedApplier::COL_BILLABLE_USAGE,
                'turns',
                'conversationDays',
            ];
        } else {
            $cols = [
                self::COL_AMOUNT,
                'bases',
                PlanIncludedApplier::COL_PLAN_INCLUDED_USED,
                PlanIncludedApplier::COL_BILLABLE_USAGE,
                'turnOverages',
                'kindExtras',
                'extras',
                'customerTurns',
                'turns',
                'conversationDays',
            ];
        }

        if ($this->byTenant()) {
            array_splice($cols, 1, 0, [self::COL_AMOUNT_DEAL]);
        }

        return $cols;
    }

    /**
     * @return array<string, string>
     */
    private function columnTypeMap(): array
    {
        $map = [];
        $money = [self::COL_AMOUNT => true, self::COL_AMOUNT_DEAL => true];

        foreach ($this->columnList() as $col) {
            $map[$col] = isset($money[$col]) ? 'float' : 'int';
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private function columnNameMap(): array
    {
        $map = [];
        $baseCode = $this->billingCurrency->defaultCode();

        foreach ($this->columnList() as $col) {
            $label = $this->language->translateLabel(
                $col,
                'columnLabels',
                'ChatwootAiAgentRun'
            );

            if ($col === self::COL_AMOUNT) {
                $label = str_replace('{currency}', $baseCode, $label);
            }

            $map[$col] = $label;
        }

        return $map;
    }

    /**
     * @param list<array{dayBucket: string, tenantId: string, metrics: array<string, int|float>}> $rows
     */
    private function buildPerDayResult(array $rows): Result
    {
        $columns = $this->columnList();
        $reportData = [];
        $dayOrder = [];
        $grand = $this->emptyMetrics();

        foreach ($rows as $row) {
            $dayKey = $row['dayBucket'] !== '' ? $row['dayBucket'] : '-';
            $dayOrder[$dayKey] = true;

            if (!isset($reportData[$dayKey])) {
                $reportData[$dayKey] = $this->emptyMetrics();
            }

            $reportData[$dayKey] = $this->addMetrics($reportData[$dayKey], $row['metrics']);
            $grand = $this->addMetrics($grand, $row['metrics']);
        }

        foreach ($reportData as $dayKey => $metrics) {
            $reportData[$dayKey] = $this->finalizeMetrics($metrics);
        }

        $grand = $this->finalizeMetrics($grand);
        $grouping = [$this->orderDays($dayOrder)];

        $result = new Result(
            self::ENTITY_TYPE,
            [self::GROUP_DAY],
            $columns,
            $columns,
            $columns,
            [],
            [],
            $columns,
            null,
            null,
            (object) $grand,
            [self::GROUP_DAY => $this->buildDayValueMap($grouping[0])],
            $this->columnNameMap(),
            $this->columnTypeMap(),
            null,
            $grouping,
            $this->arrayToObjectTreeDepth1($reportData),
            null,
            null,
            null,
        );

        $result->setGroup1NonSummaryColumnList([]);

        return $result;
    }

    /**
     * @param list<array{dayBucket: string, tenantId: string, metrics: array<string, int|float>}> $rows
     */
    private function buildByTenantResult(array $rows, ?User $user, TenantRateBook $rateBook): Result
    {
        $columns = $this->columnList();
        $reportData = [];
        $group1Sums = [];
        $dayOrder = [];
        $tenantOrder = [];
        $grand = $this->emptyMetrics();

        foreach ($rows as $row) {
            $dayKey = $row['dayBucket'] !== '' ? $row['dayBucket'] : '-';
            $tenantKey = $row['tenantId'] !== ''
                ? $row['tenantId']
                : ConversationDayGrainFetcher::noTenantKey();

            $dayOrder[$dayKey] = true;
            $tenantOrder[$tenantKey] = true;

            if (!isset($reportData[$dayKey][$tenantKey])) {
                $reportData[$dayKey][$tenantKey] = $this->emptyMetrics();
            }

            if (!isset($group1Sums[$dayKey])) {
                $group1Sums[$dayKey] = $this->emptyMetrics();
            }

            $reportData[$dayKey][$tenantKey] = $this->addMetrics(
                $reportData[$dayKey][$tenantKey],
                $row['metrics']
            );
            // Day-level / grand rollups: only sum base-currency `amount`.
            // Deal-currency amountDeal is not mixed across tenants.
            $group1Sums[$dayKey] = $this->addMetrics(
                $group1Sums[$dayKey],
                $this->baseOnlyMetrics($row['metrics'])
            );
            $grand = $this->addMetrics($grand, $this->baseOnlyMetrics($row['metrics']));
        }

        foreach ($reportData as $dayKey => $tenants) {
            foreach ($tenants as $tenantKey => $metrics) {
                $reportData[$dayKey][$tenantKey] = $this->finalizeMetrics($metrics);
            }

            $group1Sums[$dayKey] = $this->finalizeMetrics($group1Sums[$dayKey]);
        }

        $grand = $this->finalizeMetrics($grand);

        $tenantNames = $this->fetchTenantNames(array_keys($tenantOrder), $user);
        $grouping = [
            $this->orderDays($dayOrder),
            $this->orderTenants($tenantOrder, $tenantNames),
        ];

        $result = new Result(
            self::ENTITY_TYPE,
            [self::GROUP_DAY, self::GROUP_TENANT],
            $columns,
            $columns,
            $columns,
            [],
            [],
            $columns,
            null,
            null,
            (object) $grand,
            [
                self::GROUP_DAY => $this->buildDayValueMap($grouping[0]),
                self::GROUP_TENANT => $this->buildTenantValueMap(
                    $grouping[1],
                    $tenantNames,
                    $rateBook
                ),
            ],
            $this->columnNameMap(),
            $this->columnTypeMap(),
            null,
            $grouping,
            $this->arrayToObjectTreeDepth2($reportData),
            null,
            null,
            null,
        );

        $result->setGroup1Sums($this->arrayToObjectTreeDepth1($group1Sums));
        $result->setGroup1NonSummaryColumnList([]);
        // amountDeal is per-tenant only — keep it off the day rollup row.
        $result->setGroup2NonSummaryColumnList([]);

        return $result;
    }

    /**
     * @return array<string, int|float>
     */
    private function emptyMetrics(): array
    {
        $empty = [];
        $money = [self::COL_AMOUNT => true, self::COL_AMOUNT_DEAL => true];

        foreach ($this->columnList() as $col) {
            $empty[$col] = isset($money[$col]) ? 0.0 : 0;
        }

        return $empty;
    }

    /**
     * @param array<string, int|float> $a
     * @param array<string, int|float> $b
     * @return array<string, int|float>
     */
    private function addMetrics(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            $a[$k] = ($a[$k] ?? 0) + $v;
        }

        return $a;
    }

    /**
     * Strip deal-currency for cross-tenant rollups (only amount is FX-safe).
     *
     * @param array<string, int|float> $m
     * @return array<string, int|float>
     */
    private function baseOnlyMetrics(array $m): array
    {
        unset($m[self::COL_AMOUNT_DEAL]);

        return $m;
    }

    /**
     * @param array<string, int|float> $m
     * @return array<string, int|float>
     */
    private function finalizeMetrics(array $m): array
    {
        foreach ([self::COL_AMOUNT, self::COL_AMOUNT_DEAL] as $col) {
            if (isset($m[$col])) {
                $m[$col] = Pricing::roundMoney((float) $m[$col]);
            }
        }

        return $m;
    }

    /**
     * @param array<string, true> $dayOrder
     * @return list<string>
     */
    private function orderDays(array $dayOrder): array
    {
        $keys = array_keys($dayOrder);

        usort($keys, function (string $a, string $b): int {
            if ($a === '-') {
                return 1;
            }
            if ($b === '-') {
                return -1;
            }

            return strcmp($a, $b);
        });

        return $keys;
    }

    /**
     * @param array<string, true> $tenantOrder
     * @param array<string, string> $tenantNames
     * @return list<string>
     */
    private function orderTenants(array $tenantOrder, array $tenantNames): array
    {
        $keys = array_keys($tenantOrder);
        $noTenant = ConversationDayGrainFetcher::noTenantKey();

        usort($keys, function (string $a, string $b) use ($tenantNames, $noTenant): int {
            if ($a === $noTenant) {
                return 1;
            }
            if ($b === $noTenant) {
                return -1;
            }

            return strcasecmp(
                $tenantNames[$a] ?? $a,
                $tenantNames[$b] ?? $b
            );
        });

        return $keys;
    }

    /**
     * @param list<string> $dayList
     * @return array<string, string>
     */
    private function buildDayValueMap(array $dayList): array
    {
        $map = [];

        foreach ($dayList as $day) {
            $map[$day] = $day === '-' ? '—' : $day;
        }

        return $map;
    }

    /**
     * @param list<string> $tenantList
     * @param array<string, string> $tenantNames
     * @return array<string, string>
     */
    private function buildTenantValueMap(
        array $tenantList,
        array $tenantNames,
        TenantRateBook $rateBook,
    ): array {
        $map = [];
        $noTenant = ConversationDayGrainFetcher::noTenantKey();
        $fallbackCurrency = $this->billingCurrency->defaultCode();

        foreach ($tenantList as $tenantKey) {
            if ($tenantKey === $noTenant) {
                $map[$tenantKey] = $this->language->translateLabel(
                    'noTenant',
                    'labels',
                    'ChatwootAiAgentRun'
                );

                continue;
            }

            $name = $tenantNames[$tenantKey]
                ?? $this->language->translateLabel('restricted', 'labels', 'ChatwootAiAgentRun');
            // Label uses current (open-ended) deal currency; per-day pricing still
            // resolves historical periods via grain dayBucket.
            $currency = $rateBook->get($tenantKey)->currency
                ?? $fallbackCurrency;
            $map[$tenantKey] = $name . ' · ' . $currency;
        }

        return $map;
    }

    /**
     * @param list<string> $tenantIds
     * @return array<string, string>
     */
    private function fetchTenantNames(array $tenantIds, ?User $user): array
    {
        $noTenant = ConversationDayGrainFetcher::noTenantKey();
        $tenantIds = array_values(array_unique(array_filter(
            $tenantIds,
            fn ($v) => $v !== null && $v !== '' && $v !== $noTenant
        )));

        if (empty($tenantIds)) {
            return [];
        }

        $tenantListBuilder = $this->selectBuilderFactory
            ->create()
            ->from(self::TENANT_ENTITY_TYPE)
            ->withStrictAccessControl();

        if ($user) {
            $tenantListBuilder->forUser($user);
        }

        try {
            $tenantQueryBuilder = $tenantListBuilder->buildQueryBuilder();
        } catch (BadRequest|Forbidden $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        $tenantQueryBuilder->where(['id' => $tenantIds]);

        $rows = $this->entityManager
            ->getQueryExecutor()
            ->execute($tenantQueryBuilder->select(['id', 'name'])->build())
            ->fetchAll(\PDO::FETCH_ASSOC);

        $names = [];

        foreach ($rows as $row) {
            $names[(string) $row['id']] = (string) ($row['name'] ?? '');
        }

        return $names;
    }

    /**
     * @param array<string, array<string, int|float>> $tree
     */
    private function arrayToObjectTreeDepth1(array $tree): stdClass
    {
        $converted = [];

        foreach ($tree as $k => $v) {
            $converted[$k] = (object) $v;
        }

        return (object) $converted;
    }

    /**
     * @param array<string, array<string, array<string, int|float>>> $tree
     */
    private function arrayToObjectTreeDepth2(array $tree): stdClass
    {
        $converted = [];

        foreach ($tree as $k => $v) {
            $inner = [];

            foreach ($v as $k1 => $v1) {
                $inner[$k1] = (object) $v1;
            }

            $converted[$k] = (object) $inner;
        }

        return (object) $converted;
    }
}
