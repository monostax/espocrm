<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Classes\Acl\CrmTag;

use Espo\Core\Acl\AccessEntityCREDSChecker;
use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\Acl\Traits\DefaultAccessCheckerDependency;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Acl\TeamsAccess;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;

/** Personal ownership also applies to instance administrators. */
class AccessChecker implements AccessEntityCREDSChecker
{
    use DefaultAccessCheckerDependency;

    public function __construct(
        DefaultAccessChecker $defaultAccessChecker,
        private TeamsAccess $teams,
        private UserTenantResolver $tenants,
    )
    {
        $this->defaultAccessChecker = $defaultAccessChecker;
    }

    public function checkEntityRead(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->canAccess($user, $entity) && $this->defaultAccessChecker->checkRead($user, $data);
    }

    public function checkEntityEdit(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->canAccess($user, $entity) && $this->defaultAccessChecker->checkEdit($user, $data);
    }

    public function checkEntityDelete(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->canAccess($user, $entity) && $this->defaultAccessChecker->checkDelete($user, $data);
    }

    public function checkEntityStream(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->checkEntityRead($user, $entity, $data);
    }

    private function canAccess(User $user, Entity $entity): bool
    {
        if ($entity->get('visibility') === 'personal') {
            return $entity->get('ownerUserId') === $user->getId() &&
                $this->tenants->canActForTenant($user, (string) $entity->get('tenantId'));
        }

        return $user->isAdmin() || $this->teams->userSharesTeam($user, $entity);
    }
}
