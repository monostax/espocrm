<?php
declare(strict_types=1);
namespace Espo\Modules\FeatureAiSession\Classes;

use Espo\Core\Acl\AccessEntityCREDSChecker;
use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\Acl\Traits\DefaultAccessCheckerDependency;
use Espo\Entities\User;
use Espo\Modules\FeatureAiSession\Services\Access;
use Espo\ORM\Entity;

class AccessChecker implements AccessEntityCREDSChecker
{
    use DefaultAccessCheckerDependency;

    public function __construct(DefaultAccessChecker $defaultAccessChecker, private Access $access)
    {
        $this->defaultAccessChecker = $defaultAccessChecker;
    }

    public function checkEntityCreate(User $user, Entity $entity, ScopeData $data): bool
    {
        return ($user->isRegular() || $user->isAdmin()) && $user->isActive() && $this->checkCreate($user, $data);
    }

    public function checkEntityRead(User $user, Entity $entity, ScopeData $data): bool
    {
        return (($user->isActive() && $user->isAdmin()) || $this->access->owner($user, $entity)) &&
            $this->defaultAccessChecker->checkEntityRead($user, $entity, $data);
    }

    public function checkEntityEdit(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->access->owner($user, $entity) && $this->defaultAccessChecker->checkEntityEdit($user, $entity, $data);
    }

    public function checkEntityDelete(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->access->owner($user, $entity) && $this->defaultAccessChecker->checkEntityDelete($user, $entity, $data);
    }

    public function checkEntityStream(User $user, Entity $entity, ScopeData $data): bool
    {
        return (($user->isActive() && $user->isAdmin()) || $this->access->owner($user, $entity)) &&
            $this->defaultAccessChecker->checkEntityStream($user, $entity, $data);
    }
}
