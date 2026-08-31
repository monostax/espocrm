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

use Espo\Core\Acl\OwnershipTeamChecker;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Acl\TeamsAccess;
use Espo\ORM\Entity;

/**
 * Custom Ownership Checker for OpportunityStage.
 *
 * Checks whether a user shares one of the stage's `teams`, which mirror the
 * parent Funnel's.
 *
 * @implements OwnershipTeamChecker<Entity>
 */
class OwnershipChecker implements OwnershipTeamChecker
{
    public function __construct(
        private TeamsAccess $teamsAccess,
    ) {}

    /**
     * Check if the user is considered an "owner" of the entity.
     * For OpportunityStage, we don't use the concept of ownership.
     */
    public function checkOwn(User $user, Entity $entity): bool
    {
        return false;
    }

    /**
     * Check if the entity belongs to one of the user's teams.
     */
    public function checkTeam(User $user, Entity $entity): bool
    {
        return $this->teamsAccess->userSharesTeam($user, $entity);
    }
}
