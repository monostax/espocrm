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
use Espo\Entities\User;
use Espo\Modules\Advanced\Reports\GridReport;
use Espo\Modules\Advanced\Tools\Report\GridType\Result;
use Espo\Modules\Advanced\Tools\Report\ListType\Result as ListResult;
use Espo\Modules\Advanced\Tools\Report\ListType\SubReportParams;
use Espo\Modules\Chatwoot\Tools\Billing\ConversationDayGrainFetcher;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Expression as Expr;
use RuntimeException;
use Throwable;

/**
 * Single grand-total grid for the daily/weekly WhatsApp AI digest.
 *
 * Totals (no day grouping) under the runtime where (typically runAt + tenant):
 *   Opp scope            — distinct Opportunities linked to AI-touched convos
 *   SUM:amountConverted — OPEN snapshot + Won/Lost with closeDate in period
 *   COUNT:opportunities — same membership as SUM:amountConverted
 *   ganhas/perdidas     — probability 100/0 AND closeDate ∈ [period start, end)
 *   abertas             — currently open (prob ∉ {0,100}), no closeDate gate
 *   COUNT:conversations — conversation-days engaged (same grain as chwRptCvDay)
 *   COUNT:afterHours    — conversation-days with ≥1 run outside BH
 *   AVG:leadTimeMs / turns
 */
class DailyAiDigest implements GridReport
{
    private const RUN_ENTITY = 'ChatwootAiAgentRun';
    private const OPP_ENTITY = 'Opportunity';
    private const TENANT_ENTITY = 'Tenant';

    private const COL_OPP_AMT = 'SUM:amountConverted';
    private const COL_OPP_CNT = 'COUNT:opportunities';
    private const COL_WON_AMT =
        'SUM:IF:(EQUAL:(probability, 100), amountConverted, 0)';
    private const COL_LOST_AMT =
        'SUM:IF:(EQUAL:(probability, 0), amountConverted, 0)';
    private const COL_OPEN_AMT =
        'SUM:IF:(AND:(NOT_EQUAL:(probability, 100), NOT_EQUAL:(probability, 0)), amountConverted, 0)';
    private const COL_CONV = 'COUNT:conversations';
    private const COL_AFTER = 'COUNT:afterHours';
    private const COL_AFTER_WEEKEND = 'COUNT:afterHoursWeekend';
    private const COL_AFTER_WEEKDAY = 'COUNT:afterHoursWeekday';
    private const COL_LEAD = 'AVG:leadTimeMs';
    private const COL_TURNS = 'turns';

    private const BH_START_HOUR = 8;
    private const BH_END_HOUR = 18;

    private const MAX_CONVERSATIONS = 10000;
    private const MAX_OPPORTUNITIES = 5000;
    private const MAX_RUN_ROWS = 50000;

    /** @var array<string, DateTimeZone> */
    private array $tzCache = [];

    public function __construct(
        private EntityManager $entityManager,
        private SelectBuilderFactory $selectBuilderFactory,
        private Config $config,
        private ConversationDayGrainFetcher $grainFetcher,
    ) {}

    public function run(?WhereItem $where, ?User $user): Result
    {
        $opp = $this->sumOpportunitiesLinkedToAi($where, $user);
        $convMetrics = $this->conversationMetrics($where, $user);
        $leadAvg = $this->averageLeadTimeMs($where, $user);

        $totals = array_merge($opp, $convMetrics, [
            self::COL_LEAD => $leadAvg,
        ]);

        $columnList = [
            self::COL_OPP_AMT,
            self::COL_OPP_CNT,
            self::COL_WON_AMT,
            self::COL_OPEN_AMT,
            self::COL_LOST_AMT,
            self::COL_CONV,
            self::COL_AFTER,
            self::COL_AFTER_WEEKEND,
            self::COL_AFTER_WEEKDAY,
            self::COL_LEAD,
            self::COL_TURNS,
        ];

        $columnNameMap = [
            self::COL_OPP_AMT => 'Oportunidades (R$)',
            self::COL_OPP_CNT => 'Oportunidades',
            self::COL_WON_AMT => 'Ganhas (R$)',
            self::COL_OPEN_AMT => 'Abertas (R$)',
            self::COL_LOST_AMT => 'Perdidas (R$)',
            self::COL_CONV => 'Conversas atendidas',
            self::COL_AFTER => 'Fora do horário',
            self::COL_AFTER_WEEKEND => 'Fora do horário (fim de semana)',
            self::COL_AFTER_WEEKDAY => 'Fora do horário (dia útil)',
            self::COL_LEAD => 'Lead time (ms)',
            self::COL_TURNS => 'Engajamentos (turnos)',
        ];

        $columnTypeMap = [
            self::COL_OPP_AMT => 'currency',
            self::COL_WON_AMT => 'currency',
            self::COL_OPEN_AMT => 'currency',
            self::COL_LOST_AMT => 'currency',
            self::COL_OPP_CNT => 'int',
            self::COL_CONV => 'int',
            self::COL_AFTER => 'int',
            self::COL_AFTER_WEEKEND => 'int',
            self::COL_AFTER_WEEKDAY => 'int',
            self::COL_LEAD => 'float',
            self::COL_TURNS => 'int',
        ];

        $sums = (object) $totals;

        $result = new Result(
            self::RUN_ENTITY,
            [],
            $columnList,
            $columnList,
            $columnList,
            [],
            [],
            $columnList,
            null,
            null,
            $sums,
            [],
            $columnNameMap,
            $columnTypeMap,
            null,
            [],
            (object) [],
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
        // Digest is totals-only — no meaningful drill-down.
        return new ListResult(
            $this->entityManager->getRDBRepository(self::RUN_ENTITY)
                ->where(['id' => '__none__'])
                ->find(),
            0
        );
    }

    /**
     * @return array<string, float|int>
     */
    private function sumOpportunitiesLinkedToAi(?WhereItem $where, ?User $user): array
    {
        $empty = [
            self::COL_OPP_AMT => 0.0,
            self::COL_OPP_CNT => 0,
            self::COL_WON_AMT => 0.0,
            self::COL_OPEN_AMT => 0.0,
            self::COL_LOST_AMT => 0.0,
        ];

        $conversationIds = $this->fetchDistinctConversationIds($where, $user);
        $opportunityIds = $this->fetchOpportunityIdsForConversations($conversationIds);

        if ($opportunityIds === []) {
            return $empty;
        }

        $period = AiLinkedOpportunityBuckets::extractDatePeriod($where, 'runAt');
        $start = $period['start'];
        $end = $period['end'];

        $selectBuilder = $this->selectBuilderFactory
            ->create()
            ->from(self::OPP_ENTITY)
            ->withStrictAccessControl();

        if ($user) {
            $selectBuilder->forUser($user);
        }

        try {
            $queryBuilder = $selectBuilder->buildQueryBuilder();
        } catch (BadRequest|Forbidden $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        $queryBuilder
            ->where(['id' => $opportunityIds])
            ->select(['id', 'amount', 'amountCurrency', 'probability', 'closeDate'])
            ->leftJoin(
                'Currency',
                'amountCurrencyRate',
                ['amountCurrencyRate.id:' => 'amountCurrency']
            )
            ->select(Expr::column('amountCurrencyRate.rate'), 'currencyRate');

        $sth = $this->entityManager->getQueryExecutor()->execute($queryBuilder->build());

        $wonAmt = 0.0;
        $lostAmt = 0.0;
        $openAmt = 0.0;
        $allAmt = 0.0;
        $allCnt = 0;

        foreach ($sth->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $amount = (float) ($row['amount'] ?? 0);
            $rate = isset($row['currencyRate']) && $row['currencyRate'] !== null
                ? (float) $row['currencyRate']
                : 1.0;
            if ($rate <= 0.0) {
                $rate = 1.0;
            }
            $converted = $amount * $rate;
            $prob = (int) ($row['probability'] ?? 0);
            $closeDate = isset($row['closeDate']) ? (string) $row['closeDate'] : null;
            $inClosePeriod = AiLinkedOpportunityBuckets::isCloseDateInPeriod(
                $closeDate,
                $start,
                $end
            );

            if ($prob === 100) {
                if (!$inClosePeriod) {
                    continue;
                }
                $wonAmt += $converted;
                $allAmt += $converted;
                $allCnt++;
            } elseif ($prob === 0) {
                if (!$inClosePeriod) {
                    continue;
                }
                $lostAmt += $converted;
                $allAmt += $converted;
                $allCnt++;
            } else {
                $openAmt += $converted;
                $allAmt += $converted;
                $allCnt++;
            }
        }

        return [
            self::COL_OPP_AMT => $allAmt,
            self::COL_OPP_CNT => $allCnt,
            self::COL_WON_AMT => $wonAmt,
            self::COL_OPEN_AMT => $openAmt,
            self::COL_LOST_AMT => $lostAmt,
        ];
    }

    /**
     * Conversation-days + after-hours breakdown + turns.
     *
     * @return array<string, int>
     */
    private function conversationMetrics(?WhereItem $where, ?User $user): array
    {
        $grains = $this->grainFetcher->fetch($where, $user);

        $conversationDays = 0;
        $turns = 0;

        foreach ($grains as $grain) {
            $conversationDays++;
            $turns += $grain->totalTurns();
        }

        $after = $this->countAfterHoursConversationDays($where, $user);

        return [
            self::COL_CONV => $conversationDays,
            self::COL_AFTER => $after['total'],
            self::COL_AFTER_WEEKEND => $after['weekend'],
            self::COL_AFTER_WEEKDAY => $after['weekday'],
            self::COL_TURNS => $turns,
        ];
    }

    /**
     * Conversation-days with ≥1 AI run outside business hours.
     * A day is weekend XOR weekday for bucketing (Sat/Sun → weekend only).
     *
     * @return array{total: int, weekend: int, weekday: int}
     */
    private function countAfterHoursConversationDays(?WhereItem $where, ?User $user): array
    {
        $searchParams = SearchParams::create();

        if ($where) {
            $searchParams = $searchParams->withWhere($where);
        }

        $selectBuilder = $this->selectBuilderFactory
            ->create()
            ->from(self::RUN_ENTITY)
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

        /** @var array<string, true> $weekend */
        $weekend = [];
        /** @var array<string, true> $weekday */
        $weekday = [];

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
            $dow = (int) $local->format('w');

            if ($dow === 0 || $dow === 6) {
                $weekend[$key] = true;
            } else {
                $weekday[$key] = true;
            }
        }

        $weekendN = count($weekend);
        $weekdayN = count($weekday);

        return [
            'total' => $weekendN + $weekdayN,
            'weekend' => $weekendN,
            'weekday' => $weekdayN,
        ];
    }

    private function averageLeadTimeMs(?WhereItem $where, ?User $user): float
    {
        $searchParams = SearchParams::create();

        if ($where) {
            $searchParams = $searchParams->withWhere($where);
        }

        $selectBuilder = $this->selectBuilderFactory
            ->create()
            ->from(self::RUN_ENTITY)
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
            ->where(Expr::isNotNull(Expr::column('leadTimeMs')))
            ->select(Expr::create('AVG:leadTimeMs'), 'avgLead')
            ->select(Expr::create('COUNT:id'), 'cnt');

        $sth = $this->entityManager->getQueryExecutor()->execute($queryBuilder->build());
        $row = $sth->fetch(\PDO::FETCH_ASSOC) ?: [];

        $cnt = (int) ($row['cnt'] ?? 0);
        if ($cnt <= 0) {
            return 0.0;
        }

        return (float) ($row['avgLead'] ?? 0);
    }

    /**
     * @return list<string>
     */
    private function fetchDistinctConversationIds(?WhereItem $where, ?User $user): array
    {
        $searchParams = SearchParams::create();

        if ($where) {
            $searchParams = $searchParams->withWhere($where);
        }

        $selectBuilder = $this->selectBuilderFactory
            ->create()
            ->from(self::RUN_ENTITY)
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
            ->where(['conversationId!=' => null])
            ->select(Expr::column('conversationId'), 'conversationId')
            ->group(Expr::column('conversationId'))
            ->limit(0, self::MAX_CONVERSATIONS);

        $sth = $this->entityManager->getQueryExecutor()->execute($queryBuilder->build());

        $ids = [];

        foreach ($sth->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $id = trim((string) ($row['conversationId'] ?? ''));
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param list<string> $conversationIds
     * @return list<string>
     */
    private function fetchOpportunityIdsForConversations(array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }

        $pdo = $this->entityManager->getPDO();
        $placeholders = implode(',', array_fill(0, count($conversationIds), '?'));

        $sql = "SELECT DISTINCT opportunity_id AS opp_id
                FROM chatwoot_conversation_opportunity
                WHERE deleted = 0
                  AND opportunity_id IS NOT NULL
                  AND chatwoot_conversation_id IN ({$placeholders})
                LIMIT " . self::MAX_OPPORTUNITIES;

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($conversationIds));

        $ids = [];

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $id = trim((string) ($row['opp_id'] ?? ''));
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
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
            ->getRDBRepository(self::TENANT_ENTITY)
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
        $dow = (int) $local->format('w');

        if ($dow === 0 || $dow === 6) {
            return true;
        }

        $hour = (int) $local->format('G');

        return $hour < self::BH_START_HOUR || $hour >= self::BH_END_HOUR;
    }
}
