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

namespace Espo\Modules\Global\Classes\Acl\Funnel;

use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\Core\Acl\AccessEntityCREDSChecker;
use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\Acl\Traits\DefaultAccessCheckerDependency;
use Espo\Modules\Global\Tools\Acl\TeamsAccess;

/**
 * Custom ACL Access Checker for Funnel.
 *
 * A user may access a Funnel only if they share one of its `teams`. This is
 * enforced in addition to the role's access level, never instead of it: Team
 * membership is what confines a Funnel to its Tenant, so a role granting `all`
 * must still not expose funnels from other tenants.
 *
 * @implements AccessEntityCREDSChecker<Entity>
 */
class AccessChecker implements AccessEntityCREDSChecker
{
    use DefaultAccessCheckerDependency;

    public function __construct(
        DefaultAccessChecker $defaultAccessChecker,
        private TeamsAccess $teamsAccess,
    ) {
        $this->defaultAccessChecker = $defaultAccessChecker;
    }

    public function checkEntityRead(User $user, Entity $entity, ScopeData $data): bool
    {
        // Admin bypass
        if ($user->isAdmin()) {
            return true;
        }

        // Check base read permission. Previously omitted, which let team
        // membership alone grant read regardless of the role's Funnel level.
        if (!$this->defaultAccessChecker->checkRead($user, $data)) {
            return false;
        }

        return $this->teamsAccess->userSharesTeam($user, $entity);
    }

    public function checkEntityEdit(User $user, Entity $entity, ScopeData $data): bool
    {
        // Admin bypass
        if ($user->isAdmin()) {
            return true;
        }

        // Check base edit permission
        if (!$this->defaultAccessChecker->checkEdit($user, $data)) {
            return false;
        }

        return $this->teamsAccess->userSharesTeam($user, $entity);
    }

    public function checkEntityDelete(User $user, Entity $entity, ScopeData $data): bool
    {
        // Admin bypass
        if ($user->isAdmin()) {
            return true;
        }

        // Check base delete permission
        if (!$this->defaultAccessChecker->checkDelete($user, $data)) {
            return false;
        }

        return $this->teamsAccess->userSharesTeam($user, $entity);
    }

    public function checkEntityStream(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->checkEntityRead($user, $entity, $data);
    }
}



