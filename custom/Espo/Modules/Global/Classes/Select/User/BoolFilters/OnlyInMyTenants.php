<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Classes\Select\User\BoolFilters;

use Espo\Core\Select\Bool\Filter;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\Global\Services\RunAsUserAccess;
use Espo\ORM\Name\Attribute;
use Espo\ORM\Query\Part\Condition;
use Espo\ORM\Query\Part\Expression;
use Espo\ORM\Query\Part\Where\OrGroupBuilder;
use Espo\ORM\Query\Part\WhereClause;
use Espo\ORM\Query\SelectBuilder;

/**
 * Restrict User select to members of Tenants the current user belongs to.
 * Used by runAsUser picker for tenant-admin.
 */
class OnlyInMyTenants implements Filter
{
    public function __construct(
        private User $user,
        private RunAsUserAccess $runAsUserAccess,
    ) {}

    public function apply(SelectBuilder $queryBuilder, OrGroupBuilder $orGroupBuilder): void
    {
        $teamIdList = $this->runAsUserAccess->getTenantTeamIdsForUser($this->user);

        if ($teamIdList === []) {
            $orGroupBuilder->add(
                WhereClause::fromRaw([Attribute::ID => null])
            );

            return;
        }

        $orGroupBuilder->add(
            Condition::in(
                Expression::column(Attribute::ID),
                SelectBuilder::create()
                    ->from(Team::RELATIONSHIP_TEAM_USER)
                    ->select('userId')
                    ->where(['teamId' => $teamIdList])
                    ->build()
            )
        );
    }
}
