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

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Exceptions\Error;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Config;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Hook to synchronize ChatwootUser with Chatwoot Platform API.
 * Creates users on Chatwoot and attaches them to accounts when created in EspoCRM.
 */
class SyncWithChatwoot implements AfterSave, BeforeSave
{
    public static int $order = 10;

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log,
        private Config $config
    ) {}

    /**
     * Validate account is set and configured before save.
     */
    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        // Only validate for new records
        if (!$entity->isNew()) {
            return;
        }

        // Ensure account is set
        $accountId = $entity->get('accountId');
        if (!$accountId) {
            throw new Error('Account is required for ChatwootUser.');
        }

        // Validate that the account exists
        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
        
        if (!$account) {
            throw new Error('Selected ChatwootAccount does not exist.');
        }

        // Validate account has a chatwootAccountId (has been synced to Chatwoot)
        $chatwootAccountId = $account->get('chatwootAccountId');
        if (!$chatwootAccountId) {
            throw new Error('ChatwootAccount has not been synchronized with Chatwoot yet. Please wait for the account to be created on Chatwoot first.');
        }

        // Get platform from account
        $platformId = $account->get('platformId');
        if (!$platformId) {
            throw new Error('ChatwootAccount does not have a platform configured.');
        }

        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
        
        if (!$platform) {
            throw new Error('ChatwootPlatform not found.');
        }

        // Validate platform has URL
        $url = $platform->get('url');
        if (!$url) {
            throw new Error('ChatwootPlatform does not have a URL configured.');
        }

        // Validate platform has access token
        $accessToken = $platform->get('accessToken');
        if (!$accessToken) {
            throw new Error('ChatwootPlatform does not have an access token configured.');
        }

        // Validate password is set for new users
        if (!$entity->get('password')) {
            throw new Error('Password is required for ChatwootUser.');
        }

        // Validate email is set
        if (!$entity->get('email')) {
            throw new Error('Email is required for ChatwootUser.');
        }
    }

    /**
     * Create user on Chatwoot and attach to account after entity is saved.
     */
    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        // Only run for new ChatwootUser records (not updates)
        if (!$entity->isNew()) {
            return;
        }

        // Skip if chatwootUserId already exists (user already synced)
        if ($entity->get('chatwootUserId')) {
            return;
        }

        // Skip if no account is linked
        $accountId = $entity->get('accountId');
        if (!$accountId) {
            $this->log->warning('ChatwootUser created without account link: ' . $entity->getId());
            return;
        }

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

            // Prepare user data for Chatwoot API
            $userData = $this->prepareUserData($entity);

            // Step 1: Create user on Chatwoot
            $userResponse = $this->apiClient->createUser($platformUrl, $accessToken, $userData);

            if (!isset($userResponse['id'])) {
                throw new Error('Chatwoot API response missing user ID.');
            }

            $chatwootUserId = $userResponse['id'];

            // Step 2: Attach user to account
            $role = $entity->get('role') ?? 'agent';
            $this->apiClient->attachUserToAccount(
                $platformUrl,
                $accessToken,
                $chatwootAccountId,
                $chatwootUserId,
                $role
            );

            // Update entity with Chatwoot user ID
            $entity->set('chatwootUserId', $chatwootUserId);
            
            // Save without triggering hooks again
            $this->entityManager->saveEntity($entity, [
                'skipHooks' => true,
                'silent' => true
            ]);

            $this->log->info(
                'Successfully created Chatwoot user: ' . 
                $chatwootUserId . ' and attached to account ' . $chatwootAccountId .
                ' for ' . $entity->getId()
            );
        } catch (\Exception $e) {
            // Since afterSave runs after the entity is committed to DB,
            // we need to delete it if the API call fails to maintain sync
            $this->log->error(
                'Failed to create Chatwoot user for ' . $entity->getId() . 
                ': ' . $e->getMessage() . '. Deleting entity to maintain sync.'
            );
            
            // Delete the entity to prevent orphaned records
            $this->entityManager->getRDBRepository($entity->getEntityType())
                ->deleteFromDb($entity->getId());
            
            // Re-throw the exception with a clear message
            throw new Error(
                'Failed to create user on Chatwoot: ' . $e->getMessage() . 
                '. The user was not created to maintain synchronization.'
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

