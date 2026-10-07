<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Classes\Acl\User;

use Espo\Core\Acl\AccessCreateChecker;
use Espo\Core\Acl\AccessDeleteChecker;
use Espo\Core\Acl\AccessEditChecker;
use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\AclManager;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ManagedIdentityPolicy;
use Espo\ORM\Entity;

/** Keep the existing tenant-aware user ACL, but never delegate user administration to an AI principal. */
class AccessChecker extends \Espo\Modules\Global\Classes\Acl\User\AccessChecker implements
    AccessCreateChecker, AccessEditChecker, AccessDeleteChecker
{
    public function __construct(
        private DefaultAccessChecker $defaults,
        AclManager $aclManager,
        private ManagedIdentityPolicy $policy,
    ) {
        parent::__construct($defaults, $aclManager);
    }

    private function isManaged(User $user): bool
    {
        return $user->isApi() && $this->policy->forCrmUser($user->getId()) !== null;
    }

    public function checkCreate(User $user, ScopeData $data): bool
    {
        return !$this->isManaged($user) && $this->defaults->checkCreate($user, $data);
    }

    public function checkEdit(User $user, ScopeData $data): bool
    {
        return !$this->isManaged($user) && $this->defaults->checkEdit($user, $data);
    }

    public function checkDelete(User $user, ScopeData $data): bool
    {
        return !$this->isManaged($user) && $this->defaults->checkDelete($user, $data);
    }

    public function checkEntityCreate(User $user, Entity $entity, ScopeData $data): bool
    {
        return !$this->isManaged($user) && parent::checkEntityCreate($user, $entity, $data);
    }

    public function checkEntityEdit(User $user, Entity $entity, ScopeData $data): bool
    {
        return !$this->isManaged($user) && parent::checkEntityEdit($user, $entity, $data);
    }

    public function checkEntityDelete(User $user, Entity $entity, ScopeData $data): bool
    {
        return !$this->isManaged($user) && parent::checkEntityDelete($user, $entity, $data);
    }
}
