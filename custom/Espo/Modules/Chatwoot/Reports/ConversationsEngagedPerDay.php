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
 * Grid report: distinct ChatwootConversation count per day.
 *
 * Group 1: DAY(ChatwootAiAgentRun.runAt) (display: YYYY-MM-DD)
 * Metric : COUNT(DISTINCT conversationId)
 *
 * Tenancy is *not* a grouping dimension here. The caller's ACL is the only
 * tenancy boundary: every query goes through SelectBuilder with
 * `withStrictAccessControl()` + `forUser()`, so team-based row-level access
 * trims runs to those the calling user can see. The per-day count therefore
 * automatically reflects whatever tenants the user belongs to — without
 * leaking tenant identities into the output.
 *
 * Runtime filter support: `runAt` (date range). The WhereItem the service
 * passes in is layered on top of the SearchParams via `withWhereAdded()`,
 * so it composes with any default filters on the Report row.
 *
 * Empty-day padding is handled centrally by
 * Espo\Modules\Global\Tools\Report\DateBucketPadder when the Report row has
 * `fillEmptyDateBuckets = true`.
 *
 * Drill-down (`runSubReport`): clicking a day lists the DISTINCT
 * ChatwootConversation entities that had at least one AI run on that day,
 * within the caller's ACL.
 */
class ConversationsEngagedPerDay implements GridReport
{
    private const ENTITY_TYPE = 'ChatwootAiAgentRun';
    private const CONVERSATION_ENTITY_TYPE = 'ChatwootConversation';

    private const COLUMN_KEY = 'COUNT:id';
    private const GROUP_DAY = 'DAY:runAt';

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

        [$reportData, $grandTotal, $dayOrder] = $this->shapeRows($rows);

        $grouping = [
            $this->orderDays($dayOrder),
        ];

        $groupValueMap = [
            self::GROUP_DAY => $this->buildDayValueMap($grouping[0]),
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
            [self::GROUP_DAY],
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

        $result->setGroup1NonSummaryColumnList([]);

        return $result;
    }

    public function runSubReport(
        SearchParams $searchParams,
        SubReportParams $subReportParams,
        ?User $user
    ): ListResult {

        // Single-dimension report: groupIndex is always 0, groupValue is day.
        /** @var string $day */
        $day = (string) $subReportParams->getGroupValue();

        $conversationIds = $this->fetchDistinctConversationIdsForDay(
            $searchParams,
            $user,
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
     * @return list<array{dayBucket: ?string, conversationsEngaged: int}>
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

        $dayExpr = Expr::create($this->buildDayExpression());
        $countExpr = Expr::create('COUNT_DISTINCT:conversationId');

        $queryBuilder
            ->select($dayExpr, 'dayBucket')
            ->select($countExpr, 'conversationsEngaged')
            ->group($dayExpr)
            ->order($dayExpr, Order::ASC)
            ->limit(0, self::MAX_GROUPS);

        $sth = $this->entityManager->getQueryExecutor()->execute($queryBuilder->build());

        $rows = [];

        foreach ($sth->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'dayBucket' => $row['dayBucket'] !== null ? (string) $row['dayBucket'] : null,
                'conversationsEngaged' => (int) $row['conversationsEngaged'],
            ];
        }

        return $rows;
    }

    /**
     * Distinct conversation ids that match the day under ACL.
     *
     * @return list<string>
     */
    private function fetchDistinctConversationIdsForDay(
        SearchParams $searchParams,
        ?User $user,
        string $day
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

        $queryBuilder
            ->where(Cond::equal(
                Expr::create($this->buildDayExpression()),
                Expr::value($day)
            ))
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
     * Pivot the SQL rowset into the single-group reportData shape required
     * by the Advanced Pack grid renderer.
     *
     * reportData[day] = ['COUNT:id' => N]
     *
     * @param list<array{dayBucket: ?string, conversationsEngaged: int}> $rows
     * @return array{
     *     array<string, array<string, int>>,
     *     int,
     *     array<string, true>
     * }
     */
    private function shapeRows(array $rows): array
    {
        $reportData = [];
        $grandTotal = 0;
        $dayOrder = [];

        foreach ($rows as $row) {
            $dayKey = $row['dayBucket'] ?? '-';
            $value = $row['conversationsEngaged'];

            $dayOrder[$dayKey] = true;
            $reportData[$dayKey] = [self::COLUMN_KEY => $value];

            $grandTotal += $value;
        }

        return [$reportData, $grandTotal, $dayOrder];
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
     * Convert the single-level array into the stdClass tree the renderer
     * expects.
     *
     * @param array<string, array<string, int>> $tree
     */
    private function arrayToObjectTree(array $tree): stdClass
    {
        $converted = [];

        foreach ($tree as $k => $v) {
            $converted[$k] = (object) $v;
        }

        return (object) $converted;
    }
}
