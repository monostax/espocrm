<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Classes\Select\Opportunity\Where;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Select\Where\Item;
use Espo\Core\Select\Where\ItemConverter;
use Espo\Modules\Chatwoot\Services\OpportunityReadStateService;
use Espo\ORM\Query\Part\WhereClause;
use Espo\ORM\Query\Part\WhereItem;
use Espo\ORM\Query\SelectBuilder;

/** Intersect a personal read group with the normal list scope, before pagination. */
class ReadGroup implements ItemConverter
{
    public function __construct(private OpportunityReadStateService $readStates) {}

    public function convert(SelectBuilder $queryBuilder, Item $item): WhereItem
    {
        $group = $item->getValue();
        if (!in_array($group, ['read', 'unread'], true)) {
            throw new BadRequest('Invalid opportunity read group.');
        }
        $unread = SelectBuilder::create()->from('Opportunity')->select('id');
        $this->readStates->applyListFilter($unread, onlyUnread: true);

        // Outer list ACL/search/mentions still apply, including for the complement.
        return WhereClause::fromRaw([
            $group === 'unread' ? 'id=s' : 'id!=s' => $unread->build(),
        ]);
    }
}
