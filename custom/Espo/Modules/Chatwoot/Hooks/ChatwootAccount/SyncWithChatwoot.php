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

use Espo\Core\Exceptions\Error;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Hook to synchronize ChatwootAccount with Chatwoot Platform API.
 * Creates BOTH account AND automation user on Chatwoot BEFORE saving to database.
 * This ensures the database only contains accounts that fully exist in Chatwoot.
 */
class SyncWithChatwoot
{
    public static int $order = 10; // Run after ValidateBeforeSync

    /**
     * Static cache to pass automation user data between beforeSave and afterSave hooks.
     * Using static cache because entity transient data may be lost when EspoCRM
     * refreshes the entity from database after insert.
     * 
     * @var array<string, array<string, mixed>>
     */
    public static array $automationUserDataCache = [];

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    /**
     * Create account AND automation user on Chatwoot BEFORE entity is saved to database.
     * The entity will be populated with all Chatwoot data before the INSERT.
     * If either operation fails, an exception is thrown and nothing is saved.
     * Only runs on entity creation.
     * 
     * @param Entity $entity
     * @param array<string, mixed> $options
     * @throws Error
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        // Only run for new ChatwootAccount records (not updates)
        if (!$entity->isNew()) {
            return;
        }

        // For NEW entities: ALWAYS sync with Chatwoot
        // We don't skip even if chatwootAccountId is set, because a new entity
        // should never have a chatwootAccountId unless it's been properly synced
        
        // If this is an update and already has chatwootAccountId, nothing to do
        // (this is handled by the isNew() check above)

        // Validate platform is linked (should have been caught by ValidateBeforeSync)
        $platformId = $entity->get('platformId');
        if (!$platformId) {
            throw new Error('Platform is required for ChatwootAccount.');
        }

        $createdAccountId = null;
        $createdUserId = null;

        try {
            // Get the platform entity
            $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
            
            if (!$platform) {
                throw new Error('ChatwootPlatform not found: ' . $platformId);
            }

            // Get platform URL and access token
            $platformUrl = $platform->get('backendUrl');
            $accessToken = $platform->get('accessToken');

            if (!$platformUrl) {
                throw new Error('ChatwootPlatform does not have a URL configured.');
            }

            if (!$accessToken) {
                throw new Error('ChatwootPlatform does not have an access token.');
            }

            // STEP 1: Create account on Chatwoot
            $this->log->info('Creating Chatwoot account: ' . $entity->get('name'));
            
            $accountData = $this->prepareAccountData($entity);
            $accountResponse = $this->apiClient->createAccount($platformUrl, $accessToken, $accountData);

            if (!isset($accountResponse['id'])) {
                throw new Error('Chatwoot API response missing account ID.');
            }

            $chatwootAccountId = $accountResponse['id'];
            $createdAccountId = $chatwootAccountId;
            
            $this->log->info('Chatwoot account created successfully with ID: ' . $chatwootAccountId);

            // STEP 2: Create automation user for this account
            $this->log->info('Creating automation user for account: ' . $chatwootAccountId);
            
            $automationUser = $this->createAutomationUser(
                $platformUrl,
                $accessToken,
                $chatwootAccountId,
                $entity->get('name')
            );

            if (!$automationUser) {
                throw new Error('Failed to create automation user for account.');
            }

            $createdUserId = $automationUser['user_id'];

            // STEP 3: Set all data on entity BEFORE database insert
            $entity->set('chatwootAccountId', $chatwootAccountId);
            
            if (isset($automationUser['access_token'])) {
                $entity->set('apiKey', $automationUser['access_token']);
                $this->log->info('Automation user created with access token for account: ' . $chatwootAccountId);
            } else {
                $this->log->warning(
                    'Automation user created but no access token received. ' .
                    'Manual API Key configuration needed. Login email: ' . $automationUser['email']
                );
            }

            // Store data for afterSave hook to create ChatwootUser entity and link it
            // Using static cache because entity transient data may be lost when EspoCRM
            // refreshes the entity from database after insert
            // Include teamId and platformId since they may not be available after refresh
            $automationUser['_teamId'] = $entity->get('teamId');
            $automationUser['_platformId'] = $entity->get('platformId');
            self::$automationUserDataCache[$entity->getId()] = $automationUser;

            $this->log->info('Successfully prepared Chatwoot account and user for database insert');
            
            // FINAL SAFEGUARD: Ensure the entity has the required Chatwoot data
            if (!$entity->get('chatwootAccountId') || !$automationUser['user_id']) {
                throw new Error(
                    'Critical error: Chatwoot account created but entity data not set properly. ' .
                    'Preventing database save to maintain data integrity.'
                );
            }

        } catch (\Exception $e) {
            // ROLLBACK: If anything failed, clean up what we created in Chatwoot
            $this->log->error(
                'Failed to create Chatwoot account/user for ' . $entity->get('name') . 
                ': ' . $e->getMessage()
            );
            
            // Note: Chatwoot doesn't have a transaction system, so we need to manually clean up
            // In production, you might want to implement cleanup logic here
            // For now, we just log and prevent the database insert
            
            if ($createdAccountId) {
                $this->log->error(
                    'Orphaned Chatwoot account created with ID: ' . $createdAccountId . 
                    '. Manual cleanup may be required.'
                );
            }

            // Re-throw - this will prevent the database INSERT from happening
            throw new Error(
                'Failed to create account on Chatwoot: ' . $e->getMessage() . 
                '. The account was not created in EspoCRM to maintain synchronization.'
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
     * Create an automation user for the account.
     *
     * @param string $platformUrl
     * @param string $accessToken
     * @param int $chatwootAccountId
     * @param string $accountName
     * @return array<string, mixed>|null
     * @throws Error
     */
    private function createAutomationUser(
        string $platformUrl,
        string $accessToken,
        int $chatwootAccountId,
        string $accountName
    ): ?array {
        // Generate automation user credentials
        $email = 'automation.' . $chatwootAccountId . '@chatwoot.local';
        $name = 'Automation User - ' . $accountName;
        
        // Generate password meeting Chatwoot requirements
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
            throw new Error('Failed to create automation user: missing user ID in response');
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

        // Return user info including password for ChatwootUser entity creation
        return [
            'user_id' => $chatwootUserId,
            'email' => $email,
            'password' => $password,
            'name' => $name,
            'access_token' => $userResponse['access_token'] ?? null
        ];
    }

    /**
     * Generate a secure password meeting Chatwoot requirements.
     * Requirements:
     * - At least 1 uppercase character (A-Z)
     * - At least 1 lowercase character (a-z)
     * - At least 1 number character (0-9)
     * - At least 1 special character
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
        
        // At least 2 numbers (required by Chatwoot)
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        
        // At least 2 special characters
        $password .= $special[random_int(0, strlen($special) - 1)];
        $password .= $special[random_int(0, strlen($special) - 1)];
        
        // At least 2 lowercase
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        
        // Fill rest with random mix (total 20 characters)
        $allChars = $lowercase . $uppercase . $numbers . $special;
        for ($i = 0; $i < 12; $i++) {
            $password .= $allChars[random_int(0, strlen($allChars) - 1)];
        }
        
        // Shuffle to randomize position of required characters
        $password = str_shuffle($password);
        
        return $password;
    }
}
