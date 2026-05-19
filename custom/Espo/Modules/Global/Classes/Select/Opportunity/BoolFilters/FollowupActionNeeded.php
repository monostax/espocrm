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

namespace Espo\Modules\Global\Classes\Select\Opportunity\BoolFilters;

use Espo\Core\Select\Bool\Filter;
use Espo\ORM\Query\SelectBuilder as QueryBuilder;
use Espo\ORM\Query\Part\Where\OrGroupBuilder;
use Espo\ORM\Query\Part\Condition as Cond;

/**
 * Bool filter for opportunities awaiting a human/AI action.
 * Matches followupStatus = 'ActionNeeded'.
 *
 * @noinspection PhpUnused
 */
class FollowupActionNeeded implements Filter
{
    public function apply(QueryBuilder $queryBuilder, OrGroupBuilder $orGroupBuilder): void
    {
        $orGroupBuilder->add(
            Cond::equal(Cond::column('followupStatus'), 'ActionNeeded')
        );
    }
}
