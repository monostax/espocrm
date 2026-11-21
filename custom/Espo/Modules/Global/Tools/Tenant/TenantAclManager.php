<?php

declare(strict_types=1);

/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Global\Tools\Tenant;

use Espo\Core\Acl\Permission;
use Espo\Core\AclManager;
use Espo\Entities\User;

/**
 * Keeps another user's activity inside the viewer's workspaces.
 *
 * Espo's `user` and `userCalendar` permissions at `team` level open a user's
 * whole stream feed, own posts, calendar and activities to anyone sharing one
 * team with them. That feed is filtered with the TARGET's access, so for a
 * member of several tenants it carries every tenant's records. On top of the
 * role check, the viewer must cover the target (see TenantVisibility).
 *
 * Assignment, mention, message and follower permissions are unchanged: staff
 * stay assignable and mentionable inside each tenant they work in.
 */
class TenantAclManager extends AclManager
{
    private const ACTIVITY_PERMISSIONS = [
        Permission::USER,
        Permission::USER_CALENDAR,
    ];

    public function checkUserPermission(User $user, $target, string $permissionType = Permission::USER): bool
    {
        if (!parent::checkUserPermission($user, $target, $permissionType)) {
            return false;
        }

        if (!in_array($permissionType, self::ACTIVITY_PERMISSIONS, true)) {
            return true;
        }

        $visibility = $this->getTenantVisibility();

        if ($target instanceof User) {
            return $visibility->covers($user, $target);
        }

        return $visibility->coversUserId($user, (string) $target);
    }

    /**
     * Fresh per check: this service lives as long as the process. Checks run
     * once per feed or calendar request, so the lookups are cheap.
     */
    protected function getTenantVisibility(): TenantVisibility
    {
        return TenantVisibility::create($this->entityManager);
    }
}
