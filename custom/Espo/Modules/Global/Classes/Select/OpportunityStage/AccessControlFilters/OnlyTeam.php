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

namespace Espo\Modules\Global\Classes\Select\OpportunityStage\AccessControlFilters;

use Espo\Core\Select\AccessControl\Filter;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\ORM\Name\Attribute;
use Espo\ORM\Query\SelectBuilder as QueryBuilder;

/**
 * Custom OnlyTeam filter for OpportunityStage.
 *
 * Filters on the stage's own `teams` (which mirror its Funnel's) via the
 * polymorphic `entity_team` table, replacing the previous join through
 * Funnel.teamId.
 *
 * Mirrors Espo\Core\Select\AccessControl\Filters\OnlyTeam.
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

        if ($teamIdList === []) {
            // User has no teams, show nothing.
            $queryBuilder->where([Attribute::ID => null]);

            return;
        }

        $teamSubQuery = QueryBuilder::create()
            ->select('entityId')
            ->from(Team::RELATIONSHIP_ENTITY_TEAM)
            ->where([
                'teamId' => $teamIdList,
                'entityType' => 'OpportunityStage',
                'deleted' => false,
            ])
            ->build();

        $queryBuilder->where([Attribute::ID . '=s' => $teamSubQuery]);
    }
}
