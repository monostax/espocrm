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

namespace Espo\Modules\Global\Classes\Select\Funnel\PrimaryFilters;

use Espo\Core\Select\Primary\Filter;
use Espo\ORM\Query\SelectBuilder as QueryBuilder;

/**
 * Primary filter to show only active Funnels.
 *
 * @noinspection PhpUnused
 */
class Active implements Filter
{
    public function apply(QueryBuilder $queryBuilder): void
    {
        $queryBuilder->where(['isActive' => true]);
    }
}

