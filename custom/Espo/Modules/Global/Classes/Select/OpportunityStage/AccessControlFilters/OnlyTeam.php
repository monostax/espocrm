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

namespace Espo\Modules\Global\Classes\Select\OpportunityStage\AccessControlFilters;

use Espo\Core\Select\AccessControl\Filter;
use Espo\Entities\User;
use Espo\ORM\Query\SelectBuilder as QueryBuilder;

/**
 * Custom OnlyTeam filter for OpportunityStage.
 *
 * Filters stages to only show those belonging to funnels of user's teams.
 *
 * @noinspection PhpUnused
 */
class OnlyTeam implements Filter
{
    public function __construct(
        private User $user,
    ) {}

    public function apply(QueryBuilder $queryBuilder): void
    {
        $teamIdList = $this->user->getTeamIdList();

        if (empty($teamIdList)) {
            // User has no teams, show nothing
            $queryBuilder->where(['id' => null]);
            return;
        }

        // Join with Funnel and filter by funnel's teamId
        $queryBuilder
            ->leftJoin('Funnel', 'funnel', ['funnel.id:' => 'funnelId'])
            ->where(['funnel.teamId' => $teamIdList]);
    }
}
