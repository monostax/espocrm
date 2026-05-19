<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Reports;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Language;
use Espo\Entities\User;
use Espo\Modules\Advanced\Reports\GridReport;
use Espo\Modules\Advanced\Tools\Report\GridType\Result;
use Espo\Modules\Advanced\Tools\Report\ListType\Result as ListResult;
use Espo\Modules\Advanced\Tools\Report\ListType\SubReportParams;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Condition as Cond;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\Part\Order;
use DateTime;
use DateTimeZone;
use RuntimeException;
use stdClass;

/**
 * Grid report: distinct ChatwootConversation count per Ambiente (Tenant) per day.
 *
 * Group 1: ChatwootAiAgentRun.tenant     (display: Tenant.name)
 * Group 2: DAY(ChatwootAiAgentRun.runAt) (display: YYYY-MM-DD, server local time)
 * Metric : COUNT(DISTINCT conversationId) — one conversation re-engaged by the
 *          AI multiple times on the same day still counts as one.
 *
 * ACL is enforced two ways:
 *   1. Espo's Report service has already verified the calling user has
 *      `read` on ChatwootAiAgentRun and access to this Report row before
 *      invoking `run()` (see Advanced/Tools/Report/Service.php).
 *   2. Every query this class builds goes through SelectBuilder with
 *      withStrictAccessControl() + forUser(), so team-based row-level ACL
 *      restricts the rows that participate in the COUNT DISTINCT and the
 *      drill-down list.
 *
 * Runtime filter support: `createdAt` (date range) and `tenant` (link IN).
 * The WhereItem the service passes in is layered on top of the SearchParams
 * via withWhereAdded(), so it composes with any default filters defined on
 * the Report row.
 *
 * Drill-down (`runSubReport`): clicking a cell lists the DISTINCT
 * ChatwootConversation entities that had at least one AI run inside the
 * clicked (tenant, day) bucket. Clicking a row total lists conversations
 * across the whole day-range for that tenant.
 */
class ConversationsEngagedByTenantPerDay implements GridReport
{
    private const ENTITY_TYPE = 'ChatwootAiAgentRun';
    private const CONVERSATION_ENTITY_TYPE = 'ChatwootConversation';
    private const TENANT_ENTITY_TYPE = 'Tenant';

    private const COLUMN_KEY = 'COUNT:id';
    private const GROUP_TENANT = 'tenant';
    private const GROUP_DAY = 'DAY:runAt';

    /** Sentinel used when a run has no tenant resolved yet. */
    private const NO_TENANT_KEY = '__no_tenant__';

    /** Cap on group buckets to protect the UI on absurdly wide ranges. */
    private const MAX_GROUPS = 1000;

    public function __construct(
        private EntityManager $entityManager,
        private SelectBuilderFactory $selectBuilderFactory,
        private Language $language,
        private Config $config,
    ) {}

    public function run(?WhereItem $where, ?User $user): Result
    {
        $rows = $this->fetchAggregateRows($where, $user);

        // Stable display ordering: days asc (chart x-axis), tenants alpha.
        // Empty-day padding is handled centrally by
        // Espo\Modules\Global\Tools\Report\DateBucketPadder, which the
        // Global module wires into Service::reportRunGridOrJoint and which
        // operates on groupByList[0]. By putting the date function first we
        // get fillEmptyDateBuckets behaviour for free (and identical to
        // every other report in the system).
        [$reportData, $group1Sums, $grandTotal, $tenantOrder, $dayOrder]
            = $this->shapeRows($rows);

        $tenantNames = $this->fetchTenantNames(array_keys($tenantOrder), $user);

        $grouping = [
            $this->orderDays($dayOrder),
            $this->orderTenants($tenantOrder, $tenantNames),
        ];

        $groupValueMap = [
            self::GROUP_DAY => $this->buildDayValueMap($grouping[0]),
            self::GROUP_TENANT => $this->buildTenantValueMap($grouping[1], $tenantNames),
        ];

        $columnNameMap = [
            self::COLUMN_KEY => $this->language->translateLabel(
                'conversationsEngaged',
                'columnLabels',
                'ChatwootAiAgentRun'
            ),
        ];

        $sums = (object) [self::COLUMN_KEY => $grandTotal];

        $result = new Result(
            self::ENTITY_TYPE,
            [self::GROUP_DAY, self::GROUP_TENANT],
            [self::COLUMN_KEY],
            [self::COLUMN_KEY],
            [self::COLUMN_KEY],
            [],
            [],
            [self::COLUMN_KEY],
            null,
            null,
            $sums,
            $groupValueMap,
            $columnNameMap,
            [self::COLUMN_KEY => 'int'],
            null,
            $grouping,
            $this->arrayToObjectTree($reportData),
            null,
            null,
            null,
        );

        $result->setGroup1Sums($this->arrayToObjectTree($group1Sums));
        $result->setGroup1NonSummaryColumnList([]);
        $result->setGroup2NonSummaryColumnList([]);

        return $result;
    }

    public function runSubReport(
        SearchParams $searchParams,
        SubReportParams $subReportParams,
        ?User $user
    ): ListResult {

        $groupIndex = $subReportParams->getGroupIndex();
        /** @var string $groupValue */
        $groupValue = (string) $subReportParams->getGroupValue();

        // Resolve which (day, tenant) bucket was clicked.
        // groupIndex 0  → row total for a day      (groupValue = day)
        // groupIndex 1  → individual cell          (groupValue = tenantId,
        //                                           groupValue2 = day)
        if ($groupIndex === 0) {
            $tenantId = null;
            $day = $groupValue;
        } else {
            $tenantId = $groupValue;
            $day = $subReportParams->hasGroupValue2()
                ? (string) $subReportParams->getGroupValue2()
                : null;
        }

        $conversationIds = $this->fetchDistinctConversationIdsForBucket(
            $searchParams,
            $user,
            $tenantId,
            $day
        );

        if (empty($conversationIds)) {
            return new ListResult(
                $this->entityManager->getRDBRepository(self::CONVERSATION_ENTITY_TYPE)
                    ->where(['id' => '__none__'])
                    ->find(),
                0
            );
        }

        // Materialise the conversation list through SelectBuilder so that
        // ChatwootConversation ACL also applies on the drill-down.
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

        // Order by `chatwootCreatedAt` (the Chatwoot-side creation
        // timestamp) — the semantic replacement for the standard
        // `createdAt`. ChatwootConversation deliberately does NOT have a
        // `createdAt` attribute (it carries `chatwootCreatedAt` for the
        // source timestamp and `lastActivityAt` for "natural" UI ordering,
        // with `deleteId` soft-delete instead of `deletedAt`). Ordering by
        // `createdAt` here triggers a `LogicException` from
        // `BaseQueryComposer::getOrderExpressionPart` because the attribute
        // does not exist on the entity. `lastActivityAt` would also work
        // but is unstable (changes over time), which makes the drill-down
        // shuffle conversations as side activity comes in — counter-
        // intuitive when the bucket is labeled by a specific day.
        $listQueryBuilder
            ->where(['id' => $conversationIds])
            ->order('chatwootCreatedAt', Order::DESC);

        $query = $listQueryBuilder->build();

        $repository = $this->entityManager->getRDBRepository(self::CONVERSATION_ENTITY_TYPE);

        $collection = $repository->clone($query)->find();
        $count = $repository->clone($query)->count();

        return new ListResult($collection, $count);
    }

    /**
     * Run the grouped COUNT(DISTINCT) query under the caller's ACL.
     *
     * @return list<array{tenantId: ?string, dayBucket: ?string, conversationsEngaged: int}>
     */
    private function fetchAggregateRows(?WhereItem $where, ?User $user): array
    {
        $searchParams = SearchParams::create();

        if ($where) {
            $searchParams = $searchParams->withWhere($where);
        }

        $selectBuilder = $this->selectBuilderFactory
            ->create()
            ->from(self::ENTITY_TYPE)
            ->withStrictAccessControl()
            ->withSearchParams($searchParams);

        if ($user) {
            $selectBuilder->forUser($user);
        }

        try {
            $queryBuilder = $selectBuilder->buildQueryBuilder();
        } catch (BadRequest|Forbidden $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        // Force a non-null bucket key so rows where tenant_id IS NULL still
        // surface in a dedicated group instead of being collapsed away.
        $tenantExpr = Expr::ifNull(
            Expr::column('tenantId'),
            self::NO_TENANT_KEY
        );

        $dayExpr = Expr::create($this->buildDayExpression());
        $countExpr = Expr::create('COUNT_DISTINCT:conversationId');

        $queryBuilder
            ->select($tenantExpr, 'tenantId')
            ->select($dayExpr, 'dayBucket')
            ->select($countExpr, 'conversationsEngaged')
            ->group($tenantExpr)
            ->group($dayExpr)
            ->order($tenantExpr, Order::ASC)
            ->order($dayExpr, Order::ASC)
            ->limit(0, self::MAX_GROUPS);

        $sth = $this->entityManager->getQueryExecutor()->execute($queryBuilder->build());

        $rows = [];

        foreach ($sth->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'tenantId' => $row['tenantId'] !== null ? (string) $row['tenantId'] : null,
                'dayBucket' => $row['dayBucket'] !== null ? (string) $row['dayBucket'] : null,
                'conversationsEngaged' => (int) $row['conversationsEngaged'],
            ];
        }

        return $rows;
    }

    /**
     * Distinct conversation ids that match (tenant, day) under ACL.
     *
     * @return list<string>
     */
    private function fetchDistinctConversationIdsForBucket(
        SearchParams $searchParams,
        ?User $user,
        ?string $tenantId,
        ?string $day
    ): array {

        $selectBuilder = $this->selectBuilderFactory
            ->create()
            ->from(self::ENTITY_TYPE)
            ->withStrictAccessControl()
            ->withSearchParams($searchParams);

        if ($user) {
            $selectBuilder->forUser($user);
        }

        try {
            $queryBuilder = $selectBuilder->buildQueryBuilder();
        } catch (BadRequest|Forbidden $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        if ($tenantId !== null) {
            if ($tenantId === self::NO_TENANT_KEY) {
                $queryBuilder->where(Expr::isNull(Expr::column('tenantId')));
            } else {
                $queryBuilder->where(['tenantId' => $tenantId]);
            }
        }

        if ($day !== null) {
            $queryBuilder->where(Cond::equal(
                Expr::create($this->buildDayExpression()),
                Expr::value($day)
            ));
        }

        $queryBuilder
            ->where(Expr::isNotNull(Expr::column('conversationId')))
            ->select(Expr::column('conversationId'), 'conversationId')
            ->group(Expr::column('conversationId'))
            // Cap drill-down rows at the standard list ceiling so a huge
            // bucket doesn't return everything in one shot.
            ->limit(0, 200);

        $sth = $this->entityManager->getQueryExecutor()->execute($queryBuilder->build());

        $ids = [];

        foreach ($sth->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (!empty($row['conversationId'])) {
                $ids[] = (string) $row['conversationId'];
            }
        }

        return $ids;
    }

    /**
     * Resolve tenant id → name via ACL-aware Tenant queries.
     *
     * Tenant rows the user cannot see are returned with a translated
     * placeholder so the grid still renders the bucket count without
     * leaking the tenant name across team boundaries.
     *
     * @param list<string> $tenantIds
     * @return array<string, string>
     */
    private function fetchTenantNames(array $tenantIds, ?User $user): array
    {
        $tenantIds = array_values(array_unique(array_filter(
            $tenantIds,
            fn ($v) => $v !== null && $v !== '' && $v !== self::NO_TENANT_KEY
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
     * Pivot the SQL rowset into reportData + group1Sums shape required by
     * the Advanced Pack grid renderer.
     *
     * group1 = day, group2 = tenant — so:
     *   reportData[day][tenant] = ['COUNT:id' => N]
     *   group1Sums[day]['COUNT:id'] = sum across tenants for that day
     *
     * The day-first layout matches DateBucketPadder's expectation (it pads
     * groupByList[0]) and renders a natural timeline chart.
     *
     * @param list<array{tenantId: ?string, dayBucket: ?string, conversationsEngaged: int}> $rows
     * @return array{
     *     array<string, array<string, array<string, int>>>,
     *     array<string, array<string, int>>,
     *     int,
     *     array<string, true>,
     *     array<string, true>
     * }
     */
    private function shapeRows(array $rows): array
    {
        $reportData = [];
        $group1Sums = [];
        $grandTotal = 0;
        $tenantOrder = [];
        $dayOrder = [];

        foreach ($rows as $row) {
            $tenantKey = $row['tenantId'] ?? self::NO_TENANT_KEY;
            $dayKey = $row['dayBucket'] ?? '-';
            $value = $row['conversationsEngaged'];

            $tenantOrder[$tenantKey] = true;
            $dayOrder[$dayKey] = true;

            $reportData[$dayKey][$tenantKey] = [self::COLUMN_KEY => $value];

            $group1Sums[$dayKey][self::COLUMN_KEY] =
                ($group1Sums[$dayKey][self::COLUMN_KEY] ?? 0) + $value;

            $grandTotal += $value;
        }

        return [$reportData, $group1Sums, $grandTotal, $tenantOrder, $dayOrder];
    }

    /**
     * @param array<string, true> $tenantOrder
     * @param array<string, string> $tenantNames
     * @return list<string>
     */
    private function orderTenants(array $tenantOrder, array $tenantNames): array
    {
        $keys = array_keys($tenantOrder);

        usort($keys, function (string $a, string $b) use ($tenantNames): int {
            // NO_TENANT bucket always last.
            if ($a === self::NO_TENANT_KEY) {
                return 1;
            }
            if ($b === self::NO_TENANT_KEY) {
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
     * @param list<string> $tenantList
     * @param array<string, string> $tenantNames
     * @return array<string, string>
     */
    private function buildTenantValueMap(array $tenantList, array $tenantNames): array
    {
        $map = [];

        foreach ($tenantList as $tenantKey) {
            if ($tenantKey === self::NO_TENANT_KEY) {
                $map[$tenantKey] = $this->language->translateLabel(
                    'noTenant',
                    'labels',
                    'ChatwootAiAgentRun'
                );

                continue;
            }

            $map[$tenantKey] = $tenantNames[$tenantKey]
                ?? $this->language->translateLabel('restricted', 'labels', 'ChatwootAiAgentRun');
        }

        return $map;
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
     * Build the timezone-aware ORM expression used to bucket runs by day.
     *
     * Without an explicit timezone, Espo's `DAY:` function evaluates the
     * underlying timestamp in the database/server timezone (typically UTC),
     * while runtime `between` filters from the dashboard carry the user's
     * timezone — producing buckets that don't line up with filter bounds
     * (a row at 01:30 UTC May-19 is bucketed as "May-19" but excluded from
     * a filter that starts at BRT May-19 00:00 = UTC May-19 03:00).
     *
     * Mirrors `Espo\Modules\Advanced\Tools\Report\SelectHelper` which
     * applies `TZ:(arg, offset)` around the column whenever the system has
     * a non-UTC timezone configured. The same offset is used by Espo's
     * filter date-resolver, so bucket and filter end up using the same
     * day boundary.
     */
    private function buildDayExpression(): string
    {
        $offset = $this->getTimeZoneOffset();

        if ($offset === 0 || $offset === 0.0) {
            return 'DAY:runAt';
        }

        return "DAY:TZ:(runAt,$offset)";
    }

    /**
     * @return float|int
     */
    private function getTimeZoneOffset()
    {
        $timeZone = $this->config->get('timeZone', 'UTC');

        if ($timeZone === 'UTC') {
            return 0;
        }

        try {
            $tz = new DateTimeZone($timeZone);
            // Pin to a non-DST instant ('first day of january') so the
            // offset stays stable year-round — same trick Espo uses.
            $anchor = (new DateTime('now', $tz))->modify('first day of january');

            return $tz->getOffset($anchor) / 3600;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Convert nested arrays into the nested stdClass tree the renderer
     * expects (mirrors LeadsByLastActivity's tail conversion).
     *
     * @param array<string, mixed> $tree
     */
    private function arrayToObjectTree(array $tree): stdClass
    {
        $converted = [];

        foreach ($tree as $k => $v) {
            if (!is_array($v)) {
                $converted[$k] = $v;
                continue;
            }

            $inner = [];

            foreach ($v as $k1 => $v1) {
                $inner[$k1] = is_array($v1) ? (object) $v1 : $v1;
            }

            $converted[$k] = (object) $inner;
        }

        return (object) $converted;
    }
}
