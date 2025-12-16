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

namespace Espo\Modules\Global\Classes\Acl\OpportunityStage;

use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Acl\OwnershipTeamChecker;

/**
 * Custom Ownership Checker for OpportunityStage.
 *
 * Checks if a user belongs to the OpportunityStage's Funnel's team.
 *
 * @implements OwnershipTeamChecker<Entity>
 */
class OwnershipChecker implements OwnershipTeamChecker
{
    public function __construct(
        private EntityManager $entityManager,
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
     * Check if the entity belongs to a user's team (via funnel).
     */
    public function checkTeam(User $user, Entity $entity): bool
    {
        $funnelId = $entity->get('funnelId');

        if (!$funnelId) {
            return false;
        }

        // Get the funnel to check its team
        $funnel = $this->entityManager->getEntityById('Funnel', $funnelId);

        if (!$funnel) {
            return false;
        }

        $funnelTeamId = $funnel->get('teamId');

        if (!$funnelTeamId) {
            return false;
        }

        $userTeamIds = $user->getTeamIdList();

        return in_array($funnelTeamId, $userTeamIds);
    }
}
