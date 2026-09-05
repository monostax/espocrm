<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Classes\Select\Opportunity\PrimaryFilters;

use Espo\Core\Select\Primary\Filter;
use Espo\Modules\Chatwoot\Services\OpportunityReadStateService;
use Espo\ORM\Query\SelectBuilder;

class Unread implements Filter
{
    public function __construct(private OpportunityReadStateService $service) {}

    public function apply(SelectBuilder $queryBuilder): void
    {
        $this->service->applyListFilter($queryBuilder, onlyUnread: true);
    }
}
