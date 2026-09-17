<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Classes\Select\Opportunity;

use Espo\Core\Select\Applier\AdditionalApplier;
use Espo\Core\Select\SearchParams;
use Espo\Modules\Chatwoot\Classes\Select\Note\LatestOpportunityEntry;
use Espo\ORM\Query\SelectBuilder;

/** Rank by the same accessible entry as the card preview, before pagination. */
class StreamActivity implements AdditionalApplier
{
    public function __construct(private LatestOpportunityEntry $latestEntry) {}

    public function apply(SelectBuilder $queryBuilder, SearchParams $searchParams): void
    {
        if ($searchParams->getOrderBy() !== 'chatwootStreamUpdatedAt') {
            return;
        }

        $queryBuilder->leftJoin('Note', 'chatwootLatestEntry', [
            'parentType' => 'Opportunity',
            'parentId:' => 'opportunity.id',
            'number=s' => $this->latestEntry->latestNumber('opportunity.id'),
            'deleted' => false,
        ]);

        // Preserve the normal record projection when no explicit select was requested.
        if (!$queryBuilder->build()->getSelect()) {
            $queryBuilder->select('*');
        }
        $date = 'COALESCE:(chatwootLatestEntry.createdAt,modifiedAt,createdAt)';
        $queryBuilder->select($date, 'chatwootStreamUpdatedAt')->order([
            [$date, $searchParams->getOrder() ?? 'DESC'],
            ['id', 'ASC'],
        ]);
    }
}
