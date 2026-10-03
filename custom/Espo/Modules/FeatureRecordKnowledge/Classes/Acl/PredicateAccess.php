<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Classes\Acl;

use Espo\Core\Acl\AccessEntityCREDSChecker;
use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\Acl\Traits\DefaultAccessCheckerDependency;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\Modules\FeatureRecordKnowledge\Services\Tenancy;

class PredicateAccess implements AccessEntityCREDSChecker
{
    use DefaultAccessCheckerDependency;
    public function __construct(DefaultAccessChecker $defaultAccessChecker, private Tenancy $tenancy) { $this->defaultAccessChecker = $defaultAccessChecker; }
    private function within(User $user, Entity $entity, bool $manage): bool
    {
        $id = $entity->get('tenantId');
        if (!$id && $entity->isNew()) {
            $ids = $this->tenancy->ids($user, $manage);
            $id = $ids !== null && count($ids) === 1 ? $ids[0] : null;
        }
        return is_string($id) && $this->tenancy->allowed($user, $id, $manage);
    }
    public function checkEntityCreate(User $user, Entity $entity, ScopeData $data): bool { return $this->checkCreate($user, $data) && $this->within($user, $entity, true); }
    public function checkEntityRead(User $user, Entity $entity, ScopeData $data): bool { return $this->checkRead($user, $data) && $this->within($user, $entity, false); }
    public function checkEntityEdit(User $user, Entity $entity, ScopeData $data): bool { return $this->checkEdit($user, $data) && $this->within($user, $entity, true); }
    public function checkEntityDelete(User $user, Entity $entity, ScopeData $data): bool { return $this->checkDelete($user, $data) && $this->within($user, $entity, true); }
    public function checkEntityStream(User $user, Entity $entity, ScopeData $data): bool { return $this->checkEntityRead($user, $entity, $data); }
}
