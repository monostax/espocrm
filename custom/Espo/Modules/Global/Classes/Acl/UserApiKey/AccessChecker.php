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

namespace Espo\Modules\Global\Classes\Acl\UserApiKey;

use Espo\Core\Acl\AccessEntityCREDChecker;
use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\Acl\Traits\DefaultAccessCheckerDependency;
use Espo\Entities\User;
use Espo\ORM\Entity;

/**
 * Access checker for the UserApiKey entity.
 *
 * Policy:
 *   • Admins have full CRUD over all keys (operator escape hatch).
 *   • Portal users have NO access — keys are an internal-tooling concept.
 *   • Regular users (and `type=api` users) can CRUD their OWN keys only,
 *     scoped by `userId === currentUser.id`. The corresponding select-side
 *     filter is {@see \Espo\Modules\Global\Classes\Select\UserApiKey\AccessControlFilters\Mandatory}.
 *
 * The scope is `acl: "boolean"`, so the role config only toggles the
 * entity on/off; per-level (own/all/team) is intentionally not exposed —
 * the only meaningful access is "own", which this checker enforces.
 *
 * @implements AccessEntityCREDChecker<Entity>
 */
class AccessChecker implements AccessEntityCREDChecker
{
    use DefaultAccessCheckerDependency;

    public function __construct(DefaultAccessChecker $defaultAccessChecker)
    {
        $this->defaultAccessChecker = $defaultAccessChecker;
    }

    public function check(User $user, ScopeData $data): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isPortal()) {
            return false;
        }

        if ($data->isFalse()) {
            return false;
        }

        return true;
    }

    public function checkEntityCreate(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->checkEntityInternal($user, $entity, $data);
    }

    public function checkEntityRead(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->checkEntityInternal($user, $entity, $data);
    }

    public function checkEntityEdit(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->checkEntityInternal($user, $entity, $data);
    }

    public function checkEntityDelete(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->checkEntityInternal($user, $entity, $data);
    }

    private function checkEntityInternal(User $user, Entity $entity, ScopeData $data): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isPortal()) {
            return false;
        }

        if ($data->isFalse()) {
            return false;
        }

        return $user->getId() === $entity->get('userId');
    }
}
