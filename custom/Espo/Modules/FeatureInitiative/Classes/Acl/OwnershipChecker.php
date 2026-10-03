<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureInitiative\Classes\Acl;

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
        $initiativeType = $entity;

        if ($entity->getEntityType() !== 'InitiativeType') {
            $id = $entity->get('initiativeTypeId');
            $initiativeType = is_string($id) && $id !== ''
                ? $this->entityManager->getEntityById('InitiativeType', $id)
                : null;
        }

        return $initiativeType !== null && (bool) $initiativeType->get('tenantId') &&
            $this->teamsAccess->userSharesTeam($user, $initiativeType);
    }
}
