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
            'stage' => 'Closed Won',
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

        // Exclude lost opportunities from pipeline
        $where['stage!='] = 'Closed Lost';

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
}
