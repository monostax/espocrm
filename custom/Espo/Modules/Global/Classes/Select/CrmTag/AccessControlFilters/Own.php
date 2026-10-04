<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Classes\Select\CrmTag\AccessControlFilters;

use Espo\Core\Select\AccessControl\Filter;
use Espo\Entities\User;
use Espo\ORM\Query\SelectBuilder;

class Own implements Filter
{
    public function __construct(private User $user) {}

    public function apply(SelectBuilder $queryBuilder): void
    {
        $queryBuilder->where(['visibility' => 'personal', 'ownerUserId' => $this->user->getId()]);
    }
}
