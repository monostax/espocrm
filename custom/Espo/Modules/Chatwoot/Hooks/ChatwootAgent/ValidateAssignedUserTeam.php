<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Hooks\ChatwootAgent;

use Espo\Core\Exceptions\Forbidden;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Validates that the assigned EspoCRM User belongs to the same team as the ChatwootAgent.
 * This enforces ACL by ensuring users can only be assigned to agents within ChatwootAccounts
 * they have team access to.
 * 
 * Runs after CascadeTeamsFromAccount (order=1) so teamId is already set.
 */
class ValidateAssignedUserTeam
{
    public static int $order = 5;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    /**
     * Validate assigned user team membership before save.
     * 
     * @param Entity $entity
     * @param array<string, mixed> $options
     * @throws Forbidden
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        // Skip validation for silent saves (from sync jobs)
        if (!empty($options['silent'])) {
            return;
        }

        $assignedUserId = $entity->get('assignedUserId');
        
        // No validation needed if no user is assigned
        if (!$assignedUserId) {
            return;
        }

        // Get the agent's team (cascaded from ChatwootAccount)
        $agentTeamId = $entity->get('teamId');
        
        // No validation needed if agent doesn't have a team
        if (!$agentTeamId) {
            return;
        }

        // Get the assigned user
        $user = $this->entityManager->getEntityById('User', $assignedUserId);
        
        if (!$user) {
            throw new Forbidden('Assigned user not found.');
        }

        // Get the user's teams
        $userTeams = $user->getLinkMultipleIdList('teams');
        
        // Check if user belongs to the agent's team
        if (!in_array($agentTeamId, $userTeams)) {
            throw new Forbidden('User must be in the same team as the Chatwoot Account to be assigned to this agent.');
        }
    }
}
