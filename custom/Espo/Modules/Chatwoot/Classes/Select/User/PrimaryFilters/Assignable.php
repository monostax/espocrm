<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Classes\Select\User\PrimaryFilters;

use Espo\Core\Select\Primary\Filter;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Classes\Select\User\ManagedAgents;
use Espo\ORM\Query\Part\Condition as Cond;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\SelectBuilder;

/** Active people plus managed AI agents: who can own a record in owner pickers. */
class Assignable implements Filter
{
    public function apply(SelectBuilder $queryBuilder): void
    {
        $queryBuilder->where(['isActive' => true]);
        $queryBuilder->where(Cond::or(
            Cond::in(Expr::column('type'), [User::TYPE_REGULAR, User::TYPE_ADMIN, User::TYPE_SUPER_ADMIN]),
            ManagedAgents::idIn(),
        ));
    }
}
