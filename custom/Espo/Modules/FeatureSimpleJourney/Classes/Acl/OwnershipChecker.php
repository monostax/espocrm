<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureSimpleJourney\Classes\Acl;

use Espo\Core\Acl\OwnershipTeamChecker;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Acl\TeamsAccess;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Integrates inherited teams with Espo's normal team-level ACL checks. */
class OwnershipChecker implements OwnershipTeamChecker
{
    public function __construct(private EntityManager $entityManager, private TeamsAccess $teamsAccess) {}

    public function checkTeam(User $user, Entity $entity): bool
    {
        $journey = $entity;

        if ($entity->getEntityType() !== 'SimpleJourney') {
            $id = $entity->get('journeyId');
            $journey = is_string($id) && $id !== ''
                ? $this->entityManager->getEntityById('SimpleJourney', $id)
                : null;
        }

        return $journey !== null && (bool) $journey->get('tenantId') &&
            $this->teamsAccess->userSharesTeam($user, $journey);
    }
}
