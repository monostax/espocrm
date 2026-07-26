<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\Acl\JourneyRecord;

use Espo\Core\Acl\AccessEntityCREDSChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\AclManager;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class AccessChecker implements AccessEntityCREDSChecker
{
    public function __construct(
        private AclManager $aclManager,
        private EntityManager $entityManager,
    ) {}

    public function check(User $user, ScopeData $data): bool
    {
        return $this->aclManager->checkScope($user, 'Journey');
    }

    public function checkCreate(User $user, ScopeData $data): bool
    {
        return $this->aclManager->checkScope($user, 'Journey', 'edit');
    }

    public function checkRead(User $user, ScopeData $data): bool
    {
        return $this->aclManager->checkScope($user, 'Journey', 'read');
    }

    public function checkEdit(User $user, ScopeData $data): bool
    {
        return $this->aclManager->checkScope($user, 'Journey', 'edit');
    }

    public function checkDelete(User $user, ScopeData $data): bool
    {
        return $this->aclManager->checkScope($user, 'Journey', 'edit');
    }

    public function checkStream(User $user, ScopeData $data): bool
    {
        return $this->checkRead($user, $data);
    }

    public function checkEntityCreate(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->canAccessParent($user, $entity, 'edit');
    }

    public function checkEntityRead(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->canAccessParent($user, $entity, 'read');
    }

    public function checkEntityEdit(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->canAccessParent($user, $entity, 'edit');
    }

    public function checkEntityDelete(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->canAccessParent($user, $entity, 'edit');
    }

    public function checkEntityStream(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->checkEntityRead($user, $entity, $data);
    }

    private function canAccessParent(User $user, Entity $entity, string $action): bool
    {
        $journeyId = $entity->get('journeyId');
        if (!$journeyId) {
            return $this->aclManager->checkScope($user, 'Journey', $action);
        }

        $journey = $this->entityManager->getEntityById('Journey', (string) $journeyId);
        if (!$journey) {
            return false;
        }

        return $this->aclManager->checkEntity($user, $journey, $action);
    }
}
