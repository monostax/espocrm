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

namespace Espo\Modules\Chatwoot\Rebuild;

use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\ORM\EntityManager;

/**
 * Rebuild action to seed the default ChatwootAccount.
 * Creates a "Default" ChatwootAccount linked to the default ChatwootPlatform.
 * If the account doesn't exist in Chatwoot, it creates it via the Platform API.
 * Also creates an automation user for the account and links it via the automationUser relationship.
 * Runs automatically during system rebuild (after SeedChatwootPlatform).
 */
class SeedChatwootAccount implements RebuildAction
{
    private const ENTITY_TYPE = 'ChatwootAccount';
    private const DEFAULT_NAME = 'Default';

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    public function process(): void
    {
        // Find default platform
        $platform = $this->entityManager
            ->getRDBRepository('ChatwootPlatform')
            ->where(['isDefault' => true])
            ->findOne();

        if (!$platform) {
            $this->log->debug('SeedChatwootAccount: No default platform found, skipping');
            return;
        }

        $backendUrl = $platform->get('backendUrl');
        $accessToken = $platform->get('accessToken');

        if (!$backendUrl || !$accessToken) {
            $this->log->debug('SeedChatwootAccount: Platform missing credentials, skipping');
            return;
        }

        $this->upsertAccount($platform, $backendUrl, $accessToken);
    }

    /**
     * Create or update the default ChatwootAccount.
     *
     * @param \Espo\ORM\Entity $platform
     * @param string $backendUrl
     * @param string $accessToken Platform access token
     */
    private function upsertAccount($platform, string $backendUrl, string $accessToken): void
    {
        $existing = $this->entityManager
            ->getRDBRepository(self::ENTITY_TYPE)
            ->where(['name' => self::DEFAULT_NAME])
            ->findOne();

        if ($existing) {
            // Update to ensure linked to default platform
            $existing->set('platformId', $platform->getId());
            
            // If apiKey or automationUser is missing, try to create automation user
            if ((!$existing->get('apiKey') || !$existing->get('automationUserId')) && $existing->get('chatwootAccountId')) {
                $automationUserData = $this->createAutomationUser(
                    $backendUrl,
                    $accessToken,
                    (int) $existing->get('chatwootAccountId'),
                    self::DEFAULT_NAME
                );
                
                if ($automationUserData) {
                    if (isset($automationUserData['access_token'])) {
                        $existing->set('apiKey', $automationUserData['access_token']);
                    }
                    
                    // Create ChatwootUser entity and link it
                    $chatwootUser = $this->createChatwootUserEntity(
                        $existing,
                        $platform,
                        $automationUserData
                    );
                    
                    if ($chatwootUser) {
                        $existing->set('automationUserId', $chatwootUser->getId());
                    }
                    
                    $this->log->info('SeedChatwootAccount: Added automation user to existing account');
                }
            }
            
            $this->entityManager->saveEntity($existing, [SaveOption::SKIP_ALL => true]);
            $this->log->info('SeedChatwootAccount: Updated default ChatwootAccount');
            return;
        }

        try {
            // Create account via Chatwoot API
            $response = $this->apiClient->createAccount($backendUrl, $accessToken, [
                'name' => self::DEFAULT_NAME,
                'locale' => 'pt_BR',
                'status' => 'active',
            ]);

            if (!isset($response['id'])) {
                $this->log->error('SeedChatwootAccount: Failed to create account - no ID returned');
                return;
            }

            $chatwootAccountId = (int) $response['id'];

            // Create automation user
            $automationUserData = $this->createAutomationUser(
                $backendUrl,
                $accessToken,
                $chatwootAccountId,
                self::DEFAULT_NAME
            );

            // Create the ChatwootAccount entity first
            $account = $this->entityManager->createEntity(self::ENTITY_TYPE, [
                'name' => self::DEFAULT_NAME,
                'platformId' => $platform->getId(),
                'chatwootAccountId' => $chatwootAccountId,
                'apiKey' => $automationUserData['access_token'] ?? null,
                'locale' => 'pt_BR',
                'status' => 'active',
            ], [SaveOption::SKIP_ALL => true]);

            // Create ChatwootUser entity and link it to the account
            if ($automationUserData) {
                $chatwootUser = $this->createChatwootUserEntity(
                    $account,
                    $platform,
                    $automationUserData
                );
                
                if ($chatwootUser) {
                    $account->set('automationUserId', $chatwootUser->getId());
                    $this->entityManager->saveEntity($account, [SaveOption::SKIP_ALL => true]);
                }
            }

            $this->log->info('SeedChatwootAccount: Created default ChatwootAccount with Chatwoot ID: ' . $chatwootAccountId);
        } catch (\Exception $e) {
            $this->log->error('SeedChatwootAccount: Failed to create account - ' . $e->getMessage());
        }
    }

    /**
     * Create an automation user for the account.
     * Uses the same naming convention as the SyncWithChatwoot hook.
     *
     * @param string $backendUrl
     * @param string $platformAccessToken
     * @param int $chatwootAccountId
     * @param string $accountName
     * @return array<string, mixed>|null User data including access_token, or null on failure
     */
    private function createAutomationUser(
        string $backendUrl,
        string $platformAccessToken,
        int $chatwootAccountId,
        string $accountName
    ): ?array {
        // Use the same naming convention as SyncWithChatwoot hook
        $email = 'automation.' . $chatwootAccountId . '@chatwoot.local';
        $name = 'Automation User - ' . $accountName;
        $password = $this->generateSecurePassword();

        try {
            // Create the user via Platform API
            $userResponse = $this->apiClient->createUser($backendUrl, $platformAccessToken, [
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'custom_attributes' => [
                    'type' => 'automation',
                    'created_by' => 'espocrm',
                    'account_id' => $chatwootAccountId
                ],
            ]);

            $chatwootUserId = $userResponse['id'] ?? null;

            if (!$chatwootUserId) {
                $this->log->error('SeedChatwootAccount: Failed to create automation user - no ID returned');
                return null;
            }

            // Add user to account as administrator
            $this->apiClient->attachUserToAccount(
                $backendUrl,
                $platformAccessToken,
                $chatwootAccountId,
                $chatwootUserId,
                'administrator'
            );

            $this->log->info("SeedChatwootAccount: Created automation user (ID: {$chatwootUserId}) for account {$chatwootAccountId}");

            return [
                'user_id' => $chatwootUserId,
                'email' => $email,
                'password' => $password,
                'name' => $name,
                'access_token' => $userResponse['access_token'] ?? null
            ];
        } catch (\Exception $e) {
            // User might already exist
            if (str_contains($e->getMessage(), 'already been taken') || str_contains($e->getMessage(), 'already exists')) {
                $this->log->info('SeedChatwootAccount: Automation user already exists, skipping creation');
                return null;
            }
            $this->log->error('SeedChatwootAccount: Failed to create automation user - ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create ChatwootUser entity in EspoCRM for the automation user.
     *
     * @param \Espo\ORM\Entity $account
     * @param \Espo\ORM\Entity $platform
     * @param array<string, mixed> $automationUserData
     * @return \Espo\ORM\Entity|null
     */
    private function createChatwootUserEntity($account, $platform, array $automationUserData): ?\Espo\ORM\Entity
    {
        try {
            // Get Teams from the ChatwootAccount for tenant isolation
            $teamsIds = $account->getLinkMultipleIdList('teams');

            // Create the ChatwootUser entity
            $chatwootUser = $this->entityManager->createEntity('ChatwootUser', [
                'name' => $automationUserData['name'],
                'email' => $automationUserData['email'],
                'password' => $automationUserData['password'],
                'displayName' => $automationUserData['name'],
                'platformId' => $platform->getId(),
                'chatwootAccountId' => $account->getId(),
                'chatwootUserId' => $automationUserData['user_id'],
                'teamsIds' => $teamsIds
            ], [
                'skipHooks' => true,
                'silent' => true
            ]);

            $this->log->info(
                'SeedChatwootAccount: Created ChatwootUser entity for automation user: ' .
                $chatwootUser->getId()
            );

            return $chatwootUser;

        } catch (\Exception $e) {
            $this->log->error('SeedChatwootAccount: Failed to create ChatwootUser entity: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate a secure password meeting Chatwoot requirements.
     * Same logic as SyncWithChatwoot hook.
     *
     * @return string
     */
    private function generateSecurePassword(): string
    {
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $numbers = '0123456789';
        $special = '!@#$%^&*()_+-=[]{}';
        
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
