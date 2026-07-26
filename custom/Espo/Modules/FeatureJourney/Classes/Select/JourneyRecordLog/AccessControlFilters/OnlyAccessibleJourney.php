<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\Select\JourneyRecordLog\AccessControlFilters;

use Espo\Core\Select\AccessControl\Filter;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\ORM\Name\Attribute;
use Espo\ORM\Query\Part\Condition as Cond;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\SelectBuilder as QueryBuilder;

/**
 * Constrain JourneyRecordLog list to logs of journeys the user can access.
 */
class OnlyAccessibleJourney implements Filter
{
    public function __construct(
        private User $user,
    ) {}

    public function apply(QueryBuilder $queryBuilder): void
    {
        if ($this->user->isAdmin()) {
            return;
        }

        $teamIdList = $this->user->getTeamIdList();

        if ($teamIdList === []) {
            $queryBuilder->where([Attribute::ID => null]);

            return;
        }

        $subQuery = QueryBuilder::create()
            ->select(Attribute::ID)
            ->from('Journey')
            ->leftJoin(Team::RELATIONSHIP_ENTITY_TEAM, 'entityTeam', [
                'entityTeam.entityId:' => Attribute::ID,
                'entityTeam.entityType' => 'Journey',
                'entityTeam.deleted' => false,
            ])
            ->where([
                'entityTeam.teamId' => $teamIdList,
            ])
            ->build();

        $queryBuilder->where(
            Cond::in(
                Expr::column('journeyId'),
                $subQuery
            )
        );
    }
}
