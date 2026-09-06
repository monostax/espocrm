<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Classes\Select\Note;

use Espo\Core\Select\Primary\Filter;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\OpportunityStreamEvents;
use Espo\Modules\Chatwoot\Tools\Stream\OpportunityEventAccess;
use Espo\ORM\Query\SelectBuilder;

/** One preview per opportunity; a busy conversation must not starve other cards. */
class LatestOpportunityEntry implements Filter
{
    public function __construct(private User $user, private OpportunityEventAccess $access) {}

    public function apply(SelectBuilder $queryBuilder): void
    {
        $latest = SelectBuilder::create()->from('Note', 'latestEntry')
            ->select([['MAX:number', 'lastNumber']])->where([
                'parentType' => 'Opportunity',
                'parentId:' => 'note.parentId',
                'type' => OpportunityStreamEvents::TYPES,
            ])->where($this->access->where($this->user))->build();

        $queryBuilder->where(['parentType' => 'Opportunity', 'number=s' => $latest]);
    }
}
