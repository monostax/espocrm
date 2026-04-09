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

namespace Espo\Modules\Global\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Modules\Crm\Controllers\Opportunity as CrmOpportunity;
use Espo\Modules\Crm\Entities\Opportunity as OpportunityEntity;
use Espo\ORM\Query\Part\Condition as Cond;
use stdClass;

/**
 * Controller for Opportunity entity.
 * Extends base CRM controller with funnel-aware reporting and Kanban aggregation.
 */
class Opportunity extends CrmOpportunity
{

    /**
     * GET Opportunity/action/reportByStage
     *
     * Override to support funnel-based filtering.
     * If funnelId is provided, filter opportunities by funnel.
     *
     * @throws BadRequest
     * @throws Forbidden
     */
    public function getActionReportByStage(Request $request): stdClass
    {
        if (!$this->acl->checkScope(OpportunityEntity::ENTITY_TYPE)) {
            throw new Forbidden();
        }

        $funnelId = $request->getQueryParam('funnelId');

        // If no funnel specified, use parent implementation
        if (!$funnelId) {
            return parent::getActionReportByStage($request);
        }

        $dateFrom = $request->getQueryParam('dateFrom');
        $dateTo = $request->getQueryParam('dateTo');
        $dateFilter = $request->getQueryParam('dateFilter');

        if (!$dateFilter) {
            throw new BadRequest("No `dateFilter` parameter.");
        }

        return $this->buildFunnelStageReport($funnelId, $dateFilter, $dateFrom, $dateTo);
    }

    /**
     * GET Opportunity/action/reportByLeadSource
     *
     * Override to support funnel-based filtering.
     * If funnelId is provided, filter opportunities by funnel.
     *
     * @throws BadRequest
     * @throws Forbidden
     */
    public function getActionReportByLeadSource(Request $request): stdClass
    {
        if (!$this->acl->checkScope(OpportunityEntity::ENTITY_TYPE)) {
            throw new Forbidden();
        }

        $funnelId = $request->getQueryParam('funnelId');

        // If no funnel specified, use parent implementation
        if (!$funnelId) {
            return parent::getActionReportByLeadSource($request);
        }

        $dateFrom = $request->getQueryParam('dateFrom');
        $dateTo = $request->getQueryParam('dateTo');
        $dateFilter = $request->getQueryParam('dateFilter');

        if (!$dateFilter) {
            throw new BadRequest("No `dateFilter` parameter.");
        }

        return $this->buildFunnelLeadSourceReport($funnelId, $dateFilter, $dateFrom, $dateTo);
    }

    /**
     * GET Opportunity/action/reportSalesByMonth
     *
     * Override to support funnel-based filtering.
     * If funnelId is provided, filter opportunities by funnel.
     *
     * @throws BadRequest
     * @throws Forbidden
     */
    public function getActionReportSalesByMonth(Request $request): stdClass
    {
        if (!$this->acl->checkScope(OpportunityEntity::ENTITY_TYPE)) {
            throw new Forbidden();
        }

        $funnelId = $request->getQueryParam('funnelId');

        // If no funnel specified, use parent implementation
        if (!$funnelId) {
            return parent::getActionReportSalesByMonth($request);
        }

        $dateFrom = $request->getQueryParam('dateFrom');
        $dateTo = $request->getQueryParam('dateTo');
        $dateFilter = $request->getQueryParam('dateFilter');

        if (!$dateFilter) {
            throw new BadRequest("No `dateFilter` parameter.");
        }

        return $this->buildFunnelSalesByMonthReport($funnelId, $dateFilter, $dateFrom, $dateTo);
    }

    /**
     * GET Opportunity/action/reportSalesPipeline
     *
     * Override to support funnel-based filtering.
     * If funnelId is provided, filter opportunities by funnel.
     *
     * @throws BadRequest
     * @throws Forbidden
     */
    public function getActionReportSalesPipeline(Request $request): stdClass
    {
        if (!$this->acl->checkScope(OpportunityEntity::ENTITY_TYPE)) {
            throw new Forbidden();
        }

        $funnelId = $request->getQueryParam('funnelId');

        // If no funnel specified, use parent implementation
        if (!$funnelId) {
            return parent::getActionReportSalesPipeline($request);
        }

        $dateFrom = $request->getQueryParam('dateFrom');
        $dateTo = $request->getQueryParam('dateTo');
        $dateFilter = $request->getQueryParam('dateFilter');
        $useLastStage = $request->getQueryParam('useLastStage') === 'true';

        if (!$dateFilter) {
            throw new BadRequest("No `dateFilter` parameter.");
        }

        return $this->buildFunnelSalesPipelineReport(
            $funnelId,
            $dateFilter,
            $dateFrom,
            $dateTo,
            $useLastStage
        );
    }

    /**
     * GET Opportunity/action/reportByOpportunityStage
     *
     * Returns open opportunities grouped by OpportunityStage.
     * Supports optional funnel filtering.
     *
     * @throws BadRequest
     * @throws Forbidden
     */
    public function getActionReportByOpportunityStage(Request $request): stdClass
    {
        if (!$this->acl->checkScope(OpportunityEntity::ENTITY_TYPE)) {
            throw new Forbidden();
        }

        $dateFrom = $request->getQueryParam('dateFrom');
        $dateTo = $request->getQueryParam('dateTo');
        $dateFilter = $request->getQueryParam('dateFilter');
        $funnelId = $request->getQueryParam('funnelId');

        if (!$dateFilter) {
            throw new BadRequest("No `dateFilter` parameter.");
        }

        return $this->buildOpportunityStageAmountReport($dateFilter, $dateFrom, $dateTo, $funnelId);
    }

    /**
     * GET Opportunity/action/reportSalesPipelineByOpportunityStage
     *
     * Returns pipeline data grouped by OpportunityStage.
     * Supports optional funnel and team filtering.
     *
     * @throws BadRequest
     * @throws Forbidden
     */
    public function getActionReportSalesPipelineByOpportunityStage(Request $request): stdClass
    {
        if (!$this->acl->checkScope(OpportunityEntity::ENTITY_TYPE)) {
            throw new Forbidden();
        }

        $dateFrom = $request->getQueryParam('dateFrom');
        $dateTo = $request->getQueryParam('dateTo');
        $dateFilter = $request->getQueryParam('dateFilter');
        $funnelId = $request->getQueryParam('funnelId');
        $teamId = $request->getQueryParam('teamId');

        if (!$dateFilter) {
            throw new BadRequest("No `dateFilter` parameter.");
        }

        return $this->buildOpportunityStagePipelineReport($dateFilter, $dateFrom, $dateTo, $funnelId, $teamId);
    }

    /**
     * GET Opportunity/action/kanbanAggregates
     *
     * Returns aggregated count and sum of amounts for each opportunity stage
     * within a specific funnel. Respects ACL restrictions.
     *
     * Query params:
     * - funnelId (required): The funnel ID to filter opportunities
     *
     * @throws BadRequest
     * @throws Forbidden
     */
    public function getActionKanbanAggregates(Request $request): stdClass
    {
        // Check scope-level read access
        if (!$this->acl->check('Opportunity', 'read')) {
            throw new Forbidden();
        }

        $funnelId = $request->getQueryParam('funnelId');

        if (!$funnelId) {
            throw new BadRequest("Parameter 'funnelId' is required.");
        }

        // Build a base query with ACL restrictions applied
        $selectBuilderFactory = $this->injectableFactory->create(SelectBuilderFactory::class);
        $baseQuery = $selectBuilderFactory
            ->create()
            ->from('Opportunity')
            ->withStrictAccessControl()  // This applies team/owner/portal ACL filters
            ->build();

        // Clone the query and add funnel filter + aggregation
        $queryBuilder = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->clone($baseQuery)
            ->select([
                'opportunityStageId',
                ['COUNT:(id)', 'count'],
                ['SUM:(amount)', 'amountSum'],
            ])
            ->where(['funnelId' => $funnelId])
            ->group('opportunityStageId');

        $query = $queryBuilder->build();

        // Execute the query
        $sth = $this->entityManager
            ->getQueryExecutor()
            ->execute($query);

        $rows = $sth->fetchAll(\PDO::FETCH_ASSOC);

        // Build result object with stage ID as key
        $aggregates = new stdClass();

        foreach ($rows as $row) {
            $stageId = $row['opportunityStageId'];
            if ($stageId) {
                $aggregates->$stageId = (object) [
                    'count' => (int) ($row['count'] ?? 0),
                    'amountSum' => (float) ($row['amountSum'] ?? 0),
                ];
            }
        }

        return (object) [
            'funnelId' => $funnelId,
            'aggregates' => $aggregates,
        ];
    }

    /**
     * Build report aggregated by stage for a specific funnel
     *
     * @throws BadRequest
     */
    private function buildFunnelStageReport(
        string $funnelId,
        string $dateFilter,
        ?string $dateFrom,
        ?string $dateTo
    ): stdClass {
        $selectBuilderFactory = $this->injectableFactory->create(SelectBuilderFactory::class);
        $baseQuery = $selectBuilderFactory
            ->create()
            ->from('Opportunity')
            ->withStrictAccessControl()
            ->build();

        $where = ['funnelId' => $funnelId];
        $this->applyDateFilter($where, $dateFilter, $dateFrom, $dateTo);

        $queryBuilder = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->clone($baseQuery)
            ->select([
                'opportunityStageId',
                ['COUNT:(id)', 'count'],
                ['SUM:(amount)', 'amountSum'],
            ])
            ->where($where)
            ->group('opportunityStageId');

        $sth = $this->entityManager
            ->getQueryExecutor()
            ->execute($queryBuilder->build());

        $rows = $sth->fetchAll(\PDO::FETCH_ASSOC);

        $dataList = [];
        foreach ($rows as $row) {
            if ($row['opportunityStageId']) {
                $dataList[] = (object) [
                    'stage' => $row['opportunityStageId'],
                    'value' => (float) ($row['amountSum'] ?? 0),
                    'count' => (int) ($row['count'] ?? 0),
                ];
            }
        }

        return (object) [
            'dataList' => $dataList,
            'funnelId' => $funnelId,
        ];
    }

    /**
     * Build report aggregated by lead source for a specific funnel
     *
     * @throws BadRequest
     */
    private function buildFunnelLeadSourceReport(
        string $funnelId,
        string $dateFilter,
        ?string $dateFrom,
        ?string $dateTo
    ): stdClass {
        $selectBuilderFactory = $this->injectableFactory->create(SelectBuilderFactory::class);
        $baseQuery = $selectBuilderFactory
            ->create()
            ->from('Opportunity')
            ->withStrictAccessControl()
            ->build();

        $where = ['funnelId' => $funnelId];
        $this->applyDateFilter($where, $dateFilter, $dateFrom, $dateTo);

        $queryBuilder = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->clone($baseQuery)
            ->select([
                'leadSourceId',
                ['COUNT:(id)', 'count'],
                ['SUM:(amount)', 'amountSum'],
            ])
            ->where($where)
            ->group('leadSourceId');

        $sth = $this->entityManager
            ->getQueryExecutor()
            ->execute($queryBuilder->build());

        $rows = $sth->fetchAll(\PDO::FETCH_ASSOC);

        $dataList = [];
        foreach ($rows as $row) {
            if ($row['leadSourceId']) {
                $dataList[] = (object) [
                    'leadSource' => $row['leadSourceId'],
                    'value' => (float) ($row['amountSum'] ?? 0),
                    'count' => (int) ($row['count'] ?? 0),
                ];
            }
        }

        return (object) [
            'dataList' => $dataList,
            'funnelId' => $funnelId,
        ];
    }

    /**
     * Build sales by month report for a specific funnel
     *
     * @throws BadRequest
     */
    private function buildFunnelSalesByMonthReport(
        string $funnelId,
        string $dateFilter,
        ?string $dateFrom,
        ?string $dateTo
    ): stdClass {
        $selectBuilderFactory = $this->injectableFactory->create(SelectBuilderFactory::class);
        $baseQuery = $selectBuilderFactory
            ->create()
            ->from('Opportunity')
            ->withStrictAccessControl()
            ->build();

        $where = [
            'funnelId' => $funnelId,
            'status' => 'Won',
        ];
        $this->applyDateFilter($where, $dateFilter, $dateFrom, $dateTo);

        $queryBuilder = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->clone($baseQuery)
            ->select([
                ['DATE_FORMAT:(closeDate, \'%Y-%m\')', 'month'],
                ['SUM:(amount)', 'amount'],
            ])
            ->where($where)
            ->group('month')
            ->order('month', 'ASC');

        $sth = $this->entityManager
            ->getQueryExecutor()
            ->execute($queryBuilder->build());

        $rows = $sth->fetchAll(\PDO::FETCH_ASSOC);

        $dataList = [];
        foreach ($rows as $row) {
            if ($row['month']) {
                $dataList[] = (object) [
                    'month' => $row['month'],
                    'value' => (float) ($row['amount'] ?? 0),
                ];
            }
        }

        return (object) [
            'dataList' => $dataList,
            'funnelId' => $funnelId,
        ];
    }

    /**
     * Build sales pipeline report for a specific funnel
     *
     * @throws BadRequest
     */
    private function buildFunnelSalesPipelineReport(
        string $funnelId,
        string $dateFilter,
        ?string $dateFrom,
        ?string $dateTo,
        bool $useLastStage = false
    ): stdClass {
        $selectBuilderFactory = $this->injectableFactory->create(SelectBuilderFactory::class);
        $baseQuery = $selectBuilderFactory
            ->create()
            ->from('Opportunity')
            ->withStrictAccessControl()
            ->build();

        $where = ['funnelId' => $funnelId];
        $this->applyDateFilter($where, $dateFilter, $dateFrom, $dateTo);

        // Exclude lost opportunities from pipeline (exclude probability = 0)
        $where['status!='] = 'Lost';

        $queryBuilder = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->clone($baseQuery)
            ->select([
                'opportunityStageId',
                ['COUNT:(id)', 'count'],
                ['SUM:(amount)', 'amountSum'],
                ['SUM:(amountWeightedConverted)', 'amountWeightedSum'],
            ])
            ->where($where)
            ->group('opportunityStageId');

        $sth = $this->entityManager
            ->getQueryExecutor()
            ->execute($queryBuilder->build());

        $rows = $sth->fetchAll(\PDO::FETCH_ASSOC);

        $dataList = [];
        foreach ($rows as $row) {
            if ($row['opportunityStageId']) {
                $dataList[] = (object) [
                    'stage' => $row['opportunityStageId'],
                    'value' => (float) ($row['amountSum'] ?? 0),
                    'valueWeighted' => (float) ($row['amountWeightedSum'] ?? 0),
                    'count' => (int) ($row['count'] ?? 0),
                ];
            }
        }

        return (object) [
            'dataList' => $dataList,
            'funnelId' => $funnelId,
            'useLastStage' => $useLastStage,
        ];
    }

    /**
     * Build amount report grouped by OpportunityStage.
     * Mirrors core "Opportunities by Stage" semantics by showing only open stages.
     *
     * @throws BadRequest
     */
    private function buildOpportunityStageAmountReport(
        string $dateFilter,
        ?string $dateFrom,
        ?string $dateTo,
        ?string $funnelId
    ): stdClass {
        $selectBuilderFactory = $this->injectableFactory->create(SelectBuilderFactory::class);
        $baseQuery = $selectBuilderFactory
            ->create()
            ->from('Opportunity')
            ->withStrictAccessControl()
            ->build();

        $conditionList = [
            Cond::equal(Cond::column('status'), 'Open'),
        ];

        if ($funnelId) {
            $conditionList[] = Cond::equal(Cond::column('funnelId'), $funnelId);
        }

        $conditionList[] = Cond::or(
            $this->buildCloseDateFilterCondition($dateFilter, $dateFrom, $dateTo),
            Cond::equal(Cond::column('closeDate'), null)
        );

        $queryBuilder = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->clone($baseQuery)
            ->select([
                'opportunityStageId',
                'opportunityStageName',
                ['SUM:(amountConverted)', 'amountSum'],
                ['COUNT:(id)', 'count'],
            ])
            ->where(Cond::and(...$conditionList))
            ->group('opportunityStageId');

        $sth = $this->entityManager
            ->getQueryExecutor()
            ->execute($queryBuilder->build());

        $rows = $sth->fetchAll(\PDO::FETCH_ASSOC);

        $dataMap = [];

        foreach ($rows as $row) {
            $stageId = $row['opportunityStageId'] ?? null;

            if (!$stageId) {
                continue;
            }

            $dataMap[$stageId] = [
                'stageId' => $stageId,
                'stageName' => $row['opportunityStageName'] ?? null,
                'value' => (float) ($row['amountSum'] ?? 0),
                'count' => (int) ($row['count'] ?? 0),
            ];
        }

        $stageList = $this->getOpportunityStageList($funnelId, array_keys($dataMap));
        $dataList = [];

        foreach ($stageList as $stage) {
            if ($stage['probability'] <= 0 || $stage['probability'] >= 100) {
                continue;
            }

            $row = $dataMap[$stage['id']] ?? null;

            if (!$row && !$funnelId) {
                continue;
            }

            $dataList[] = (object) [
                'stageId' => $stage['id'],
                'stageName' => $stage['name'],
                'style' => $stage['style'],
                'probability' => $stage['probability'],
                'value' => $row['value'] ?? 0.0,
                'count' => $row['count'] ?? 0,
            ];
        }

        return (object) [
            'dataList' => $dataList,
            'funnelId' => $funnelId,
        ];
    }

    /**
     * Build sales pipeline report grouped by OpportunityStage.
     * Mirrors core pipeline semantics by excluding lost stages only.
     *
     * @throws BadRequest
     */
    private function buildOpportunityStagePipelineReport(
        string $dateFilter,
        ?string $dateFrom,
        ?string $dateTo,
        ?string $funnelId,
        ?string $teamId
    ): stdClass {
        $selectBuilderFactory = $this->injectableFactory->create(SelectBuilderFactory::class);
        $baseQuery = $selectBuilderFactory
            ->create()
            ->from('Opportunity')
            ->withStrictAccessControl()
            ->build();

        $conditionList = [
            Cond::notEqual(Cond::column('status'), 'Lost'),
        ];

        if ($funnelId) {
            $conditionList[] = Cond::equal(Cond::column('funnelId'), $funnelId);
        }

        if ($teamId) {
            $conditionList[] = Cond::equal(Cond::column('teamsFilter.id'), $teamId);
        }

        $conditionList[] = Cond::or(
            $this->buildCloseDateFilterCondition($dateFilter, $dateFrom, $dateTo),
            Cond::and(
                Cond::equal(Cond::column('status'), 'Open'),
                Cond::equal(Cond::column('closeDate'), null)
            )
        );

        $queryBuilder = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->clone($baseQuery)
            ->select([
                'opportunityStageId',
                'opportunityStageName',
                ['SUM:(amountConverted)', 'amountSum'],
                ['SUM:(amountWeightedConverted)', 'amountWeightedSum'],
                ['COUNT:(id)', 'count'],
            ])
            ->group('opportunityStageId');

        if ($teamId) {
            $queryBuilder->join('teams', 'teamsFilter');
        }

        $queryBuilder->where(Cond::and(...$conditionList));

        $sth = $this->entityManager
            ->getQueryExecutor()
            ->execute($queryBuilder->build());

        $rows = $sth->fetchAll(\PDO::FETCH_ASSOC);

        $dataMap = [];

        foreach ($rows as $row) {
            $stageId = $row['opportunityStageId'] ?? null;

            if (!$stageId) {
                continue;
            }

            $dataMap[$stageId] = [
                'stageId' => $stageId,
                'stageName' => $row['opportunityStageName'] ?? null,
                'value' => (float) ($row['amountSum'] ?? 0),
                'valueWeighted' => (float) ($row['amountWeightedSum'] ?? 0),
                'count' => (int) ($row['count'] ?? 0),
            ];
        }

        $stageList = $this->getOpportunityStageList($funnelId, array_keys($dataMap));
        $dataList = [];

        foreach ($stageList as $stage) {
            if ($stage['probability'] === 0) {
                continue;
            }

            $row = $dataMap[$stage['id']] ?? null;

            if (!$row && !$funnelId) {
                continue;
            }

            $dataList[] = (object) [
                'stageId' => $stage['id'],
                'stageName' => $stage['name'],
                'style' => $stage['style'],
                'probability' => $stage['probability'],
                'value' => $row['value'] ?? 0.0,
                'valueWeighted' => $row['valueWeighted'] ?? 0.0,
                'count' => $row['count'] ?? 0,
            ];
        }

        return (object) [
            'dataList' => $dataList,
            'funnelId' => $funnelId,
        ];
    }

    /**
     * Get accessible OpportunityStage rows ordered for reporting.
     * When a funnel is provided, returns all active stages in funnel order.
     * Otherwise, returns only the provided stage IDs in name order.
     *
     * @return array<int, array{id: string, name: string, style: string, probability: int}>
     */
    private function getOpportunityStageList(?string $funnelId, array $stageIdList): array
    {
        $selectBuilderFactory = $this->injectableFactory->create(SelectBuilderFactory::class);
        $baseQuery = $selectBuilderFactory
            ->create()
            ->from('OpportunityStage')
            ->withStrictAccessControl()
            ->build();

        $where = [
            'isActive' => true,
        ];

        if ($funnelId) {
            $where['funnelId'] = $funnelId;
        } elseif (!$stageIdList) {
            return [];
        } else {
            $where['id'] = $stageIdList;
        }

        $queryBuilder = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->clone($baseQuery)
            ->where($where);

        if ($funnelId) {
            $queryBuilder
                ->order('order', 'ASC')
                ->order('name', 'ASC');
        } else {
            $queryBuilder->order('name', 'ASC');
        }

        $stages = $this->entityManager
            ->getRDBRepository('OpportunityStage')
            ->clone($queryBuilder->build())
            ->find();

        $list = [];

        foreach ($stages as $stage) {
            $list[] = [
                'id' => $stage->getId(),
                'name' => $stage->get('name'),
                'style' => $stage->get('style') ?: 'default',
                'probability' => (int) $stage->get('probability'),
            ];
        }

        return $list;
    }

    /**
     * Apply date filter conditions to where clause
     */
    private function applyDateFilter(
        array &$where,
        string $dateFilter,
        ?string $dateFrom,
        ?string $dateTo
    ): void {
        $dateField = 'closeDate';

        switch ($dateFilter) {
            case 'currentYear':
                $where[$dateField . '>='] = date('Y') . '-01-01';
                $where[$dateField . '<='] = date('Y') . '-12-31';
                break;

            case 'currentQuarter':
                $quarter = ceil(date('n') / 3);
                $year = date('Y');
                $startMonth = ($quarter - 1) * 3 + 1;
                $endMonth = $quarter * 3;
                $where[$dateField . '>='] = sprintf('%d-%02d-01', $year, $startMonth);
                $where[$dateField . '<='] = date('Y-m-t', strtotime("$year-$endMonth-01"));
                break;

            case 'currentMonth':
                $where[$dateField . '>='] = date('Y-m-01');
                $where[$dateField . '<='] = date('Y-m-t');
                break;

            case 'currentFiscalYear':
                // Assuming fiscal year starts in January, adjust as needed
                $fiscalYearStart = date('Y') . '-01-01';
                $where[$dateField . '>='] = $fiscalYearStart;
                break;

            case 'between':
                if ($dateFrom) {
                    $where[$dateField . '>='] = $dateFrom;
                }
                if ($dateTo) {
                    $where[$dateField . '<='] = $dateTo;
                }
                break;

            default:
                throw new BadRequest("Invalid dateFilter: $dateFilter");
        }
    }

    private function buildCloseDateFilterCondition(
        string $dateFilter,
        ?string $dateFrom,
        ?string $dateTo
    ) {
        $dateField = Cond::column('closeDate');

        switch ($dateFilter) {
            case 'currentYear':
                return Cond::and(
                    Cond::greaterOrEqual($dateField, date('Y') . '-01-01'),
                    Cond::lessOrEqual($dateField, date('Y') . '-12-31')
                );

            case 'currentQuarter':
            case 'currentFiscalQuarter':
                $quarter = (int) ceil((int) date('n') / 3);
                $year = date('Y');
                $startMonth = ($quarter - 1) * 3 + 1;
                $endMonth = $quarter * 3;

                return Cond::and(
                    Cond::greaterOrEqual($dateField, sprintf('%d-%02d-01', $year, $startMonth)),
                    Cond::lessOrEqual($dateField, date('Y-m-t', strtotime("$year-$endMonth-01")))
                );

            case 'currentMonth':
                return Cond::and(
                    Cond::greaterOrEqual($dateField, date('Y-m-01')),
                    Cond::lessOrEqual($dateField, date('Y-m-t'))
                );

            case 'currentFiscalYear':
                return Cond::greaterOrEqual($dateField, date('Y') . '-01-01');

            case 'between':
                $conditionList = [];

                if ($dateFrom) {
                    $conditionList[] = Cond::greaterOrEqual($dateField, $dateFrom);
                }

                if ($dateTo) {
                    $conditionList[] = Cond::lessOrEqual($dateField, $dateTo);
                }

                if (!$conditionList) {
                    throw new BadRequest("No `dateFrom` or `dateTo` parameter.");
                }

                if (count($conditionList) === 1) {
                    return $conditionList[0];
                }

                return Cond::and(...$conditionList);

            case 'ever':
                return Cond::notEqual($dateField, null);

            default:
                throw new BadRequest("Invalid dateFilter: $dateFilter");
        }
    }
}
