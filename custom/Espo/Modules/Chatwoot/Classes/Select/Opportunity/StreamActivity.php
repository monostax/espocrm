<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Classes\Select\Opportunity;

use Espo\Core\Select\Applier\AdditionalApplier;
use Espo\Core\Select\SearchParams;
use Espo\Modules\Chatwoot\Classes\Select\Note\LatestOpportunityEntry;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\Query\Part\Expression as Expr;

/**
 * Rank by the latest accessible mural entry or conversation message, before
 * pagination. chatwootLastMessageAt lets the client re-rank as previews change.
 */
class StreamActivity implements AdditionalApplier
{
    public function __construct(private LatestOpportunityEntry $latestEntry) {}

    public function apply(SelectBuilder $queryBuilder, SearchParams $searchParams): void
    {
        if ($searchParams->getOrderBy() !== 'chatwootStreamUpdatedAt') {
            return;
        }

        // Do not clone the list's unread/mention filters into this aggregate: that
        // would execute their derived tables a second time just to sort the page.
        $queryBuilder->leftJoin($this->latestEntry->latestNumbers(), 'chatwootLatestNumber',
            Expr::equal(Expr::alias('chatwootLatestNumber.parentId'), Expr::column('id')));
        $queryBuilder->leftJoin('Note', 'chatwootLatestEntry', Expr::and(
            Expr::equal(Expr::column('chatwootLatestEntry.parentType'), 'Opportunity'),
            Expr::equal(Expr::column('chatwootLatestEntry.parentId'), Expr::column('id')),
            Expr::equal(Expr::column('chatwootLatestEntry.number'), Expr::alias('chatwootLatestNumber.lastNumber')),
            Expr::equal(Expr::column('chatwootLatestEntry.deleted'), false),
        ));

        $latest = Expr::column('chatwootLatestEntry.createdAt');
        $message = Expr::value(null);
        $messages = $this->latestEntry->latestMessages();
        if ($messages) {
            // The tenant match keeps a cross-tenant link from ever setting the date.
            $queryBuilder->leftJoin($messages, 'chatwootLatestMessage', Expr::and(
                Expr::equal(Expr::alias('chatwootLatestMessage.opportunityId'), Expr::column('id')),
                Expr::equal(Expr::alias('chatwootLatestMessage.tenantId'), Expr::column('tenantId')),
            ));
            $message = Expr::alias('chatwootLatestMessage.lastMessageAt');
            $latest = Expr::greatest(Expr::coalesce($latest, $message), Expr::coalesce($message, $latest));
        }

        // Preserve the normal record projection when no explicit select was requested.
        if (!$queryBuilder->build()->getSelect()) {
            $queryBuilder->select('*');
        }
        $date = Expr::coalesce($latest, Expr::column('modifiedAt'), Expr::column('createdAt'));
        $queryBuilder->select($date, 'chatwootStreamUpdatedAt')
            ->select($message, 'chatwootLastMessageAt')
            ->order([
                [$date->getValue(), $searchParams->getOrder() ?? 'DESC'],
                ['id', 'ASC'],
            ]);
    }
}
