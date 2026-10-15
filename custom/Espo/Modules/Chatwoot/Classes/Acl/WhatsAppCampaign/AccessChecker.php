<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Classes\Acl\WhatsAppCampaign;

use Espo\Core\Acl\AccessEntityCREDSChecker;
use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;

class AccessChecker implements AccessEntityCREDSChecker
{
    public function __construct(private DefaultAccessChecker $default, private UserTenantResolver $tenants) {}

    public function check(User $user, ScopeData $data): bool { return $this->default->check($user, $data); }
    public function checkCreate(User $user, ScopeData $data): bool { return $this->default->checkCreate($user, $data); }
    public function checkRead(User $user, ScopeData $data): bool { return $this->default->checkRead($user, $data); }
    public function checkEdit(User $user, ScopeData $data): bool { return $this->default->checkEdit($user, $data); }
    public function checkDelete(User $user, ScopeData $data): bool { return $this->default->checkDelete($user, $data); }
    public function checkStream(User $user, ScopeData $data): bool { return $this->default->checkStream($user, $data); }

    public function checkEntityCreate(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->inTenant($user, $entity) && $this->default->checkEntityCreate($user, $entity, $data);
    }

    public function checkEntityRead(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->inTenant($user, $entity) && $this->default->checkEntityRead($user, $entity, $data);
    }

    public function checkEntityEdit(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->inTenant($user, $entity) && $this->default->checkEntityEdit($user, $entity, $data);
    }

    public function checkEntityDelete(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->inTenant($user, $entity) && $this->default->checkEntityDelete($user, $entity, $data);
    }

    public function checkEntityStream(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->checkEntityRead($user, $entity, $data);
    }

    private function inTenant(User $user, Entity $entity): bool
    {
        return !$entity->get('managedByChatwoot') || $user->isAdmin() ||
            ($user->isRegular() && $user->isActive() && $this->tenants->canActForTenant($user, (string) $entity->get('tenantId')));
    }
}
