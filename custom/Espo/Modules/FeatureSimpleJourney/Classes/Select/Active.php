<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureSimpleJourney\Classes\Select;

use Espo\Core\Select\Primary\Filter;
use Espo\ORM\Query\SelectBuilder;

class Active implements Filter
{
    public function apply(SelectBuilder $queryBuilder): void
    {
        $queryBuilder->where(['isActive' => true]);
    }
}
