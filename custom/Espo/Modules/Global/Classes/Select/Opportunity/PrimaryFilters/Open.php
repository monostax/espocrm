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

namespace Espo\Modules\Global\Classes\Select\Opportunity\PrimaryFilters;

use Espo\Core\Select\Primary\Filter;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\Query\Part\Condition as Cond;

/**
 * Primary filter for Open opportunities.
 * Matches status = 'Open' or (probability > 0 AND probability < 100)
 */
class Open implements Filter
{
    public function apply(SelectBuilder $queryBuilder): void
    {
        $queryBuilder->where(
            Cond::and(
                Cond::greater(
                    Cond::column('probability'),
                    0
                ),
                Cond::less(
                    Cond::column('probability'),
                    100
                )
            )
        );
    }
}
