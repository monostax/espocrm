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
 * Creates user on Chatwoot AND attaches to account BEFORE saving to database.
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
     * Create user on Chatwoot AND attach to account BEFORE entity is saved to database.
     * The entity will be populated with chatwootUserId before the INSERT.
     * If either operation fails, an exception is thrown and nothing is saved.
     * 
     * @throws Error
     */
    public function process(Entity $entity, CreateParams $params): void
    {
        // Skip if chatwootUserId already exists (user already synced)
        if ($entity->get('chatwootUserId')) {
            return;
        }

        // Skip if no account is linked (should have been caught by validation)
        $accountId = $entity->get('accountId');
        if (!$accountId) {
            throw new Error('Account is required for ChatwootUser.');
        }

        $createdUserId = null;

        try {
            // Get the account entity
            $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
            
            if (!$account) {
                throw new Error('ChatwootAccount not found: ' . $accountId);
            }

            $chatwootAccountId = $account->get('chatwootAccountId');
            if (!$chatwootAccountId) {
                throw new Error('ChatwootAccount has not been synchronized with Chatwoot.');
            }

            // Get platform from account
            $platformId = $account->get('platformId');
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

            // STEP 1: Create user on Chatwoot
            $this->log->info('Creating Chatwoot user: ' . $entity->get('email'));
            
            $userData = $this->prepareUserData($entity);
            $userResponse = $this->apiClient->createUser($platformUrl, $accessToken, $userData);

            if (!isset($userResponse['id'])) {
                throw new Error('Chatwoot API response missing user ID.');
            }

            $chatwootUserId = $userResponse['id'];
            $createdUserId = $chatwootUserId;
            
            $this->log->info('Chatwoot user created successfully with ID: ' . $chatwootUserId);

            // STEP 2: Attach user to account
            $this->log->info("Attaching user $chatwootUserId to account $chatwootAccountId");
            
            $role = $entity->get('role') ?? 'agent';
            $this->apiClient->attachUserToAccount(
                $platformUrl,
                $accessToken,
                $chatwootAccountId,
                $chatwootUserId,
                $role
            );

            $this->log->info('User successfully attached to account');

            // STEP 3: Set chatwootUserId on entity BEFORE database insert
            $entity->set('chatwootUserId', $chatwootUserId);

            $this->log->info(
                'Successfully prepared Chatwoot user ' . 
                $chatwootUserId . ' attached to account ' . $chatwootAccountId .
                ' for database insert'
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
