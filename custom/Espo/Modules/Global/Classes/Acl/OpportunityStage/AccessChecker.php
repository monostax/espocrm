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

namespace Espo\Modules\Global\Classes\Acl\OpportunityStage;

use Espo\Core\Acl\AccessEntityCREDSChecker;
use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\Acl\Traits\DefaultAccessCheckerDependency;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Acl\TeamsAccess;
use Espo\ORM\Entity;

/**
 * Custom ACL Access Checker for OpportunityStage.
 *
 * A stage's `teams` mirror its Funnel's (maintained by
 * Hooks/OpportunityStage/InheritFunnelTeams and Hooks/Funnel/SyncStageTeams),
 * so access is decided from the stage's own teams rather than by loading the
 * parent Funnel on every check.
 *
 * Team membership is enforced in addition to the role's access level, never
 * instead of it, since Team membership is what confines a stage to its Tenant.
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
        // membership alone grant read regardless of the role's access level.
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
