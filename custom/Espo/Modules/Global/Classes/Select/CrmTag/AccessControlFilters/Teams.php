<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Classes\Select\CrmTag\AccessControlFilters;

use Espo\Core\Select\AccessControl\Filter;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Query\SelectBuilder;

class Teams implements Filter
{
    public function __construct(private User $user, private UserTenantResolver $tenants) {}

    public function apply(SelectBuilder $queryBuilder): void
    {
        $personal = [
            'visibility' => 'personal',
            'ownerUserId' => $this->user->getId(),
            'tenantId' => $this->tenants->resolveTenantIds($this->user),
        ];
        $shared = ['OR' => [['visibility' => 'team'], ['visibility' => null]]];
        if ($this->user->isAdmin()) {
            $queryBuilder->where(['OR' => [$personal, $shared]]);
            return;
        }
        $teams = SelectBuilder::create()->from(Team::RELATIONSHIP_ENTITY_TEAM)->select('entityId')
            ->where(['entityType' => 'CrmTag', 'teamId' => $this->user->getTeamIdList(), 'deleted' => false])->build();
        $queryBuilder->where(['OR' => [$personal, ['AND' => [$shared, ['id=s' => $teams]]]]]);
    }
}
