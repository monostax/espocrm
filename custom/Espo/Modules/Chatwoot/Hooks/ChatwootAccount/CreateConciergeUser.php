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
use Espo\Modules\Chatwoot\Hooks\ChatwootAccount\SyncWithChatwoot;
use Espo\Modules\Chatwoot\Services\ChatwootAccountUserMembershipService;

/**
 * Creates the ChatwootUser entity in EspoCRM after the ChatwootAccount is saved.
 * The actual Chatwoot user was already created in the beforeSave phase.
 * This hook creates the corresponding EspoCRM entity and links it to the ChatwootAccount.
 */
class CreateConciergeUser
{
    public static int $order = 20; // Run after database insert

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
        private ChatwootAccountUserMembershipService $membershipService
    ) {}

    /**
     * Create ChatwootUser entity in EspoCRM after the ChatwootAccount is saved.
     * Links the ChatwootUser to the ChatwootAccount via the conciergeUser relationship.
     * Only runs on entity creation.
     * 
     * @param Entity $entity
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        // Check if we have concierge user data from the beforeSave hook (via static cache)
        // Using static cache because entity transient data may be lost when EspoCRM
        // refreshes the entity from database after insert
        $entityId = $entity->getId();
        $conciergeUserData = SyncWithChatwoot::$conciergeUserDataCache[$entityId] ?? null;
        
        // Clean up the cache entry regardless of outcome
        unset(SyncWithChatwoot::$conciergeUserDataCache[$entityId]);
        
        if (!$conciergeUserData) {
            // No data means this is an update or the concierge user wasn't created
            return;
        }

        try {
            // Create corresponding ChatwootUser entity in EspoCRM
            // Using cached teamsIds and platformId since entity may have been refreshed
            $chatwootUser = $this->createChatwootUserEntity(
                $entity,
                $conciergeUserData['user_id'],
                $conciergeUserData['name'],
                $conciergeUserData['email'],
                $conciergeUserData['password'],
                $conciergeUserData['_teamsIds'] ?? [],
                $conciergeUserData['_platformId'] ?? null
            );

            if ($chatwootUser) {
                // Link the ChatwootUser to the ChatwootAccount
                $entity->set('conciergeUserId', $chatwootUser->getId());
                $this->entityManager->saveEntity($entity, ['silent' => true, 'skipHooks' => true]);

                $accountUserId = isset($conciergeUserData['account_user_id'])
                    ? (int) $conciergeUserData['account_user_id']
                    : null;

                $this->membershipService->upsertMembership(
                    $entity->getId(),
                    $chatwootUser->getId(),
                    'administrator',
                    $accountUserId,
                    true // isAI — concierge memberships are AI-enabled by default
                );
                
                $this->log->info(
                    'Created and linked ChatwootUser entity for concierge user: ' . 
                    $chatwootUser->getId()
                );
            }

        } catch (\Exception $e) {
            // Don't fail the entire process if ChatwootUser entity creation fails
            // The concierge user exists in Chatwoot, so this is just a record-keeping issue
            $this->log->error('Failed to create ChatwootUser entity: ' . $e->getMessage());
        }
    }

    /**
     * Create ChatwootUser entity in EspoCRM for the concierge user.
     * This ensures the user inherits the proper Team and Platform for tenant isolation.
     *
     * @param Entity $chatwootAccount
     * @param int $chatwootUserId
     * @param string $name
     * @param string $email
     * @param string $password
     * @param array<string> $teamsIds
     * @param string|null $platformId
     * @return Entity|null
     */
    private function createChatwootUserEntity(
        Entity $chatwootAccount,
        int $chatwootUserId,
        string $name,
        string $email,
        string $password,
        array $teamsIds,
        ?string $platformId
    ): ?Entity {
        try {
            // teamsIds and platformId are passed from cached data captured in beforeSave
            // because after entity refresh, the in-memory data may be lost

            // Create the ChatwootUser entity
            // Note: assignedUser is NOT set for concierge users to avoid unique constraint violation
            // Concierge users are system users, not tied to a specific EspoCRM user
            $chatwootUser = $this->entityManager->createEntity('ChatwootUser', [
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'displayName' => $name,
                'platformId' => $platformId,
                'chatwootUserId' => $chatwootUserId,
                'teamsIds' => $teamsIds // Inherit Teams from ChatwootAccount
            ], [
                'skipHooks' => true, // Skip hooks to avoid recursive creation
                'silent' => true
            ]);

            $this->log->info(
                'Created ChatwootUser entity for concierge user: ' . 
                $chatwootUser->getId() . 
                ' with Team: ' . ($teamId ?? 'none')
            );

            return $chatwootUser;

        } catch (\Exception $e) {
            $this->log->error('Failed to create ChatwootUser entity: ' . $e->getMessage());
            return null;
        }
    }
}
