<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Global\Classes\FieldProcessing\TargetList;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\FieldProcessing\Loader;
use Espo\Core\FieldProcessing\Loader\Params;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\Modules\Crm\Entities\Opportunity;
use Espo\Modules\Crm\Entities\TargetList;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PDO;

/**
 * Loads outbound-batch STATE metrics onto TargetList (notStorable fields):
 * opportunitiesCount, openOpportunitiesCount, wonOpportunitiesCount,
 * lostOpportunitiesCount, wonOpportunitiesAmount.
 *
 * Counts opportunities attributed to the list via the sourceTargetList link
 * (stamped once at creation — see Global's Opportunity entityDefs). One
 * aggregate GROUP BY query per record, mirroring the stock EntryCountLoader
 * pattern (Crm\Classes\FieldProcessing\TargetList).
 *
 * ACL / tenancy: the Opportunity query is built through SelectBuilderFactory
 * with strict access control FOR THE CURRENT USER, so the counts only cover
 * opportunities the requesting user can read (team/tenant isolation is
 * enforced by the same ACL filters as any Opportunity list view). No
 * Opportunity read access -> all metrics are zero.
 *
 * wonOpportunitiesAmount sums the raw `amount` column across currencies
 * (single-currency deployments assumed). FLOW metrics (reply rates, stage
 * velocity) intentionally live in the TrackingEvent ledger instead — see
 * FeatureTrackingEvent\Services\TargetListOutreachMetricsService.
 *
 * @implements Loader<TargetList>
 */
class OpportunityMetricsLoader implements Loader
{
    private const FIELD_LIST = [
        'opportunitiesCount',
        'openOpportunitiesCount',
        'wonOpportunitiesCount',
        'lostOpportunitiesCount',
        'wonOpportunitiesAmount',
    ];

    public function __construct(
        private EntityManager $entityManager,
        private SelectBuilderFactory $selectBuilderFactory,
        private User $user,
    ) {}

    public function process(Entity $entity, Params $params): void
    {
        if ($params->hasSelect() && !$this->isRequested($params)) {
            return;
        }

        $byStatus = $this->fetchAggregates($entity);

        $open = $byStatus['Open'] ?? ['count' => 0, 'amount' => 0.0];
        $won = $byStatus['Won'] ?? ['count' => 0, 'amount' => 0.0];
        $lost = $byStatus['Lost'] ?? ['count' => 0, 'amount' => 0.0];

        $total = 0;

        foreach ($byStatus as $item) {
            $total += $item['count'];
        }

        $entity->set('opportunitiesCount', $total);
        $entity->set('openOpportunitiesCount', $open['count']);
        $entity->set('wonOpportunitiesCount', $won['count']);
        $entity->set('lostOpportunitiesCount', $lost['count']);
        $entity->set('wonOpportunitiesAmount', $won['amount']);
    }

    private function isRequested(Params $params): bool
    {
        $select = $params->getSelect() ?? [];

        return array_intersect(self::FIELD_LIST, $select) !== [];
    }

    /**
     * @return array<string, array{count: int, amount: float}>
     */
    private function fetchAggregates(Entity $entity): array
    {
        try {
            $subQuery = $this->selectBuilderFactory
                ->create()
                ->from(Opportunity::ENTITY_TYPE)
                ->forUser($this->user)
                ->withStrictAccessControl()
                ->buildQueryBuilder()
                ->select(['id'])
                ->where(['sourceTargetListId' => $entity->getId()])
                ->build();
        } catch (Forbidden|BadRequest) {
            // No Opportunity read access for the current user.
            return [];
        }

        // Aggregate over the ACL-filtered id set (id=s subquery, mirroring
        // Crm\Tools\Opportunity\Report\Util) so access-control joins can
        // never duplicate rows and inflate COUNT/SUM.
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from(Opportunity::ENTITY_TYPE)
            ->select([
                'status',
                ['COUNT:id', 'cnt'],
                ['SUM:amount', 'amountSum'],
            ])
            ->where(['id=s' => $subQuery])
            ->group('status')
            ->build();

        $sth = $this->entityManager->getQueryExecutor()->execute($query);

        $result = [];

        foreach ($sth->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = (string) ($row['status'] ?? '');

            $result[$status] = [
                'count' => (int) ($row['cnt'] ?? 0),
                'amount' => (float) ($row['amountSum'] ?? 0.0),
            ];
        }

        return $result;
    }
}
