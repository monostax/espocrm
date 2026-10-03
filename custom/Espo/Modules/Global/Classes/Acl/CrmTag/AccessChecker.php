<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Classes\Acl\CrmTag;

use Espo\Core\Acl\AccessEntityCREDSChecker;
use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\Acl\Traits\DefaultAccessCheckerDependency;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Acl\TeamsAccess;
use Espo\ORM\Entity;

/** Catalog access is bounded by teams even when a role grants the all level. */
class AccessChecker implements AccessEntityCREDSChecker
{
    use DefaultAccessCheckerDependency;

    public function __construct(DefaultAccessChecker $defaultAccessChecker, private TeamsAccess $teams)
    {
        $this->defaultAccessChecker = $defaultAccessChecker;
    }

    public function checkEntityRead(User $user, Entity $entity, ScopeData $data): bool
    {
        return $user->isAdmin() || ($this->defaultAccessChecker->checkRead($user, $data) && $this->teams->userSharesTeam($user, $entity));
    }

    public function checkEntityEdit(User $user, Entity $entity, ScopeData $data): bool
    {
        return $user->isAdmin() || ($this->defaultAccessChecker->checkEdit($user, $data) && $this->teams->userSharesTeam($user, $entity));
    }

    public function checkEntityDelete(User $user, Entity $entity, ScopeData $data): bool
    {
        return $user->isAdmin() || ($this->defaultAccessChecker->checkDelete($user, $data) && $this->teams->userSharesTeam($user, $entity));
    }

    public function checkEntityStream(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->checkEntityRead($user, $entity, $data);
    }
}
