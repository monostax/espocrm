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

namespace Espo\Modules\Global\Classes\Select\Funnel\AccessControlFilters;

use Espo\Core\Select\AccessControl\Filter;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\ORM\Name\Attribute;
use Espo\ORM\Query\SelectBuilder as QueryBuilder;

/**
 * Custom OnlyTeam filter for Funnel.
 *
 * Restricts funnels to those sharing a Team with the user, via the polymorphic
 * `entity_team` table. Funnel has no assignedUser/collaborators fields, so the
 * teams subquery is the only clause needed.
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
                'entityType' => 'Funnel',
                'deleted' => false,
            ])
            ->build();

        $queryBuilder->where([Attribute::ID . '=s' => $teamSubQuery]);
    }
}
