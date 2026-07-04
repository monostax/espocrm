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

namespace Espo\Modules\Chatwoot\Classes\Acl\ChatwootAccountWebhook;

use Espo\Core\Acl\AccessEntityCREDChecker;
use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\Acl\Traits\DefaultAccessCheckerDependency;
use Espo\Entities\User;
use Espo\ORM\Entity;

/**
 * Access checker for the ChatwootAccountWebhook entity.
 *
 * Policy:
 *   • Admins have full CRUD over all webhooks.
 *   • Non-admins are denied access to any webhook whose URL contains
 *     ".cluster.local" (internal cluster-internal endpoints such as the
 *     Hatchet AI Agent webhook). All other webhooks fall through to the
 *     default team-based ACL.
 *
 * The corresponding select-side filter is
 * {@see \Espo\Modules\Chatwoot\Classes\Select\ChatwootAccountWebhook\AccessControlFilters\Mandatory}.
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

        if ($data->isFalse()) {
            return false;
        }

        return true;
    }

    public function checkEntityCreate(User $user, Entity $entity, ScopeData $data): bool
    {
        if (!$this->defaultAccessChecker->checkEntityCreate($user, $entity, $data)) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if ($this->isClusterLocalUrl($entity)) {
            return false;
        }

        return true;
    }

    public function checkEntityRead(User $user, Entity $entity, ScopeData $data): bool
    {
        if (!$this->defaultAccessChecker->checkEntityRead($user, $entity, $data)) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if ($this->isClusterLocalUrl($entity)) {
            return false;
        }

        return true;
    }

    public function checkEntityEdit(User $user, Entity $entity, ScopeData $data): bool
    {
        if (!$this->defaultAccessChecker->checkEntityEdit($user, $entity, $data)) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if ($this->isClusterLocalUrl($entity)) {
            return false;
        }

        return true;
    }

    public function checkEntityDelete(User $user, Entity $entity, ScopeData $data): bool
    {
        if (!$this->defaultAccessChecker->checkEntityDelete($user, $entity, $data)) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if ($this->isClusterLocalUrl($entity)) {
            return false;
        }

        return true;
    }

    public function checkEntityStream(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->checkEntityRead($user, $entity, $data);
    }

    private function isClusterLocalUrl(Entity $entity): bool
    {
        $url = $entity->get('url');

        if (!$url || !is_string($url)) {
            return false;
        }

        return str_contains($url, '.cluster.local');
    }
}
