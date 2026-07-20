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

namespace Espo\Modules\Global\Classes\Acl\CustomFieldGroup;

use Espo\Core\Acl\AccessEntityCREDSChecker;
use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\Acl\Traits\DefaultAccessCheckerDependency;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Entities\User;
use Espo\ORM\Entity;

/**
 * Team-based ACL for CustomFieldGroup (schema admin entity).
 *
 * Note: custom field VALUES on Contact/Lead/etc. are NOT checked here —
 * they inherit the host record's ACL (read/edit entity ⇒ read/edit values).
 *
 * @implements AccessEntityCREDSChecker<Entity>
 */
class AccessChecker implements AccessEntityCREDSChecker
{
    use DefaultAccessCheckerDependency;

    public function __construct(DefaultAccessChecker $defaultAccessChecker)
    {
        $this->defaultAccessChecker = $defaultAccessChecker;
    }

    private function userInEntityTeams(User $user, Entity $entity): bool
    {
        $teamIds = $this->getTeamIds($entity);

        if ($teamIds === []) {
            return false;
        }

        $userTeamIds = $user->getTeamIdList();

        foreach ($teamIds as $teamId) {
            if (in_array($teamId, $userTeamIds, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function getTeamIds(Entity $entity): array
    {
        if ($entity instanceof CoreEntity) {
            try {
                $ids = $entity->getLinkMultipleIdList('teams');

                if (is_array($ids) && $ids !== []) {
                    return array_values($ids);
                }
            } catch (\Throwable) {
                // fall through
            }
        }

        $raw = $entity->get('teamsIds');

        return is_array($raw) ? array_values($raw) : [];
    }

    public function checkEntityRead(User $user, Entity $entity, ScopeData $data): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $this->userInEntityTeams($user, $entity);
    }

    public function checkEntityEdit(User $user, Entity $entity, ScopeData $data): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (!$this->defaultAccessChecker->checkEdit($user, $data)) {
            return false;
        }

        return $this->userInEntityTeams($user, $entity);
    }

    public function checkEntityDelete(User $user, Entity $entity, ScopeData $data): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (!$this->defaultAccessChecker->checkDelete($user, $data)) {
            return false;
        }

        return $this->userInEntityTeams($user, $entity);
    }

    public function checkEntityStream(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->checkEntityRead($user, $entity, $data);
    }
}
