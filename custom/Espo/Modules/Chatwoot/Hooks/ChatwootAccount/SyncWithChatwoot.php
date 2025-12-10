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

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Exceptions\Error;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Config;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Entities\User;

/**
 * Hook to synchronize ChatwootAccount with Chatwoot Platform API.
 * Creates accounts on Chatwoot when they are created in EspoCRM.
 */
class SyncWithChatwoot implements AfterSave, BeforeSave
{
    public static int $order = 10;

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log,
        private Config $config,
        private User $user
    ) {}

    /**
     * Validate platform is set and configured before save.
     */
    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        // Only validate for new records
        if (!$entity->isNew()) {
            return;
        }

        // Ensure platform is set
        $platformId = $entity->get('platformId');
        if (!$platformId) {
            throw new Error('Platform is required for ChatwootAccount.');
        }

        // Validate that the platform exists and is properly configured
        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
        
        if (!$platform) {
            throw new Error('Selected ChatwootPlatform does not exist.');
        }

        // Validate platform has URL
        $url = $platform->get('url');
        if (!$url) {
            throw new Error('ChatwootPlatform does not have a URL configured. Please configure the platform URL first.');
        }

        // Validate platform has access token
        $accessToken = $platform->get('accessToken');
        if (!$accessToken) {
            throw new Error('ChatwootPlatform does not have an access token configured. Please configure the access token first.');
        }
    }

    /**
     * Create account on Chatwoot after entity is saved.
     */
    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        // Only run for new ChatwootAccount records (not updates)
        if (!$entity->isNew()) {
            return;
        }

        // Skip if chatwootAccountId already exists (account already synced)
        if ($entity->get('chatwootAccountId')) {
            return;
        }

        // Skip if no platform is linked
        $platformId = $entity->get('platformId');
        if (!$platformId) {
            $this->log->warning('ChatwootAccount created without platform link: ' . $entity->getId());
            return;
        }

        try {
            // Get the platform entity
            $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
            
            if (!$platform) {
                throw new Error('ChatwootPlatform not found: ' . $platformId);
            }

            // Get platform URL and access token
            $platformUrl = $this->getPlatformUrl($platform);
            $accessToken = $platform->get('accessToken');

            if (!$accessToken) {
                throw new Error('ChatwootPlatform does not have an access token.');
            }

            // Prepare account data for Chatwoot API
            $accountData = $this->prepareAccountData($entity);

            // Create account on Chatwoot
            $response = $this->apiClient->createAccount($platformUrl, $accessToken, $accountData);

            // Update entity with Chatwoot account ID
            if (isset($response['id'])) {
                $chatwootAccountId = $response['id'];
                $entity->set('chatwootAccountId', $chatwootAccountId);
                
                // Create automation user for this account
                $automationUser = $this->createAutomationUser(
                    $platformUrl,
                    $accessToken,
                    $chatwootAccountId,
                    $entity->get('name'),
                    $entity
                );
                
                if ($automationUser) {
                    // Store automation user details
                    $entity->set('automationUserId', $automationUser['user_id']);
                    $entity->set('automationUserEmail', $automationUser['email']);
                    
                    if (isset($automationUser['access_token'])) {
                        // Store the automation user's access token as API key
                        $entity->set('apiKey', $automationUser['access_token']);
                        $this->log->info('Automation user created with access token for account: ' . $chatwootAccountId);
                    } else {
                        $this->log->warning(
                            'Automation user created but no access token received. ' .
                            'Manual API Key configuration needed. Login email: ' . $automationUser['email']
                        );
                    }
                }
                
                // Save without triggering hooks again
                $this->entityManager->saveEntity($entity, [
                    'skipHooks' => true,
                    'silent' => true
                ]);

                $this->log->info(
                    'Successfully created Chatwoot account: ' . 
                    $chatwootAccountId . ' for ' . $entity->getId()
                );
            } else {
                throw new Error('Chatwoot API response missing account ID.');
            }
        } catch (\Exception $e) {
            // Since afterSave runs after the entity is committed to DB,
            // we need to delete it if the API call fails to maintain sync
            $this->log->error(
                'Failed to create Chatwoot account for ' . $entity->getId() . 
                ': ' . $e->getMessage() . '. Deleting entity to maintain sync.'
            );
            
            // Delete the entity to prevent orphaned records
            $this->entityManager->getRDBRepository($entity->getEntityType())
                ->deleteFromDb($entity->getId());
            
            // Re-throw the exception with a clear message
            throw new Error(
                'Failed to create account on Chatwoot: ' . $e->getMessage() . 
                '. The account was not created to maintain synchronization.'
            );
        }
    }

    /**
     * Prepare account data for Chatwoot API.
     *
     * @param Entity $entity
     * @return array<string, mixed>
     */
    private function prepareAccountData(Entity $entity): array
    {
        $data = [
            'name' => $entity->get('name'),
            'locale' => $entity->get('locale') ?? 'pt_BR',
            'status' => $entity->get('status') ?? 'active',
        ];

        // Add optional fields if they exist
        if ($entity->get('domain')) {
            $data['domain'] = $entity->get('domain');
        }

        if ($entity->get('supportEmail')) {
            $data['support_email'] = $entity->get('supportEmail');
        }

        // Add empty arrays for limits and custom_attributes as per API spec
        $data['limits'] = [];
        $data['custom_attributes'] = [];

        return $data;
    }

    /**
     * Get platform URL from platform entity.
     *
     * @param Entity $platform
     * @return string
     * @throws Error
     */
    private function getPlatformUrl(Entity $platform): string
    {
        $url = $platform->get('url');
        
        if (!$url) {
            throw new Error('ChatwootPlatform does not have a URL configured.');
        }

        return $url;
    }

    /**
     * Create an automation user for the account.
     * The user will be created as a ChatwootUser entity in EspoCRM
     * and inherit the Teams from the ChatwootAccount for proper tenant isolation.
     *
     * @param string $platformUrl
     * @param string $accessToken
     * @param int $chatwootAccountId
     * @param string $accountName
     * @return array<string, mixed>|null
     */
    private function createAutomationUser(
        string $platformUrl,
        string $accessToken,
        int $chatwootAccountId,
        string $accountName,
        Entity $chatwootAccount
    ): ?array {
        try {
            // Generate automation user credentials
            $email = 'automation.' . $chatwootAccountId . '@chatwoot.local';
            $name = 'Automation User - ' . $accountName;
            
            // Generate password meeting Chatwoot requirements:
            // - At least 1 uppercase (A-Z)
            // - At least 1 special character
            // - Minimum 6 characters
            $password = $this->generateSecurePassword();

            // Create user via Platform API
            $userData = [
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'custom_attributes' => [
                    'type' => 'automation',
                    'created_by' => 'espocrm',
                    'account_id' => $chatwootAccountId
                ]
            ];

            $userResponse = $this->apiClient->createUser($platformUrl, $accessToken, $userData);

            if (!isset($userResponse['id'])) {
                $this->log->error('Failed to create automation user: missing user ID in response');
                return null;
            }

            $chatwootUserId = $userResponse['id'];

            // Attach user to account as administrator
            $this->apiClient->attachUserToAccount(
                $platformUrl,
                $accessToken,
                $chatwootAccountId,
                $chatwootUserId,
                'administrator'
            );

            $this->log->info("Created automation user (ID: $chatwootUserId) for account $chatwootAccountId");

            // Create corresponding ChatwootUser entity in EspoCRM
            // This ensures proper Team-based access control
            $chatwootUser = $this->createChatwootUserEntity(
                $chatwootAccount,
                $chatwootUserId,
                $name,
                $email,
                $password
            );

            // Try to get the user's login token/access token
            // Note: Platform API may not directly return Application API tokens
            // In that case, we'll need to use the Platform token with proper permissions
            
            // Return user info - access_token might not be available
            return [
                'user_id' => $chatwootUserId,
                'espocrm_user_id' => $chatwootUser ? $chatwootUser->getId() : null,
                'email' => $email,
                'access_token' => $userResponse['access_token'] ?? null
            ];

        } catch (\Exception $e) {
            $this->log->error('Failed to create automation user: ' . $e->getMessage());
            // Don't fail the entire account creation if automation user fails
            return null;
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

    /**
     * Generate a secure password meeting Chatwoot requirements.
     * Requirements:
     * - At least 1 uppercase character (A-Z)
     * - At least 1 special character (!@#$%^&*()_+-=[]{}|"/\.,`<>:;?~')
     * - Minimum 6 characters
     *
     * @return string
     */
    private function generateSecurePassword(): string
    {
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $numbers = '0123456789';
        $special = '!@#$%^&*()_+-=[]{}';
        
        // Build password with guaranteed character types
        $password = '';
        
        // At least 2 uppercase
        $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        
        // At least 2 special characters
        $password .= $special[random_int(0, strlen($special) - 1)];
        $password .= $special[random_int(0, strlen($special) - 1)];
        
        // Fill rest with random mix (total 20 characters)
        $allChars = $lowercase . $uppercase . $numbers . $special;
        for ($i = 0; $i < 16; $i++) {
            $password .= $allChars[random_int(0, strlen($allChars) - 1)];
        }
        
        // Shuffle to randomize position of required characters
        $password = str_shuffle($password);
        
        return $password;
    }
}

