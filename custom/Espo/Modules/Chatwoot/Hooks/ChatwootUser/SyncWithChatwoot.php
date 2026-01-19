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

use Espo\Core\Record\Hook\CreateHook;
use Espo\Core\Record\CreateParams;
use Espo\Core\Exceptions\Error;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Hook to synchronize ChatwootUser with Chatwoot Platform API.
 * Creates user on Chatwoot BEFORE saving to database.
 * ChatwootUser is now platform-level - attaching to accounts is done via ChatwootAgent.
 * This ensures the database only contains users that fully exist in Chatwoot.
 */
class SyncWithChatwoot implements CreateHook
{
    public static int $order = 10; // Run after ValidateBeforeSync

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    /**
     * Create user on Chatwoot BEFORE entity is saved to database.
     * The entity will be populated with chatwootUserId before the INSERT.
     * Account attachment is now handled by ChatwootAgent creation.
     * 
     * @throws Error
     */
    public function process(Entity $entity, CreateParams $params): void
    {
        // Skip if chatwootUserId already exists (user already synced)
        if ($entity->get('chatwootUserId')) {
            return;
        }

        // Skip if no platform is linked (should have been caught by validation)
        $platformId = $entity->get('platformId');
        if (!$platformId) {
            throw new Error('Platform is required for ChatwootUser.');
        }

        $createdUserId = null;

        try {
            // Get the platform entity
            $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
            
            if (!$platform) {
                throw new Error('ChatwootPlatform not found: ' . $platformId);
            }

            // Get platform URL and access token
            $platformUrl = $platform->get('url');
            $accessToken = $platform->get('accessToken');

            if (!$platformUrl) {
                throw new Error('ChatwootPlatform does not have a URL.');
            }

            if (!$accessToken) {
                throw new Error('ChatwootPlatform does not have an access token.');
            }

            // Create user on Chatwoot Platform API
            $this->log->info('Creating Chatwoot user: ' . $entity->get('email'));
            
            $userData = $this->prepareUserData($entity);
            $userResponse = $this->apiClient->createUser($platformUrl, $accessToken, $userData);

            if (!isset($userResponse['id'])) {
                throw new Error('Chatwoot API response missing user ID.');
            }

            $chatwootUserId = $userResponse['id'];
            $createdUserId = $chatwootUserId;
            
            $this->log->info('Chatwoot user created successfully with ID: ' . $chatwootUserId);

            // Set chatwootUserId on entity BEFORE database insert
            $entity->set('chatwootUserId', $chatwootUserId);

            $this->log->info(
                'Successfully prepared Chatwoot user ' . $chatwootUserId . ' for database insert'
            );

        } catch (\Exception $e) {
            // ROLLBACK: If anything failed, log for manual cleanup
            $this->log->error(
                'Failed to create Chatwoot user for ' . $entity->get('email') . 
                ': ' . $e->getMessage()
            );
            
            if ($createdUserId) {
                $this->log->error(
                    'Orphaned Chatwoot user created with ID: ' . $createdUserId . 
                    '. Manual cleanup may be required.'
                );
            }

            // Re-throw - this will prevent the database INSERT from happening
            throw new Error(
                'Failed to create user on Chatwoot: ' . $e->getMessage() . 
                '. The user was not created in EspoCRM to maintain synchronization.'
            );
        }
    }

    /**
     * Prepare user data for Chatwoot API.
     *
     * @param Entity $entity
     * @return array<string, mixed>
     */
    private function prepareUserData(Entity $entity): array
    {
        $data = [
            'name' => $entity->get('name'),
            'email' => $entity->get('email'),
            'password' => $entity->get('password'),
            'custom_attributes' => []
        ];

        // Add display_name if set
        if ($entity->get('displayName')) {
            $data['display_name'] = $entity->get('displayName');
        }

        return $data;
    }
}
