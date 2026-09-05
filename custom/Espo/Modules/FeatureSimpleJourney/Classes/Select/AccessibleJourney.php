<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureSimpleJourney\Classes\Select;

use Espo\Core\Acl;
use Espo\Core\Select\AccessControl\Filter;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\ORM\Query\SelectBuilder;

/** Mandatory list/search/export boundary; not bypassed by role access = all. */
class AccessibleJourney implements Filter
{
    public function __construct(
        private string $entityType,
        private User $user,
        private Acl $acl,
    ) {}

    public function apply(SelectBuilder $queryBuilder): void
    {
        if ($this->user->isAdmin()) {
            return;
        }

        $teams = $this->user->getTeamIdList();

        if ($teams === [] || !$this->acl->checkScope('SimpleJourney', 'read')) {
            $queryBuilder->where(['id' => null]);

            return;
        }

        $teamQuery = SelectBuilder::create()
            ->from(Team::RELATIONSHIP_ENTITY_TEAM)
            ->select('entityId')
            ->where(['entityType' => 'SimpleJourney', 'teamId' => $teams, 'deleted' => false])
            ->build();

        $journeyQuery = SelectBuilder::create()
            ->from('SimpleJourney')
            ->select('id')
            ->where(['id=s' => $teamQuery, 'tenantId!=' => null, 'deleted' => false])
            ->build();

        $attribute = $this->entityType === 'SimpleJourney' ? 'id' : 'journeyId';
        $queryBuilder->where([$attribute . '=s' => $journeyQuery]);
    }
}
