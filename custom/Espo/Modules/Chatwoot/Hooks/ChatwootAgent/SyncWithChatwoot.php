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

use Espo\Core\Exceptions\Error;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Hook to synchronize ChatwootAgent with Chatwoot Account API.
 * Creates or updates agent on Chatwoot BEFORE saving to database.
 * 
 * If a password is provided, it will first create a ChatwootUser via Platform API
 * (which sets up the user with credentials), then create the agent.
 * This ensures the agent is confirmed and can login immediately.
 */
class SyncWithChatwoot
{
    public static int $order = 10; // Run after ValidateBeforeSync

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    /**
     * Create or update agent on Chatwoot BEFORE entity is saved to database.
     * 
     * @param Entity $entity
     * @param array<string, mixed> $options
     * @throws Error
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        // Skip if this is a silent save (from sync job)
        if (!empty($options['silent'])) {
            return;
        }

        $isNew = $entity->isNew();
        $chatwootAgentId = $entity->get('chatwootAgentId');

        // Skip if no account is linked
        $accountId = $entity->get('chatwootAccountId');
        if (!$accountId) {
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

            // Get API key from account
            $apiKey = $account->get('apiKey');
            if (!$apiKey) {
                throw new Error('ChatwootAccount does not have an API key.');
            }

            // Get platform from account
            $platformId = $account->get('platformId');
            $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
            
            if (!$platform) {
                throw new Error('ChatwootPlatform not found: ' . $platformId);
            }

            $platformUrl = $platform->get('backendUrl');
            if (!$platformUrl) {
                throw new Error('ChatwootPlatform does not have a URL.');
            }

            // Get platform access token for user creation
            $platformAccessToken = $platform->get('accessToken');

            if ($isNew && !$chatwootAgentId) {
                // Check if password is provided - if so, create ChatwootUser first
                $password = $entity->get('password');
                
                if ($password && $platformAccessToken) {
                    // Create ChatwootUser first via Platform API
                    $chatwootUser = $this->createChatwootUserFirst(
                        $entity,
                        $platformUrl,
                        $platformAccessToken,
                        $platformId,
                        $chatwootAccountId,
                        $password
                    );
                    
                    if ($chatwootUser) {
                        $entity->set('chatwootUserId', $chatwootUser->getId());
                    }
                }
                
                // Create new agent on Chatwoot
                $this->createAgentOnChatwoot($entity, $platformUrl, $apiKey, $chatwootAccountId);
            } elseif ($chatwootAgentId) {
                // Update existing agent on Chatwoot (only certain fields can be updated)
                $this->updateAgentOnChatwoot($entity, $platformUrl, $apiKey, $chatwootAccountId, $chatwootAgentId);
            }

            // Mark as synced
            $entity->set('syncStatus', 'synced');
            $entity->set('lastSyncedAt', date('Y-m-d H:i:s'));
            $entity->set('lastSyncError', null);

        } catch (\Exception $e) {
            $this->log->error(
                'Failed to sync ChatwootAgent to Chatwoot: ' . $e->getMessage()
            );

            // Mark as error but allow save to continue
            $entity->set('syncStatus', 'error');
            $entity->set('lastSyncError', $e->getMessage());
            $entity->set('lastSyncedAt', date('Y-m-d H:i:s'));

            // For new agents, we should throw to prevent orphaned records
            if ($isNew && !$chatwootAgentId) {
                throw new Error(
                    'Failed to create agent on Chatwoot: ' . $e->getMessage() . 
                    '. The agent was not created in EspoCRM to maintain synchronization.'
                );
            }
        }
    }

    /**
     * Create a ChatwootUser via Platform API before creating the agent.
     * This ensures the user has credentials and can login immediately.
     * ChatwootUser is platform-level, not account-level.
     * 
     * @return Entity|null The created ChatwootUser entity, or null if creation failed
     */
    private function createChatwootUserFirst(
        Entity $agentEntity,
        string $platformUrl,
        string $platformAccessToken,
        string $platformId,
        int $chatwootAccountId,
        string $password
    ): ?Entity {
        $email = $agentEntity->get('email');
        $name = $agentEntity->get('name');
        $role = $agentEntity->get('role') ?? 'agent';

        $this->log->info('Creating ChatwootUser first for agent: ' . $email);

        try {
            // Check if ChatwootUser already exists for this email in this platform
            $existingUser = $this->entityManager
                ->getRDBRepository('ChatwootUser')
                ->where([
                    'email' => $email,
                    'platformId' => $platformId,
                ])
                ->findOne();

            if ($existingUser) {
                $this->log->info('ChatwootUser already exists for email ' . $email . ', using existing user');
                
                // Attach existing user to this account
                $chatwootUserId = $existingUser->get('chatwootUserId');
                if ($chatwootUserId) {
                    $this->apiClient->attachUserToAccount(
                        $platformUrl,
                        $platformAccessToken,
                        $chatwootAccountId,
                        $chatwootUserId,
                        $role
                    );
                    $this->log->info('Existing user attached to account: ' . $chatwootAccountId);
                }
                
                $agentEntity->set('confirmed', true);
                return $existingUser;
            }

            // Step 1: Create user on Chatwoot Platform API
            $userData = [
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'custom_attributes' => []
            ];

            $userResponse = $this->apiClient->createUser($platformUrl, $platformAccessToken, $userData);

            if (!isset($userResponse['id'])) {
                throw new Error('Chatwoot API response missing user ID.');
            }

            $chatwootUserId = $userResponse['id'];
            $this->log->info('Chatwoot user created with ID: ' . $chatwootUserId);

            // Step 2: Attach user to account
            $this->apiClient->attachUserToAccount(
                $platformUrl,
                $platformAccessToken,
                $chatwootAccountId,
                $chatwootUserId,
                $role
            );
            $this->log->info('User attached to account: ' . $chatwootAccountId);

            // Step 3: Create ChatwootUser entity in EspoCRM (platform-level, not account-level)
            $chatwootUser = $this->entityManager->createEntity('ChatwootUser', [
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'platformId' => $platformId,
                'chatwootUserId' => $chatwootUserId,
            ], ['silent' => true]);

            $this->log->info('ChatwootUser entity created: ' . $chatwootUser->getId());

            // The agent will be confirmed since the user exists
            $agentEntity->set('confirmed', true);

            return $chatwootUser;

        } catch (\Exception $e) {
            $this->log->warning(
                'Failed to create ChatwootUser for agent ' . $email . ': ' . $e->getMessage() .
                '. Agent will be created without linked user (confirmation email will be sent).'
            );
            return null;
        }
    }

    /**
     * Create a new agent on Chatwoot, or link to existing one if already exists.
     */
    private function createAgentOnChatwoot(
        Entity $entity,
        string $platformUrl,
        string $apiKey,
        int $chatwootAccountId
    ): void {
        $email = $entity->get('email');
        $this->log->info('Creating/linking Chatwoot agent: ' . $email);

        // First, check if an agent with this email already exists in Chatwoot
        $existingAgent = $this->findExistingAgentByEmail($platformUrl, $apiKey, $chatwootAccountId, $email);
        
        if ($existingAgent) {
            $this->log->info('Found existing Chatwoot agent with ID: ' . $existingAgent['id'] . ', linking instead of creating');
            $this->populateEntityFromChatwootAgent($entity, $existingAgent);
            return;
        }

        // Agent doesn't exist, create new one
        $agentData = [
            'name' => $entity->get('name'),
            'email' => $email,
            'role' => $entity->get('role') ?? 'agent',
        ];

        // Add optional fields
        if ($entity->get('availabilityStatus')) {
            $agentData['availability_status'] = $entity->get('availabilityStatus');
        }

        if ($entity->has('autoOffline')) {
            $agentData['auto_offline'] = $entity->get('autoOffline');
        }

        $response = $this->apiClient->createAgent(
            $platformUrl,
            $apiKey,
            $chatwootAccountId,
            $agentData
        );

        if (!isset($response['id'])) {
            throw new Error('Chatwoot API response missing agent ID.');
        }

        $this->populateEntityFromChatwootAgent($entity, $response);
        $this->log->info('Chatwoot agent created successfully with ID: ' . $response['id']);
    }

    /**
     * Find an existing agent by email in Chatwoot.
     * 
     * @return array|null The agent data if found, null otherwise
     */
    private function findExistingAgentByEmail(
        string $platformUrl,
        string $apiKey,
        int $chatwootAccountId,
        string $email
    ): ?array {
        try {
            $agents = $this->apiClient->listAgents($platformUrl, $apiKey, $chatwootAccountId);
            
            foreach ($agents as $agent) {
                if (isset($agent['email']) && strtolower($agent['email']) === strtolower($email)) {
                    return $agent;
                }
            }
        } catch (\Exception $e) {
            $this->log->warning('Failed to list agents from Chatwoot: ' . $e->getMessage());
        }
        
        return null;
    }

    /**
     * Populate entity fields from Chatwoot agent data.
     */
    private function populateEntityFromChatwootAgent(Entity $entity, array $chatwootAgent): void
    {
        $entity->set('chatwootAgentId', $chatwootAgent['id']);
        
        if (isset($chatwootAgent['available_name'])) {
            $entity->set('availableName', $chatwootAgent['available_name']);
        }
        if (isset($chatwootAgent['confirmed'])) {
            $entity->set('confirmed', $chatwootAgent['confirmed']);
        }
        if (isset($chatwootAgent['thumbnail'])) {
            $entity->set('avatarUrl', $chatwootAgent['thumbnail']);
        }
        if (isset($chatwootAgent['custom_role_id'])) {
            $entity->set('customRoleId', $chatwootAgent['custom_role_id']);
        }
        if (isset($chatwootAgent['role'])) {
            $entity->set('role', $chatwootAgent['role']);
        }
        if (isset($chatwootAgent['availability_status'])) {
            $entity->set('availabilityStatus', $chatwootAgent['availability_status']);
        }
        if (isset($chatwootAgent['auto_offline'])) {
            $entity->set('autoOffline', $chatwootAgent['auto_offline']);
        }
    }

    /**
     * Update an existing agent on Chatwoot.
     * Note: Chatwoot Agent update API only allows updating role, availability_status, and auto_offline.
     */
    private function updateAgentOnChatwoot(
        Entity $entity,
        string $platformUrl,
        string $apiKey,
        int $chatwootAccountId,
        int $chatwootAgentId
    ): void {
        // Check if relevant fields have changed
        $fieldsToCheck = ['role', 'availabilityStatus', 'autoOffline'];
        $hasChanges = false;
        
        foreach ($fieldsToCheck as $field) {
            if ($entity->isAttributeChanged($field)) {
                $hasChanges = true;
                break;
            }
        }

        if (!$hasChanges) {
            return;
        }

        $this->log->info('Updating Chatwoot agent: ' . $chatwootAgentId);

        $agentData = [
            'role' => $entity->get('role') ?? 'agent',
        ];

        if ($entity->get('availabilityStatus')) {
            $agentData['availability_status'] = $entity->get('availabilityStatus');
        }

        if ($entity->has('autoOffline')) {
            $agentData['auto_offline'] = $entity->get('autoOffline');
        }

        $response = $this->apiClient->updateAgent(
            $platformUrl,
            $apiKey,
            $chatwootAccountId,
            $chatwootAgentId,
            $agentData
        );

        // Update fields from response
        if (isset($response['available_name'])) {
            $entity->set('availableName', $response['available_name']);
        }
        if (isset($response['confirmed'])) {
            $entity->set('confirmed', $response['confirmed']);
        }
        if (isset($response['thumbnail'])) {
            $entity->set('avatarUrl', $response['thumbnail']);
        }
        if (isset($response['availability_status'])) {
            $entity->set('availabilityStatus', $response['availability_status']);
        }

        $this->log->info('Chatwoot agent updated successfully: ' . $chatwootAgentId);
    }
}
