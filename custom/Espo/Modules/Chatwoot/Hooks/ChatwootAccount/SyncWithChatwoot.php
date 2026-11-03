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
use Espo\Modules\Chatwoot\Services\ChatwootAccountCreationJournal;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Chatwoot\Services\ConciergeAvatarService;
use Espo\Modules\Chatwoot\Services\ConciergeEmailDomainResolver;
use Espo\Modules\Chatwoot\Services\IntegrationUserNameResolver;

/**
 * Hook to synchronize ChatwootAccount with Chatwoot Platform API.
 * Creates BOTH account AND concierge user on Chatwoot BEFORE saving to database.
 * This ensures the database only contains accounts that fully exist in Chatwoot.
 *
 * Each remote id is recorded in {@see ChatwootAccountCreationJournal} as soon as
 * Chatwoot returns it. When a later step fails, saving again with the same
 * ChatwootAccount id reuses the remote account and concierge user instead of
 * creating new ones.
 */
class SyncWithChatwoot
{
    public static int $order = 10; // Run after ValidateBeforeSync

    /**
     * Static cache to pass concierge user data between beforeSave and afterSave hooks.
     * Using static cache because entity transient data may be lost when EspoCRM
     * refreshes the entity from database after insert.
     * 
     * @var array<string, array<string, mixed>>
     */
    public static array $conciergeUserDataCache = [];

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log,
        private ConciergeAvatarService $conciergeAvatarService,
        private ConciergeEmailDomainResolver $conciergeEmailDomainResolver,
        private IntegrationUserNameResolver $integrationUserNameResolver,
        private ChatwootAccountCreationJournal $creationJournal,
    ) {}

    /**
     * Create account AND concierge user on Chatwoot BEFORE entity is saved to database.
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

            $entityId = (string) $entity->getId();
            $journal = $this->creationJournal->find($entityId);

            // STEP 1: Create account on Chatwoot, or reuse the one an earlier
            // attempt for this same ChatwootAccount id created.
            $chatwootAccountId = (int) ($journal['remoteAccountId'] ?? 0);

            if ($chatwootAccountId > 0) {
                // Throws when Chatwoot can't confirm it; never create a second account.
                $this->apiClient->getAccount($platformUrl, $accessToken, $chatwootAccountId);
                $this->log->info("Resuming Chatwoot account {$chatwootAccountId} for ChatwootAccount {$entityId}.");
            } else {
                $this->log->info('Creating Chatwoot account: ' . $entity->get('name'));

                $accountData = $this->prepareAccountData($entity);
                $accountResponse = $this->apiClient->createAccount($platformUrl, $accessToken, $accountData);

                if (!isset($accountResponse['id'])) {
                    throw new Error('Chatwoot API response missing account ID.');
                }

                $chatwootAccountId = (int) $accountResponse['id'];
                $this->creationJournal->recordAccount($entityId, $chatwootAccountId);

                $this->log->info('Chatwoot account created successfully with ID: ' . $chatwootAccountId);
            }

            $createdAccountId = $chatwootAccountId;

            // STEP 2: Create concierge user for this account, or reuse it.
            $conciergeUserId = (int) ($journal['conciergeUserId'] ?? 0);

            if ($conciergeUserId > 0) {
                $conciergeUser = $this->resumeConciergeUser(
                    $platformUrl,
                    $accessToken,
                    $chatwootAccountId,
                    $conciergeUserId,
                    (string) ($journal['conciergeEmail'] ?? ''),
                    $entity
                );
            } else {
                $this->log->info('Creating concierge user for account: ' . $chatwootAccountId);

                $conciergeUser = $this->createConciergeUser(
                    $platformUrl,
                    $accessToken,
                    $chatwootAccountId,
                    $entity->get('name'),
                    $entity
                );
            }

            if (!$conciergeUser) {
                throw new Error('Failed to create concierge user for account.');
            }

            $createdUserId = $conciergeUser['user_id'];

            // STEP 3: Set all data on entity BEFORE database insert
            $entity->set('chatwootAccountId', $chatwootAccountId);
            
            if (isset($conciergeUser['access_token'])) {
                $entity->set('apiKey', $conciergeUser['access_token']);
                $this->log->info('Concierge user created with access token for account: ' . $chatwootAccountId);
            } else {
                $this->log->warning(
                    'Concierge user created but no access token received. ' .
                    'Manual API Key configuration needed. Login email: ' . $conciergeUser['email']
                );
            }

            // Store data for afterSave hook to create ChatwootUser entity and link it
            // Using static cache because entity transient data may be lost when EspoCRM
            // refreshes the entity from database after insert
            // Include teamsIds and platformId since they may not be available after refresh
            $conciergeUser['_teamsIds'] = $entity->getLinkMultipleIdList('teams');
            $conciergeUser['_platformId'] = $entity->get('platformId');
            self::$conciergeUserDataCache[$entity->getId()] = $conciergeUser;

            $this->log->info('Successfully prepared Chatwoot account and user for database insert');
            
            // FINAL SAFEGUARD: Ensure the entity has the required Chatwoot data
            if (!$entity->get('chatwootAccountId') || !$conciergeUser['user_id']) {
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
            
            // Chatwoot has no transactions. What was created is recorded in the
            // creation journal, so saving again with this ChatwootAccount id
            // resumes instead of creating a second remote account.
            if ($createdAccountId) {
                $this->log->error(
                    'Chatwoot account ' . $createdAccountId . ' is recorded for ChatwootAccount ' .
                    $entity->getId() . '; retry with the same id to resume, or remove it in Chatwoot.'
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
     * Create a concierge user for the account.
     *
     * @param string $platformUrl
     * @param string $accessToken
     * @param int $chatwootAccountId
     * @param string $accountName
     * @param Entity $entity The ChatwootAccount entity (used to resolve tenant slug for email domain)
     * @return array<string, mixed>|null
     * @throws Error
     */
    private function createConciergeUser(
        string $platformUrl,
        string $accessToken,
        int $chatwootAccountId,
        string $accountName,
        Entity $entity
    ): ?array {
        // Generate concierge user credentials
        $email = $this->conciergeEmailDomainResolver->resolveEmail($entity, $chatwootAccountId);
        $name = $this->integrationUserNameResolver->resolve($entity);
        
        // Generate password meeting Chatwoot requirements
        $password = $this->generateSecurePassword();

        // Create user via Platform API
        $userData = [
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'custom_attributes' => [
                'type' => 'concierge',
                'created_by' => 'espocrm',
                'account_id' => $chatwootAccountId
            ]
        ];

        $userResponse = $this->apiClient->createUser($platformUrl, $accessToken, $userData, true);

        if (!isset($userResponse['id'])) {
            throw new Error('Failed to create concierge user: missing user ID in response');
        }

        $chatwootUserId = $userResponse['id'];

        if (($userResponse['existing'] ?? false) === true) {
            // An earlier attempt created this account's concierge but its reply
            // was lost. resumeConciergeUser() verifies that before reusing it.
            return $this->resumeConciergeUser(
                $platformUrl,
                $accessToken,
                $chatwootAccountId,
                (int) $chatwootUserId,
                $email,
                $entity
            );
        }

        $this->creationJournal->recordConcierge((string) $entity->getId(), (int) $chatwootUserId, $email);

        // The integration user can manage account settings, but is never an assignee.
        // WAHA inboxes grant it direct conversation access during provisioning.
        $accountUserResponse = $this->apiClient->attachUserToAccount(
            $platformUrl,
            $accessToken,
            $chatwootAccountId,
            $chatwootUserId,
            'administrator',
            true,
            false
        );

        $this->log->info("Created concierge user (ID: $chatwootUserId) for account $chatwootAccountId");

        $accessToken = $userResponse['access_token'] ?? null;

        // Best-effort: brand the concierge with the Monostax favicon. Failures
        // here never abort concierge creation — avatar is cosmetic.
        $avatarUrl = null;
        if ($accessToken) {
            $avatarUrl = $this->conciergeAvatarService->uploadForConcierge(
                $platformUrl,
                $accessToken,
                (int) $chatwootUserId
            );
        }

        // Return user info including password for ChatwootUser entity creation
        return [
            'user_id' => $chatwootUserId,
            'email' => $email,
            'password' => $password,
            'name' => $name,
            'access_token' => $accessToken,
            'account_user_id' => isset($accountUserResponse['id']) ? (int) $accountUserResponse['id'] : null,
            'avatar_url' => $avatarUrl,
        ];
    }

    /**
     * Reuse the concierge user an earlier attempt created.
     *
     * Only a user Chatwoot shows as this account's concierge is reused: the
     * marker is in custom_attributes, which only the platform API sets. Its
     * password was never stored, so a fresh one is set; the access token is
     * read back from Chatwoot. Attaching to the account is idempotent.
     *
     * @return array<string, mixed>
     * @throws Error
     */
    private function resumeConciergeUser(
        string $platformUrl,
        string $accessToken,
        int $chatwootAccountId,
        int $chatwootUserId,
        string $email,
        Entity $entity
    ): array {
        $this->log->info("Resuming concierge user {$chatwootUserId} for account {$chatwootAccountId}.");

        $user = $this->apiClient->getUser($platformUrl, $accessToken, $chatwootUserId);
        $attributes = is_array($user['custom_attributes'] ?? null) ? $user['custom_attributes'] : [];

        if (($attributes['type'] ?? null) !== 'concierge' || (int) ($attributes['account_id'] ?? 0) !== $chatwootAccountId) {
            throw new Error("Chatwoot user {$chatwootUserId} is not the concierge of account {$chatwootAccountId}.");
        }

        $this->creationJournal->recordConcierge((string) $entity->getId(), $chatwootUserId, $email);
        $password = $this->generateSecurePassword();
        $this->apiClient->updateUser($platformUrl, $accessToken, $chatwootUserId, ['password' => $password]);

        $accountUserResponse = $this->apiClient->attachUserToAccount(
            $platformUrl,
            $accessToken,
            $chatwootAccountId,
            $chatwootUserId,
            'administrator',
            true,
            false
        );

        $userAccessToken = $user['access_token'] ?? null;
        $avatarUrl = null;

        if ($userAccessToken) {
            $avatarUrl = $this->conciergeAvatarService->uploadForConcierge(
                $platformUrl,
                $userAccessToken,
                $chatwootUserId
            );
        }

        return [
            'user_id' => $chatwootUserId,
            'email' => $email !== '' ? $email : (string) ($user['email'] ?? ''),
            'password' => $password,
            'name' => (string) ($user['name'] ?? $this->integrationUserNameResolver->resolve($entity)),
            'access_token' => $userAccessToken,
            'account_user_id' => isset($accountUserResponse['id']) ? (int) $accountUserResponse['id'] : null,
            'avatar_url' => $avatarUrl,
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
