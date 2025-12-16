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

namespace Espo\Modules\Global\Classes\Select\OpportunityStage\BoolFilters;

use Espo\Core\Select\Bool\Filter;
use Espo\ORM\Query\SelectBuilder as QueryBuilder;

/**
 * Bool filter to show only active OpportunityStages.
 *
 * @noinspection PhpUnused
 */
class OnlyActive implements Filter
{
    public function apply(QueryBuilder $queryBuilder, ...$arguments): void
    {
        $queryBuilder->where(['isActive' => true]);
    }
}

