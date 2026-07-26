<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureAgentbox\Services;

use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\ORM\EntityManager;

/**
 * Catalog privilege gates aligned with backend resolveAgentboxCatalogScope.
 *
 * - Espo instance admin: all kinds
 * - Role tenant-admin (static id, raw or md5): tenant-shared + membership writes
 * - Regular users: own user catalog only
 */
class CatalogAuth
{
    public const TENANT_ADMIN_STATIC_ID = 'tenant-admin';

    /**
     * @return list<string>
     */
    public static function tenantAdminRoleIds(): array
    {
        $static = self::TENANT_ADMIN_STATIC_ID;
        $hashed = md5($static);

        if ($static === $hashed) {
            return [$static];
        }

        return [$static, $hashed];
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
     * @throws Forbidden
     */
    public static function assertCanWriteCatalog(
        User $user,
        EntityManager $entityManager,
        string $workspaceKind,
        ?string $targetUserId
    ): void {
        $kind = CatalogScope::normalizeKind($workspaceKind);

        if ($kind === CatalogScope::KIND_USER) {
            $selfId = $user->getId();
            if (
                is_string($targetUserId) &&
                $targetUserId !== '' &&
                $targetUserId !== $selfId &&
                !self::isInstanceAdmin($user)
            ) {
                throw new Forbidden(
                    'Only Espo admins can modify another user\'s AgentSkill/AgentMode catalog.'
                );
            }

            return;
        }

        if (
            $kind === CatalogScope::KIND_CRM_GLOBAL ||
            $kind === CatalogScope::KIND_CONTACT
        ) {
            if (!self::isInstanceAdmin($user)) {
                throw new Forbidden(
                    'Only Espo admins can modify crm-global or contact catalogs.'
                );
            }

            return;
        }

        // tenant-shared | membership
        if (!self::hasTenantAdminRole($user, $entityManager)) {
            throw new Forbidden(
                'Only tenant-admin (or Espo admin) can modify tenant-shared/membership catalogs.'
            );
        }
    }

    /**
     * @return list<string>
     */
    private static function collectUserRoleIds(User $user, EntityManager $entityManager): array
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
