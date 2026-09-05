<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureSimpleJourney\Classes\Acl;

use Espo\Core\Acl\AccessEntityCREDSChecker;
use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\Acl\Traits\DefaultAccessCheckerDependency;
use Espo\Core\AclManager;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Acl\TeamsAccess;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Role permission AND the live journey's team boundary, even for an `all` role. */
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
            $entity->getEntityType() === 'SimpleJourney' || $this->withinJourney($user, $entity, 'edit')
        );
    }

    public function checkEntityRead(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->defaultAccessChecker->checkEntityRead($user, $entity, $data) &&
            $this->withinJourney($user, $entity, 'read');
    }

    public function checkEntityEdit(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->defaultAccessChecker->checkEntityEdit($user, $entity, $data) &&
            $this->withinJourney($user, $entity, 'edit');
    }

    public function checkEntityDelete(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->defaultAccessChecker->checkEntityDelete($user, $entity, $data) &&
            $this->withinJourney($user, $entity, 'edit');
    }

    public function checkEntityStream(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->defaultAccessChecker->checkEntityStream($user, $entity, $data) &&
            $this->withinJourney($user, $entity, 'read');
    }

    private function withinJourney(User $user, Entity $entity, string $action): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($entity->getEntityType() === 'SimpleJourney') {
            return (bool) $entity->get('tenantId') && $this->teamsAccess->userSharesTeam($user, $entity);
        }

        if ($entity->getEntityType() === 'SimpleJourneyRecordParent') {
            $recordId = $entity->get('recordId');
            $record = is_string($recordId) && $recordId !== ''
                ? $this->entityManager->getEntityById('SimpleJourneyRecord', $recordId)
                : null;

            return $record !== null && $this->aclManager->checkEntity($user, $record, $action);
        }

        $id = $entity->get('journeyId');
        $journey = is_string($id) && $id !== ''
            ? $this->entityManager->getEntityById('SimpleJourney', $id)
            : null;

        // Operators may manage records without permission to change journey configuration.
        $parentAction = $entity->getEntityType() === 'SimpleJourneyRecord' ? 'read' : $action;

        return $journey !== null && $this->aclManager->checkEntity($user, $journey, $parentAction);
    }
}
