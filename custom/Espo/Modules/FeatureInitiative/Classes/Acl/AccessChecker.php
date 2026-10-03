<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureInitiative\Classes\Acl;

use Espo\Core\Acl\AccessEntityCREDSChecker;
use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\Acl\Traits\DefaultAccessCheckerDependency;
use Espo\Core\AclManager;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Acl\TeamsAccess;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Role permission AND the live initiative type's team boundary, even for an `all` role. */
class AccessChecker implements AccessEntityCREDSChecker
{
    use DefaultAccessCheckerDependency;

    public function __construct(
        DefaultAccessChecker $defaultAccessChecker,
        private TeamsAccess $teamsAccess,
        private EntityManager $entityManager,
        private AclManager $aclManager,
    ) {
        $this->defaultAccessChecker = $defaultAccessChecker;
    }

    public function checkEntityCreate(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->checkCreate($user, $data) && (
            $entity->getEntityType() === 'InitiativeType' || $this->withinInitiativeType($user, $entity, 'edit')
        );
    }

    public function checkEntityRead(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->defaultAccessChecker->checkEntityRead($user, $entity, $data) &&
            $this->withinInitiativeType($user, $entity, 'read');
    }

    public function checkEntityEdit(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->defaultAccessChecker->checkEntityEdit($user, $entity, $data) &&
            $this->withinInitiativeType($user, $entity, 'edit');
    }

    public function checkEntityDelete(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->defaultAccessChecker->checkEntityDelete($user, $entity, $data) &&
            $this->withinInitiativeType($user, $entity, 'edit');
    }

    public function checkEntityStream(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->defaultAccessChecker->checkEntityStream($user, $entity, $data) &&
            $this->withinInitiativeType($user, $entity, 'read');
    }

    private function withinInitiativeType(User $user, Entity $entity, string $action): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($entity->getEntityType() === 'InitiativeType') {
            return (bool) $entity->get('tenantId') && $this->teamsAccess->userSharesTeam($user, $entity);
        }

        if ($entity->getEntityType() === 'InitiativeRelation') {
            $initiativeId = $entity->get('initiativeId');
            $initiative = is_string($initiativeId) && $initiativeId !== ''
                ? $this->entityManager->getEntityById('Initiative', $initiativeId)
                : null;

            return $initiative !== null && $this->aclManager->checkEntity($user, $initiative, $action);
        }

        $id = $entity->get('initiativeTypeId');
        $initiativeType = is_string($id) && $id !== ''
            ? $this->entityManager->getEntityById('InitiativeType', $id)
            : null;

        // Operators may manage initiatives without permission to change type configuration.
        $parentAction = $entity->getEntityType() === 'Initiative' ? 'read' : $action;

        return $initiativeType !== null && $this->aclManager->checkEntity($user, $initiativeType, $parentAction);
    }
}
