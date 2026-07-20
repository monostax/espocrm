<?php

declare(strict_types=1);

/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Global\Classes\Select\CustomFieldDef\BoolFilters;

use Espo\Core\Select\Bool\Filter;
use Espo\ORM\Query\Part\Where\OrGroupBuilder;
use Espo\ORM\Query\SelectBuilder as QueryBuilder;

/** @noinspection PhpUnused */
class OnlyActive implements Filter
{
    public function apply(QueryBuilder $queryBuilder, OrGroupBuilder $orGroupBuilder): void
    {
        $queryBuilder->where(['isActive' => true]);
    }
}
