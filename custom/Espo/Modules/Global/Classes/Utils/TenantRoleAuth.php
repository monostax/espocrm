<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Classes\Utils;

use Espo\Entities\User;
use Espo\ORM\EntityManager;

/**
 * Role gates shared across modules (tenant-admin vs instance admin).
 *
 * Role ids may be the static string or its md5 (UUID rebuild mode).
 */
class TenantRoleAuth
{
    public const TENANT_ADMIN_STATIC_ID = 'tenant-admin';
    public const TENANT_STATIC_ID = 'tenant';

    /**
     * Candidate row ids for a seeded static role id. Rebuild\SeedRole stores
     * these roles under the raw static id, or its md5 when the instance uses
     * UUID record ids. Single source of truth for that duality.
     *
     * @return list<string>
     */
    public static function roleIdsFor(string $staticId): array
    {
        $hashed = md5($staticId);

        if ($staticId === $hashed) {
            return [$staticId];
        }

        return [$staticId, $hashed];
    }

    /**
     * @return list<string>
     */
    public static function tenantAdminRoleIds(): array
    {
        return self::roleIdsFor(self::TENANT_ADMIN_STATIC_ID);
    }

    public static function isInstanceAdmin(User $user): bool
    {
        return $user->isAdmin();
    }

    public static function hasTenantAdminRole(User $user, EntityManager $entityManager): bool
    {
        if (self::isInstanceAdmin($user)) {
            return true;
        }

        $roleIds = self::collectUserRoleIds($user, $entityManager);
        if ($roleIds === []) {
            return false;
        }

        $adminRoleIds = self::tenantAdminRoleIds();

        foreach ($roleIds as $roleId) {
            if (in_array($roleId, $adminRoleIds, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function collectUserRoleIds(User $user, EntityManager $entityManager): array
    {
        $roleIds = [];

        foreach ($user->getLinkMultipleIdList('roles') as $roleId) {
            if (is_string($roleId) && $roleId !== '') {
                $roleIds[] = $roleId;
            }
        }

        $teamIds = $user->getLinkMultipleIdList('teams');
        if ($teamIds === []) {
            return array_values(array_unique($roleIds));
        }

        $teams = $entityManager
            ->getRDBRepository('Team')
            ->where(['id' => $teamIds])
            ->find();

        foreach ($teams as $team) {
            foreach ($team->getLinkMultipleIdList('roles') as $roleId) {
                if (is_string($roleId) && $roleId !== '') {
                    $roleIds[] = $roleId;
                }
            }
        }

        return array_values(array_unique($roleIds));
    }
}
