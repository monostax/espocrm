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

namespace Espo\Modules\Global\Classes\Acl\User;

use Espo\Core\Acl\Permission;
use Espo\Core\Name\Field;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\Core\Acl\AccessEntityCREDSChecker;
use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\Acl\Table;
use Espo\Core\Acl\Traits\DefaultAccessCheckerDependency;
use Espo\Core\AclManager;
use Espo\Core\ORM\Entity as CoreEntity;

/**
 * Custom ACL Access Checker for User.
 *
 * Allows regular users to create/edit/delete other users within their own teams.
 * Admins retain full access. Super admin and system user protections are preserved.
 *
 * @implements AccessEntityCREDSChecker<User>
 */
class AccessChecker implements AccessEntityCREDSChecker
{
    use DefaultAccessCheckerDependency;

    public function __construct(
        private DefaultAccessChecker $defaultAccessChecker,
        private AclManager $aclManager,
    ) {}

    /**
     * Check if the current user shares at least one team with the target user entity.
     */
    private function sharesTeamWithUser(User $user, Entity $entity): bool
    {
        assert($entity instanceof CoreEntity);

        $userTeamIds = $user->getLinkMultipleIdList(Field::TEAMS);
        $entityTeamIds = $entity->getLinkMultipleIdList(Field::TEAMS);

        $intersect = array_intersect($userTeamIds, $entityTeamIds);

        return count($intersect) > 0;
    }

    /**
     * Check if user can create other users.
     * - Admins: full access
     * - Regular users: allowed if they have ACL create permission
     *   (team validation happens in BeforeCreate hook)
     */
    public function checkEntityCreate(User $user, Entity $entity, ScopeData $data): bool
    {
        // Super admin protection: only super admins can create super admins
        if ($entity->isSuperAdmin() && !$user->isSuperAdmin()) {
            return false;
        }

        // Admin bypass
        if ($user->isAdmin()) {
            return $this->defaultAccessChecker->checkEntityCreate($user, $entity, $data);
        }

        // Regular users: check if they have create permission from their role
        return $this->defaultAccessChecker->checkCreate($user, $data);
    }

    /**
     * Check if user can read another user.
     * - Preserves existing logic for inactive users, super admins, system users, portal users
     * - Regular users can read users in their teams
     */
    public function checkEntityRead(User $user, Entity $entity, ScopeData $data): bool
    {
        // Non-admins cannot read inactive users
        if (!$user->isAdmin() && !$entity->isActive()) {
            return false;
        }

        // Super admin protection
        if ($entity->isSuperAdmin() && !$user->isSuperAdmin()) {
            return false;
        }

        // System users are never readable
        if ($entity->isSystem()) {
            return false;
        }

        // Portal users require portal permission
        if ($entity->isPortal()) {
            return $this->aclManager->getPermissionLevel($user, Permission::PORTAL) === Table::LEVEL_YES;
        }

        // Admin bypass
        if ($user->isAdmin()) {
            return true;
        }

        // Regular users: use default access checker (respects team-based ACL)
        return $this->defaultAccessChecker->checkEntityRead($user, $entity, $data);
    }

    /**
     * Check if user can edit another user.
     * - Admins: full access (except super admin protection)
     * - Regular users: can edit users in their teams (if they have ACL edit permission)
     */
    public function checkEntityEdit(User $user, Entity $entity, ScopeData $data): bool
    {
        // System users cannot be edited
        if ($entity->isSystem()) {
            return false;
        }

        // Super admin protection
        if ($entity->isSuperAdmin() && !$user->isSuperAdmin()) {
            return false;
        }

        // Admin bypass
        if ($user->isAdmin()) {
            return $this->defaultAccessChecker->checkEntityEdit($user, $entity, $data);
        }

        // Regular users can always edit themselves (own profile)
        if ($user->getId() === $entity->getId()) {
            return $this->defaultAccessChecker->checkEntityEdit($user, $entity, $data);
        }

        // Regular users: check ACL edit permission AND team membership
        if (!$this->defaultAccessChecker->checkEdit($user, $data)) {
            return false;
        }

        // Cannot edit admin users
        if ($entity->isAdmin()) {
            return false;
        }

        return $this->sharesTeamWithUser($user, $entity);
    }

    /**
     * Check if user can delete another user.
     * - Admins: full access (except super admin protection)
     * - Regular users: can delete users in their teams (if they have ACL delete permission)
     */
    public function checkEntityDelete(User $user, Entity $entity, ScopeData $data): bool
    {
        // System users cannot be deleted
        if ($entity->isSystem()) {
            return false;
        }

        // Super admin protection
        if ($entity->isSuperAdmin() && !$user->isSuperAdmin()) {
            return false;
        }

        // Admin bypass
        if ($user->isAdmin()) {
            return $this->defaultAccessChecker->checkEntityDelete($user, $entity, $data);
        }

        // Regular users cannot delete themselves
        if ($user->getId() === $entity->getId()) {
            return false;
        }

        // Regular users: check ACL delete permission AND team membership
        if (!$this->defaultAccessChecker->checkDelete($user, $data)) {
            return false;
        }

        // Cannot delete admin users
        if ($entity->isAdmin()) {
            return false;
        }

        return $this->sharesTeamWithUser($user, $entity);
    }

    /**
     * Check if user can view another user's stream.
     */
    public function checkEntityStream(User $user, Entity $entity, ScopeData $data): bool
    {
        /** @noinspection PhpRedundantOptionalArgumentInspection */
        return $this->aclManager->checkUserPermission($user, $entity, Permission::USER);
    }
}
