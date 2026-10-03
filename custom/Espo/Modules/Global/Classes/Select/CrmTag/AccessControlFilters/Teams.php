<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Classes\Select\CrmTag\AccessControlFilters;

use Espo\Core\Select\AccessControl\Filter;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\ORM\Query\SelectBuilder;

class Teams implements Filter
{
    public function __construct(private User $user) {}

    public function apply(SelectBuilder $queryBuilder): void
    {
        if ($this->user->isAdmin()) return;
        $teams = SelectBuilder::create()->from(Team::RELATIONSHIP_ENTITY_TEAM)->select('entityId')
            ->where(['entityType' => 'CrmTag', 'teamId' => $this->user->getTeamIdList(), 'deleted' => false])->build();
        $queryBuilder->where(['id=s' => $teams]);
    }
}
