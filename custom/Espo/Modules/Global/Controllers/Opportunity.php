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
use Espo\Core\Templates\Controllers\Base;
use stdClass;

/**
 * Controller for Opportunity entity.
 * Provides additional API endpoints for Kanban aggregation.
 */
class Opportunity extends Base
{
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
        // Check scope-level read access (uses inherited $this->acl)
        if (!$this->acl->check('Opportunity', 'read')) {
            throw new Forbidden();
        }

        $funnelId = $request->getQueryParam('funnelId');

        if (!$funnelId) {
            throw new BadRequest("Parameter 'funnelId' is required.");
        }

        // Get SelectBuilderFactory via InjectableFactory (inherited from parent)
        $selectBuilderFactory = $this->injectableFactory->create(SelectBuilderFactory::class);

        // Build a base query with ACL restrictions applied
        $baseQuery = $selectBuilderFactory
            ->create()
            ->from('Opportunity')
            ->withStrictAccessControl()  // This applies team/owner/portal ACL filters
            ->build();

        // Clone the query and add funnel filter + aggregation
        // Use inherited $this->entityManager
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
}
