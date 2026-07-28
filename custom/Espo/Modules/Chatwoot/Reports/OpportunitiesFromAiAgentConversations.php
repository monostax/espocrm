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

/**
 * Totals for Opportunities linked (m2m) to ChatwootConversations that had
 * ≥1 ChatwootAiAgentRun matching the runtime where (typically runAt period
 * + tenantId from runReport).
 *
 * Won / Lost require Opportunity.closeDate ∈ the same calendar window as the
 * runAt filter. Open is a snapshot of currently open AI-touched opps.
 * ALL = OPEN + Won(in period) + Lost(in period).
 *
 * Columns mirror "Oportunidades (R$)" so formula templates stay compatible:
 *   SUM:amountConverted
 *   SUM:IF:(EQUAL:(probability, 100), amountConverted, 0)          // won
 *   SUM:IF:(EQUAL:(probability, 0), amountConverted, 0)            // lost
 *   SUM:IF:(AND:(NOT_EQUAL:(probability, 100), NOT_EQUAL:(probability, 0)), amountConverted, 0) // open
 *   COUNT:id
 *   plus count variants for won/lost/open
 */
class OpportunitiesFromAiAgentConversations implements GridReport
{
    private const RUN_ENTITY = 'ChatwootAiAgentRun';
    private const OPP_ENTITY = 'Opportunity';

    private const COL_ALL_AMT = 'SUM:amountConverted';
    private const COL_ALL_CNT = 'COUNT:id';
    private const COL_WON_AMT =
        'SUM:IF:(EQUAL:(probability, 100), amountConverted, 0)';
    private const COL_WON_CNT =
        'SUM:IF:(EQUAL:(probability, 100), 1, 0)';
    private const COL_LOST_AMT =
        'SUM:IF:(EQUAL:(probability, 0), amountConverted, 0)';
    private const COL_LOST_CNT =
        'SUM:IF:(EQUAL:(probability, 0), 1, 0)';
    private const COL_OPEN_AMT =
        'SUM:IF:(AND:(NOT_EQUAL:(probability, 100), NOT_EQUAL:(probability, 0)), amountConverted, 0)';
    private const COL_OPEN_CNT =
        'SUM:IF:(AND:(NOT_EQUAL:(probability, 100), NOT_EQUAL:(probability, 0)), 1, 0)';

    private const MAX_CONVERSATIONS = 10000;
    private const MAX_OPPORTUNITIES = 5000;

    public function __construct(
        private EntityManager $entityManager,
        private SelectBuilderFactory $selectBuilderFactory,
        private Language $language,
        private Config $config,
    ) {}

    public function run(?WhereItem $where, ?User $user): Result
    {
        $conversationIds = $this->fetchDistinctConversationIds($where, $user);
        $opportunityIds = $this->fetchOpportunityIdsForConversations($conversationIds);
        $period = AiLinkedOpportunityBuckets::extractDatePeriod($where, 'runAt');
        $totals = $this->sumOpportunities($opportunityIds, $user, $period['start'], $period['end']);

        $columnList = [
            self::COL_WON_AMT,
            self::COL_LOST_CNT,
            self::COL_OPEN_AMT,
            self::COL_OPEN_CNT,
            self::COL_LOST_AMT,
            self::COL_WON_CNT,
            self::COL_ALL_AMT,
            self::COL_ALL_CNT,
        ];

        $columnNameMap = [
            self::COL_WON_AMT => 'Ganhas',
            self::COL_LOST_CNT => 'Perdidas (Count)',
            self::COL_OPEN_AMT => 'Abertas',
            self::COL_OPEN_CNT => 'Abertas (Count)',
            self::COL_LOST_AMT => 'Perdidas',
            self::COL_WON_CNT => 'Ganhas (Count)',
            self::COL_ALL_AMT => 'Todos',
            self::COL_ALL_CNT => 'Todos (Count)',
        ];

        $columnTypeMap = [
            self::COL_WON_AMT => 'currency',
            self::COL_OPEN_AMT => 'currency',
            self::COL_LOST_AMT => 'currency',
            self::COL_ALL_AMT => 'currency',
            self::COL_WON_CNT => 'int',
            self::COL_OPEN_CNT => 'int',
            self::COL_LOST_CNT => 'int',
            self::COL_ALL_CNT => 'int',
        ];

        $sums = (object) $totals;

        $result = new Result(
            self::OPP_ENTITY,
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
        $where = $searchParams->getWhere();
        $conversationIds = $this->fetchDistinctConversationIds($where, $user);
        $opportunityIds = $this->fetchOpportunityIdsForConversations($conversationIds);

        if ($opportunityIds === []) {
            return new ListResult(
                $this->entityManager->getRDBRepository(self::OPP_ENTITY)
                    ->where(['id' => '__none__'])
                    ->find(),
                0
            );
        }

        $listBuilder = $this->selectBuilderFactory
            ->create()
            ->from(self::OPP_ENTITY)
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
            ->where(['id' => $opportunityIds])
            ->order('createdAt', Order::DESC)
            ->limit(0, 200);

        $query = $listQueryBuilder->build();
        $repository = $this->entityManager->getRDBRepository(self::OPP_ENTITY);
        $collection = $repository->clone($query)->find();
        $count = $repository->clone($query)->count();

        return new ListResult($collection, $count);
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
     * @param list<string> $opportunityIds
     * @return array<string, float|int>
     */
    private function sumOpportunities(
        array $opportunityIds,
        ?User $user,
        ?string $periodStart = null,
        ?string $periodEnd = null,
    ): array {
        $empty = [
            self::COL_WON_AMT => 0.0,
            self::COL_LOST_CNT => 0,
            self::COL_OPEN_AMT => 0.0,
            self::COL_OPEN_CNT => 0,
            self::COL_LOST_AMT => 0.0,
            self::COL_WON_CNT => 0,
            self::COL_ALL_AMT => 0.0,
            self::COL_ALL_CNT => 0,
        ];

        if ($opportunityIds === []) {
            return $empty;
        }

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

        // Project rows under Opportunity ACL; convert currency + bucket in PHP
        // (SelectBuilder does not support report IF: aggregate dialect).
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
        $wonCnt = 0;
        $lostCnt = 0;
        $openCnt = 0;
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
                $periodStart,
                $periodEnd
            );

            if ($prob === 100) {
                if (!$inClosePeriod) {
                    continue;
                }
                $wonAmt += $converted;
                $wonCnt++;
                $allAmt += $converted;
                $allCnt++;
            } elseif ($prob === 0) {
                if (!$inClosePeriod) {
                    continue;
                }
                $lostAmt += $converted;
                $lostCnt++;
                $allAmt += $converted;
                $allCnt++;
            } else {
                $openAmt += $converted;
                $openCnt++;
                $allAmt += $converted;
                $allCnt++;
            }
        }

        return [
            self::COL_WON_AMT => $wonAmt,
            self::COL_LOST_CNT => $lostCnt,
            self::COL_OPEN_AMT => $openAmt,
            self::COL_OPEN_CNT => $openCnt,
            self::COL_LOST_AMT => $lostAmt,
            self::COL_WON_CNT => $wonCnt,
            self::COL_ALL_AMT => $allAmt,
            self::COL_ALL_CNT => $allCnt,
        ];
    }
}
