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

use DateTimeImmutable;
use DateTimeZone;
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
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\Part\Order;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Grid: conversation-days with ≥1 AI run outside business hours, per day.
 *
 * Business hours (MVP): Mon–Fri 08:00–18:00 in the **Tenant** time zone
 * (`Tenant.timeZone`). Empty tenant TZ → instance `config.timeZone` → UTC.
 * Outside = weekend OR hour < 8 OR hour >= 18 (tenant-local).
 *
 * Day buckets are calendar days in that same tenant TZ so BH and grouping align.
 *
 * Metric: COUNT(DISTINCT conversationId) per local day (billable conversation-day grain).
 * ACL: strict SelectBuilder on ChatwootAiAgentRun.
 */
class EngagementsOutsideBusinessHoursPerDay implements GridReport
{
    private const ENTITY_TYPE = 'ChatwootAiAgentRun';
    private const CONVERSATION_ENTITY_TYPE = 'ChatwootConversation';
    private const TENANT_ENTITY_TYPE = 'Tenant';

    private const COLUMN_KEY = 'COUNT:id';
    private const GROUP_DAY = 'DAY:runAt';

    /** Inclusive start hour (tenant-local). */
    private const BH_START_HOUR = 8;
    /** Exclusive end hour (tenant-local). */
    private const BH_END_HOUR = 18;

    private const MAX_RUN_ROWS = 50000;
    private const MAX_GROUPS = 1000;
    private const MAX_DRILL_CONVERSATIONS = 200;

    /** @var array<string, DateTimeZone> */
    private array $tzCache = [];

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
                'engagementsOutsideBusinessHours',
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
        /** @var string $day */
        $day = (string) $subReportParams->getGroupValue();

        $conversationIds = $this->fetchDistinctConversationIdsForDay(
            $searchParams,
            $user,
            $day
        );

        if ($conversationIds === []) {
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
     * @return list<array{dayBucket: ?string, count: int}>
     */
    private function fetchAggregateRows(?WhereItem $where, ?User $user): array
    {
        $searchParams = SearchParams::create();

        if ($where) {
            $searchParams = $searchParams->withWhere($where);
        }

        $grains = $this->collectOutsideHoursGrains($searchParams, $user);

        /** @var array<string, array<string, true>> $byDay */
        $byDay = [];

        foreach ($grains as $grain) {
            $day = $grain['dayBucket'];
            $conv = $grain['conversationId'];
            $byDay[$day][$conv] = true;
        }

        $rows = [];

        foreach ($byDay as $dayBucket => $convs) {
            $rows[] = [
                'dayBucket' => $dayBucket,
                'count' => count($convs),
            ];
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => strcmp((string) $a['dayBucket'], (string) $b['dayBucket'])
        );

        if (count($rows) > self::MAX_GROUPS) {
            $rows = array_slice($rows, 0, self::MAX_GROUPS);
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function fetchDistinctConversationIdsForDay(
        SearchParams $searchParams,
        ?User $user,
        string $day
    ): array {
        $grains = $this->collectOutsideHoursGrains($searchParams, $user);

        $ids = [];

        foreach ($grains as $grain) {
            if ($grain['dayBucket'] !== $day) {
                continue;
            }

            $ids[$grain['conversationId']] = true;

            if (count($ids) >= self::MAX_DRILL_CONVERSATIONS) {
                break;
            }
        }

        return array_keys($ids);
    }

    /**
     * @return list<array{conversationId: string, dayBucket: string}>
     */
    private function collectOutsideHoursGrains(SearchParams $searchParams, ?User $user): array
    {
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
            ->where(Expr::isNotNull(Expr::column('conversationId')))
            ->where(Expr::isNotNull(Expr::column('runAt')))
            ->select(Expr::column('conversationId'), 'conversationId')
            ->select(Expr::column('tenantId'), 'tenantId')
            ->select(Expr::column('runAt'), 'runAt')
            ->limit(0, self::MAX_RUN_ROWS);

        $sth = $this->entityManager->getQueryExecutor()->execute($queryBuilder->build());
        $rawRows = $sth->fetchAll(\PDO::FETCH_ASSOC);

        $tenantIds = [];

        foreach ($rawRows as $row) {
            $tid = $row['tenantId'] ?? null;
            if (is_string($tid) && $tid !== '') {
                $tenantIds[$tid] = true;
            }
        }

        $tzByTenant = $this->loadTenantTimeZones(array_keys($tenantIds));
        $fallbackTz = $this->resolveTimeZone($this->instanceTimeZoneName());

        /** @var array<string, true> $seen grain key conversationId|day */
        $seen = [];
        $out = [];

        foreach ($rawRows as $row) {
            $conversationId = $row['conversationId'] ?? null;
            $runAt = $row['runAt'] ?? null;

            if (!is_string($conversationId) || $conversationId === '' || !is_string($runAt) || $runAt === '') {
                continue;
            }

            $tenantId = is_string($row['tenantId'] ?? null) ? (string) $row['tenantId'] : '';
            $tz = $tzByTenant[$tenantId] ?? $fallbackTz;

            try {
                $utc = new DateTimeImmutable($runAt, new DateTimeZone('UTC'));
            } catch (Throwable) {
                continue;
            }

            $local = $utc->setTimezone($tz);

            if (!$this->isOutsideBusinessHours($local)) {
                continue;
            }

            $dayBucket = $local->format('Y-m-d');
            $key = $conversationId . '|' . $dayBucket;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = [
                'conversationId' => $conversationId,
                'dayBucket' => $dayBucket,
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $tenantIds
     * @return array<string, DateTimeZone>
     */
    private function loadTenantTimeZones(array $tenantIds): array
    {
        $map = [];

        if ($tenantIds === []) {
            return $map;
        }

        $collection = $this->entityManager
            ->getRDBRepository(self::TENANT_ENTITY_TYPE)
            ->where(['id' => $tenantIds])
            ->select(['id', 'timeZone'])
            ->find();

        foreach ($collection as $tenant) {
            $id = $tenant->getId();
            $name = trim((string) ($tenant->get('timeZone') ?? ''));

            if ($name === '') {
                $name = $this->instanceTimeZoneName();
            }

            $map[$id] = $this->resolveTimeZone($name);
        }

        return $map;
    }

    private function instanceTimeZoneName(): string
    {
        $name = trim((string) $this->config->get('timeZone', 'UTC'));

        return $name !== '' ? $name : 'UTC';
    }

    private function resolveTimeZone(string $name): DateTimeZone
    {
        if (isset($this->tzCache[$name])) {
            return $this->tzCache[$name];
        }

        try {
            $tz = new DateTimeZone($name);
        } catch (Throwable) {
            $tz = new DateTimeZone('UTC');
        }

        $this->tzCache[$name] = $tz;

        return $tz;
    }

    private function isOutsideBusinessHours(DateTimeImmutable $local): bool
    {
        // PHP: 0 = Sunday … 6 = Saturday
        $dow = (int) $local->format('w');

        if ($dow === 0 || $dow === 6) {
            return true;
        }

        $hour = (int) $local->format('G');

        return $hour < self::BH_START_HOUR || $hour >= self::BH_END_HOUR;
    }

    /**
     * @param list<array{dayBucket: ?string, count: int}> $rows
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
            $value = $row['count'];

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
