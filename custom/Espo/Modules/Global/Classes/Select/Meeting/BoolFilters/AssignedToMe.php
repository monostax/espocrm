<?php

namespace Espo\Modules\Global\Classes\Select\Meeting\BoolFilters;

use Espo\Core\Select\Bool\Filter;
use Espo\Entities\User;
use Espo\ORM\Query\SelectBuilder as QueryBuilder;
use Espo\ORM\Query\Part\Where\OrGroupBuilder;
use Espo\ORM\Query\Part\Condition as Cond;

class AssignedToMe implements Filter
{
    public function __construct(private User $user)
    {}

    public function apply(QueryBuilder $queryBuilder, OrGroupBuilder $orGroupBuilder): void
    {
        $orGroupBuilder->add(
            Cond::equal(
                Cond::column('assignedUserId'),
                $this->user->getId()
            )
        );
    }
}

