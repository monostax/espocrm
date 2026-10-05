<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Classes\Select\Opportunity\Where;

use Espo\Core\Select\Where\Item;
use Espo\Core\Select\Where\ItemConverter;
use Espo\Modules\Chatwoot\Services\OpportunityInboxFilter;
use Espo\ORM\Query\Part\WhereClause;
use Espo\ORM\Query\Part\WhereItem;
use Espo\ORM\Query\SelectBuilder;

class Inbox implements ItemConverter
{
    public function __construct(private OpportunityInboxFilter $inboxes) {}

    public function convert(SelectBuilder $queryBuilder, Item $item): WhereItem
    {
        return WhereClause::fromRaw(['id=s' => $this->inboxes->matchingIds($item->getValue())]);
    }
}
