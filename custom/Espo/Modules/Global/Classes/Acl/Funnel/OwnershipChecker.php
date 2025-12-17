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

namespace Espo\Modules\Global\Classes\Acl\Funnel;

use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\Core\Acl\OwnershipTeamChecker;

/**
 * Custom Ownership Checker for Funnel.
 *
 * Checks if a user belongs to the Funnel's team.
 *
 * @implements OwnershipTeamChecker<Entity>
 */
class OwnershipChecker implements OwnershipTeamChecker
{
    /**
     * Check if the user is considered an "owner" of the entity.
     * For Funnel, we don't use the concept of ownership.
     */
    public function checkOwn(User $user, Entity $entity): bool
    {
        return false;
    }

    /**
     * Check if the entity belongs to a user's team.
     */
    public function checkTeam(User $user, Entity $entity): bool
    {
        $funnelTeamId = $entity->get('teamId');

        if (!$funnelTeamId) {
            return false;
        }

        $userTeamIds = $user->getTeamIdList();

        return in_array($funnelTeamId, $userTeamIds);
    }
}



