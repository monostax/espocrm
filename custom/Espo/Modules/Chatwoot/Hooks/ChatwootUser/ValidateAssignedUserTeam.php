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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootUser;

use Espo\Core\Exceptions\Forbidden;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Validates that the assigned EspoCRM User belongs to at least one of the same teams as the ChatwootUser.
 * This enforces ACL by ensuring users can only be assigned to ChatwootUsers within ChatwootAccounts
 * they have team access to.
 *
 * Mirrors ChatwootAgent/ValidateAssignedUserTeam but at the authoritative write point (ChatwootUser).
 *
 * Runs after CascadeTeamsFromAccount (order=1) so teamsIds is already set.
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

        // Skip when assignment didn't change
        if (!$entity->isAttributeChanged('assignedUserId')) {
            return;
        }

        $assignedUserId = $entity->get('assignedUserId');

        // Clearing assignment is always valid
        if (!$assignedUserId) {
            return;
        }

        // Get the ChatwootUser's teams (cascaded from ChatwootAccount via CascadeTeamsFromAccount)
        $userTeamsIds = $entity->getLinkMultipleIdList('teams');

        // No validation needed if ChatwootUser doesn't have teams
        // (e.g., platform-level context with no chatwootAccountId)
        if (empty($userTeamsIds)) {
            return;
        }

        // Get the assigned EspoCRM User
        $user = $this->entityManager->getEntityById('User', $assignedUserId);

        if (!$user) {
            throw new Forbidden('Assigned user not found.');
        }

        // Get the EspoCRM user's teams
        $espoUserTeams = $user->getLinkMultipleIdList('teams');

        // Check if user belongs to at least one of the ChatwootUser's teams
        if (empty(array_intersect($userTeamsIds, $espoUserTeams))) {
            throw new Forbidden('User must be in the same team as the Chat / Account to be assigned to this Chat / User.');
        }
    }
}
