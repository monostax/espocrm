<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\Select\JourneyRecord\PrimaryFilters;

use Espo\Core\Select\Primary\Filter;
use Espo\Modules\FeatureJourney\Entities\JourneyRecord;
use Espo\ORM\Query\SelectBuilder as QueryBuilder;

class Failed implements Filter
{
    public function apply(QueryBuilder $queryBuilder): void
    {
        $queryBuilder->where([
            'status' => JourneyRecord::STATUS_FAILED,
        ]);
    }
}
