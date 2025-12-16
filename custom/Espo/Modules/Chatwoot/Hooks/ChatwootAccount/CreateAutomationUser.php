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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootAccount;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;

/**
 * Creates the ChatwootUser entity in EspoCRM after the ChatwootAccount is saved.
 * The actual Chatwoot user was already created in the beforeSave phase.
 * This hook just creates the corresponding EspoCRM entity for record-keeping and access control.
 */
class CreateAutomationUser
{
    public static int $order = 20; // Run after database insert

    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    /**
     * Create ChatwootUser entity in EspoCRM after the ChatwootAccount is saved.
     * Only runs on entity creation.
     * 
     * @param Entity $entity
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        // Only run for new ChatwootAccount records (not updates)
        if (!$entity->isNew()) {
            return;
        }
        // Check if we have automation user data from the beforeCreate hook
        $automationUserData = $entity->get('_automationUserData');
        
        if (!$automationUserData) {
            $this->log->debug('No automation user data found, skipping ChatwootUser entity creation');
            return;
        }

        try {
            // Create corresponding ChatwootUser entity in EspoCRM
            $chatwootUser = $this->createChatwootUserEntity(
                $entity,
                $automationUserData['user_id'],
                $automationUserData['name'],
                $automationUserData['email'],
                $automationUserData['password']
            );

            if ($chatwootUser) {
                $this->log->info(
                    'Created ChatwootUser entity for automation user: ' . 
                    $chatwootUser->getId()
                );
            }

        } catch (\Exception $e) {
            // Don't fail the entire process if ChatwootUser entity creation fails
            // The automation user exists in Chatwoot, so this is just a record-keeping issue
            $this->log->error('Failed to create ChatwootUser entity: ' . $e->getMessage());
        }
    }

    /**
     * Create ChatwootUser entity in EspoCRM for the automation user.
     * This ensures the user inherits the proper Teams for tenant isolation.
     *
     * @param Entity $chatwootAccount
     * @param int $chatwootUserId
     * @param string $name
     * @param string $email
     * @param string $password
     * @return Entity|null
     */
    private function createChatwootUserEntity(
        Entity $chatwootAccount,
        int $chatwootUserId,
        string $name,
        string $email,
        string $password
    ): ?Entity {
        try {
            // Get Teams from the ChatwootAccount for tenant isolation
            $teams = $this->entityManager
                ->getRDBRepository('ChatwootAccount')
                ->getRelation($chatwootAccount, 'teams')
                ->find();

            $teamIds = [];
            foreach ($teams as $team) {
                $teamIds[] = $team->getId();
            }

            // Create the ChatwootUser entity
            // Note: assignedUser is NOT set for automation users to avoid unique constraint violation
            // Automation users are system users, not tied to a specific EspoCRM user
            $chatwootUser = $this->entityManager->createEntity('ChatwootUser', [
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'displayName' => $name,
                'accountId' => $chatwootAccount->getId(),
                'role' => 'administrator',
                'chatwootUserId' => $chatwootUserId,
                'teamsIds' => $teamIds // Inherit Teams from ChatwootAccount
            ], [
                'skipHooks' => true, // Skip hooks to avoid recursive creation
                'silent' => true
            ]);

            $this->log->info(
                'Created ChatwootUser entity for automation user: ' . 
                $chatwootUser->getId() . 
                ' with Teams: ' . implode(', ', $teamIds)
            );

            return $chatwootUser;

        } catch (\Exception $e) {
            $this->log->error('Failed to create ChatwootUser entity: ' . $e->getMessage());
            return null;
        }
    }
}
