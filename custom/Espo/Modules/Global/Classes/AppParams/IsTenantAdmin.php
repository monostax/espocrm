<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Classes\AppParams;

use Espo\Entities\User;
use Espo\Modules\Global\Classes\Utils\TenantRoleAuth;
use Espo\ORM\EntityManager;
use Espo\Tools\App\AppParam;

/**
 * True when current user has the tenant-admin role (not merely instance admin).
 * Consumed by runAsUser field view.
 */
class IsTenantAdmin implements AppParam
{
    public function __construct(
        private User $user,
        private EntityManager $entityManager,
    ) {}

    public function get(): bool
    {
        if (TenantRoleAuth::isInstanceAdmin($this->user)) {
            return false;
        }

        return TenantRoleAuth::hasTenantAdminRole($this->user, $this->entityManager);
    }
}
