<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Classes\Acl\Opportunity;

use Espo\Core\Acl\AccessEntityCREDSChecker;
use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\Acl\Traits\DefaultAccessCheckerDependency;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;

class AccessChecker implements AccessEntityCREDSChecker
{
    use DefaultAccessCheckerDependency;

    public function __construct(DefaultAccessChecker $defaultAccessChecker, private UserTenantResolver $tenants)
    {
        $this->defaultAccessChecker = $defaultAccessChecker;
    }

    public function checkEntityRead(User $user, Entity $entity, ScopeData $data): bool
    {
        return ($user->isAdmin() || $this->tenants->canActForTenant($user, (string) $entity->get('tenantId'))) &&
            $this->defaultAccessChecker->checkEntityRead($user, $entity, $data);
    }

    public function checkEntityStream(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->checkEntityRead($user, $entity, $data) &&
            $this->defaultAccessChecker->checkEntityStream($user, $entity, $data);
    }

    public function checkEntityEdit(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->checkEntityRead($user, $entity, $data) &&
            $this->defaultAccessChecker->checkEntityEdit($user, $entity, $data);
    }

    public function checkEntityDelete(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->checkEntityRead($user, $entity, $data) &&
            $this->defaultAccessChecker->checkEntityDelete($user, $entity, $data);
    }
}
