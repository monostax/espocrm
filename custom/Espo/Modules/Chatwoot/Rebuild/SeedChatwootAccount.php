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
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootAccountUserMembershipService;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Chatwoot\Services\ChatwootWahaAppTokenSync;
use Espo\Modules\Chatwoot\Services\ConciergeAvatarService;
use Espo\Modules\Chatwoot\Services\ConciergeEmailDomainResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Rebuild action to seed the default ChatwootAccount.
 * Creates a "Default" ChatwootAccount linked to the default ChatwootPlatform.
 * If the account doesn't exist in Chatwoot, it creates it via the Platform API.
 * Also creates a concierge user for the account and links it via the conciergeUser relationship.
 * Runs automatically during system rebuild (after SeedChatwootPlatform).
 */
class SeedChatwootAccount implements RebuildAction
{
    private const ENTITY_TYPE = 'ChatwootAccount';
    private const DEFAULT_NAME = 'Default';

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private ChatwootAccountUserMembershipService $membershipService,
        private ConciergeAvatarService $conciergeAvatarService,
        private ConciergeEmailDomainResolver $conciergeEmailDomainResolver,
        private ChatwootWahaAppTokenSync $wahaAppTokenSync,
        private Config $config,
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
            
            // If apiKey/concierge linkage is missing or broken, bootstrap concierge user.
            if ($this->needsConciergeBootstrap($existing) && $existing->get('chatwootAccountId')) {
                $conciergeUserData = $this->createConciergeUser(
                    $backendUrl,
                    $accessToken,
                    (int) $existing->get('chatwootAccountId'),
                    self::DEFAULT_NAME,
                    $existing
                );
                
                if ($conciergeUserData) {
                    if (isset($conciergeUserData['access_token'])) {
                        $existing->set('apiKey', $conciergeUserData['access_token']);
                    }
                    
                    // Create ChatwootUser entity and link it
                    $chatwootUser = $this->createChatwootUserEntity(
                        $existing,
                        $platform,
                        $conciergeUserData
                    );
                    
                    if ($chatwootUser) {
                        $existing->set('conciergeUserId', $chatwootUser->getId());
                        $this->ensureConciergeMembership($existing, $chatwootUser, $conciergeUserData);
                    }
                    
                    $this->log->info('SeedChatwootAccount: Added concierge user to existing account');
                }
            }
            
            $this->entityManager->saveEntity($existing, [SaveOption::SKIP_ALL => true]);

            // Ensure webhooks exist (SKIP_ALL bypasses RegisterDeliveryWebhook hook)
            $this->ensureWebhooks($existing);

            // If we just rotated apiKey on the existing account (concierge
            // rebootstrap branch above), push the fresh token out to every
            // WAHA Chatwoot app bound to this account. SKIP_ALL bypassed
            // the PropagateApiKeyToWaha hook, so the seed must do it.
            // No-op when no rotation happened — syncForAccount short-circuits
            // for integrations whose accountToken already matches.
            $this->safeSyncWaha($existing);

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

            // Create concierge user
            $conciergeUserData = $this->createConciergeUser(
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
                'apiKey' => $conciergeUserData['access_token'] ?? null,
                'locale' => 'pt_BR',
                'status' => 'active',
            ], [SaveOption::SKIP_ALL => true]);

            // Create ChatwootUser entity and link it to the account
            if ($conciergeUserData) {
                $chatwootUser = $this->createChatwootUserEntity(
                    $account,
                    $platform,
                    $conciergeUserData
                );
                
                if ($chatwootUser) {
                    $account->set('conciergeUserId', $chatwootUser->getId());
                    $this->ensureConciergeMembership($account, $chatwootUser, $conciergeUserData);
                    $this->entityManager->saveEntity($account, [SaveOption::SKIP_ALL => true]);
                }
            }

            // Register webhooks (SKIP_ALL bypasses RegisterDeliveryWebhook hook)
            $this->ensureWebhooks($account);

            // Fresh account + fresh concierge token. No WAHA apps exist yet
            // for a brand-new account, but run the sync anyway so that if
            // a pre-existing orphan integration happens to point at this
            // account (data migration edge case), it gets rewired.
            $this->safeSyncWaha($account);

            $this->log->info('SeedChatwootAccount: Created default ChatwootAccount with Chatwoot ID: ' . $chatwootAccountId);
        } catch (\Exception $e) {
            $this->log->error('SeedChatwootAccount: Failed to create account - ' . $e->getMessage());
        }
    }

    /**
     * Propagate the account's current apiKey to every WAHA Chatwoot app
     * that belongs to this account. Wrapped so a WAHA hiccup cannot abort
     * the surrounding rebuild step.
     *
     * See `ChatwootWahaAppTokenSync` for rationale. The normal afterSave
     * hook `Hooks\ChatwootAccount\PropagateApiKeyToWaha` is bypassed by
     * the SKIP_ALL saves this class uses, so propagation is done here
     * explicitly. No-op when tokens already match.
     */
    private function safeSyncWaha(Entity $account): void
    {
        try {
            $this->wahaAppTokenSync->syncForAccount($account);
        } catch (\Throwable $e) {
            $this->log->error(
                'SeedChatwootAccount: WAHA app token propagation failed for account ' .
                $account->getId() . ' — ' . $e->getMessage()
            );
        }
    }

    /**
     * Ensure all required webhooks exist for the account.
     *
     * The RegisterDeliveryWebhook afterSave hook is skipped when saving with
     * SKIP_ALL, so the seed script must handle this directly.
     * Idempotent: each webhook is checked by name before creating.
     */
    private function ensureWebhooks(Entity $account): void
    {
        $chatwootAccountId = $account->get('chatwootAccountId');

        if (!$chatwootAccountId) {
            return;
        }

        $this->ensureWebhook(
            $account,
            'WhatsApp Delivery Status',
            $this->buildDeliveryWebhookUrl($chatwootAccountId),
            ['message_updated', 'message_created']
        );

        $this->ensureWebhook(
            $account,
            'Hatchet AI Agent',
            getenv('HATCHET_CHATWOOT_WEBHOOK_URL') ?: null,
            ['message_created']
        );

        $this->ensureWebhook(
            $account,
            'VoIP Call Mirror',
            $this->buildVoipWebhookUrl($chatwootAccountId),
            ['message_created', 'message_updated']
        );
    }

    /**
     * Ensure a single webhook exists for the account.
     * Uses a deterministic per-account ID for idempotency across rebuilds.
     *
     * @param Entity $account The ChatwootAccount entity
     * @param string $name Webhook name
     * @param string|null $url Webhook URL — skipped if null/empty
     * @param array<string> $subscriptions Event subscriptions
     */
    private function ensureWebhook(Entity $account, string $name, ?string $url, array $subscriptions): void
    {
        if (!$url) {
            $this->log->debug("SeedChatwootAccount: Skipping webhook '{$name}' — URL not configured.");
            return;
        }

        $existing = $this->findExistingWebhook($account, $name, $url);

        if ($existing) {
            $changed = false;

            if ($existing->get('url') !== $url) {
                $existing->set('url', $url);
                $changed = true;
            }

            $currentSubscriptions = $existing->get('subscriptions') ?? [];
            if (!$this->sameStringSet($currentSubscriptions, $subscriptions)) {
                $existing->set('subscriptions', $subscriptions);
                $changed = true;
            }

            if ($changed) {
                $this->entityManager->saveEntity($existing);
                $this->log->info(
                    "SeedChatwootAccount: Repaired webhook '{$name}' for account {$account->getId()}"
                );
            }

            return;
        }

        try {
            $webhookId = $this->buildWebhookId($account->getId(), $name);
            $webhook = $this->entityManager->getNewEntity('ChatwootAccountWebhook');
            $webhook->set('id', $webhookId);
            $webhook->set('name', $name);
            $webhook->set('accountId', $account->getId());
            $webhook->set('url', $url);
            $webhook->set('subscriptions', $subscriptions);
            $this->entityManager->saveEntity($webhook);

            $this->log->info(
                "SeedChatwootAccount: Registered webhook '{$name}' (ID: {$webhookId}) for account " .
                "{$account->getId()} at {$url}"
            );
        } catch (\Exception $e) {
            $this->log->error(
                "SeedChatwootAccount: Failed to register webhook '{$name}': {$e->getMessage()}"
            );
        }
    }

    private function buildWebhookId(string $accountId, string $name): string
    {
        return substr(sha1('seed-webhook|' . $accountId . '|' . $name), 0, 17);
    }

    /**
     * @param array<string> $left
     * @param array<string> $right
     */
    private function sameStringSet(array $left, array $right): bool
    {
        sort($left);
        sort($right);

        return $left === $right;
    }

    private function findExistingWebhook(Entity $account, string $name, string $url): ?Entity
    {
        $existing = $this->entityManager
            ->getRDBRepository('ChatwootAccountWebhook')
            ->where([
                'accountId' => $account->getId(),
                'name' => $name,
            ])
            ->findOne();

        if ($existing) {
            return $existing;
        }

        return $this->entityManager
            ->getRDBRepository('ChatwootAccountWebhook')
            ->where([
                'accountId' => $account->getId(),
                'url' => $url,
            ])
            ->findOne();
    }

    /**
     * Build the WhatsApp Delivery webhook URL from CRM backend URL.
     */
    private function buildDeliveryWebhookUrl(int $chatwootAccountId): ?string
    {
        $crmBackendUrl = getenv('CRM_BACKEND_URL') ?: $this->config->get('siteUrl');

        if (!$crmBackendUrl) {
            $this->log->warning(
                'SeedChatwootAccount: Cannot build delivery webhook URL — ' .
                'neither CRM_BACKEND_URL env nor siteUrl config is set.'
            );
            return null;
        }

        return rtrim($crmBackendUrl, '/') . '/api/v1/WhatsAppDeliveryWebhook/' . $chatwootAccountId;
    }

    private function buildVoipWebhookUrl(int $chatwootAccountId): ?string
    {
        $crmBackendUrl = getenv('CRM_BACKEND_URL') ?: $this->config->get('siteUrl');

        if (!$crmBackendUrl) {
            $this->log->warning(
                'SeedChatwootAccount: Cannot build VoIP webhook URL — ' .
                'neither CRM_BACKEND_URL env nor siteUrl config is set.'
            );
            return null;
        }

        return rtrim($crmBackendUrl, '/') . '/api/v1/VoipWebhook/' . $chatwootAccountId;
    }

    /**
     * Create a concierge user for the account.
     * Uses the same naming convention as the SyncWithChatwoot hook.
     *
     * @param string $backendUrl
     * @param string $platformAccessToken
     * @param int $chatwootAccountId
     * @param string $accountName
     * @param Entity|null $account The ChatwootAccount entity (used to resolve tenant slug for email domain), or null if not yet created
     * @return array<string, mixed>|null User data including access_token, or null on failure
     */
    private function createConciergeUser(
        string $backendUrl,
        string $platformAccessToken,
        int $chatwootAccountId,
        string $accountName,
        ?Entity $account = null
    ): ?array {
        // Use the same naming convention as SyncWithChatwoot hook
        $email = $this->conciergeEmailDomainResolver->resolveEmail($account, $chatwootAccountId);
        $name = '✦ Concierge (Monostax)';
        $password = $this->generateSecurePassword();

        try {
            // Create the user via Platform API
            $userResponse = $this->apiClient->createUser($backendUrl, $platformAccessToken, [
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'custom_attributes' => [
                    'type' => 'concierge',
                    'created_by' => 'espocrm',
                    'account_id' => $chatwootAccountId
                ],
            ]);

            $chatwootUserId = $userResponse['id'] ?? null;

            if (!$chatwootUserId) {
                $this->log->error('SeedChatwootAccount: Failed to create concierge user - no ID returned');
                return null;
            }

            // Add user to account as administrator
            $accountUserResponse = $this->apiClient->attachUserToAccount(
                $backendUrl,
                $platformAccessToken,
                $chatwootAccountId,
                $chatwootUserId,
                'administrator'
            );

            $this->log->info("SeedChatwootAccount: Created concierge user (ID: {$chatwootUserId}) for account {$chatwootAccountId}");

            $userAccessToken = $userResponse['access_token'] ?? null;

            // Best-effort avatar branding. See ConciergeAvatarService docs.
            $avatarUrl = null;
            if ($userAccessToken) {
                $avatarUrl = $this->conciergeAvatarService->uploadForConcierge(
                    $backendUrl,
                    $userAccessToken,
                    (int) $chatwootUserId
                );
            }

            return [
                'user_id' => $chatwootUserId,
                'email' => $email,
                'password' => $password,
                'name' => $name,
                'access_token' => $userAccessToken,
                'account_user_id' => isset($accountUserResponse['id']) ? (int) $accountUserResponse['id'] : null,
                'avatar_url' => $avatarUrl,
            ];
        } catch (\Exception $e) {
            // User might already exist
            if (str_contains($e->getMessage(), 'already been taken') || str_contains($e->getMessage(), 'already exists')) {
                $this->log->info('SeedChatwootAccount: Concierge user already exists, skipping creation');
                return null;
            }
            $this->log->error('SeedChatwootAccount: Failed to create concierge user - ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create ChatwootUser entity in EspoCRM for the concierge user.
     *
     * @param \Espo\ORM\Entity $account
     * @param \Espo\ORM\Entity $platform
     * @param array<string, mixed> $conciergeUserData
     * @return \Espo\ORM\Entity|null
     */
    private function createChatwootUserEntity($account, $platform, array $conciergeUserData): ?\Espo\ORM\Entity
    {
        try {
            // Get Teams from the ChatwootAccount for tenant isolation
            $teamsIds = $account->getLinkMultipleIdList('teams');

            // Create the ChatwootUser entity
            $attributes = [
                'name' => $conciergeUserData['name'],
                'email' => $conciergeUserData['email'],
                'password' => $conciergeUserData['password'],
                'displayName' => $conciergeUserData['name'],
                'platformId' => $platform->getId(),
                'chatwootUserId' => $conciergeUserData['user_id'],
                'teamsIds' => $teamsIds
            ];

            // Persist the user's personal access_token so the bi-directional
            // avatar sync (AgentAvatarSyncService) can hit /api/v1/profile
            // without re-fetching via the Platform API. Optional — the sync
            // lazily refetches when missing.
            $userAccessToken = $conciergeUserData['access_token'] ?? null;
            if (is_string($userAccessToken) && $userAccessToken !== '') {
                $attributes['userAccessToken'] = $userAccessToken;
            }

            $chatwootUser = $this->entityManager->createEntity('ChatwootUser', $attributes, [
                'skipHooks' => true,
                'silent' => true
            ]);

            $this->log->info(
                'SeedChatwootAccount: Created ChatwootUser entity for concierge user: ' .
                $chatwootUser->getId()
            );

            return $chatwootUser;

        } catch (\Exception $e) {
            $this->log->error('SeedChatwootAccount: Failed to create ChatwootUser entity: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Ensure concierge user has a membership so it is not treated as orphan.
     *
     * @param array<string, mixed> $conciergeUserData
     */
    private function ensureConciergeMembership(Entity $account, Entity $chatwootUser, array $conciergeUserData): void
    {
        try {
            $accountUserId = isset($conciergeUserData['account_user_id'])
                ? (int) $conciergeUserData['account_user_id']
                : null;

            $membership = $this->membershipService->upsertMembership(
                $account->getId(),
                $chatwootUser->getId(),
                'administrator',
                $accountUserId,
                true // isAI — concierge memberships are AI-enabled by default
            );

            // Proactively stamp the avatar URL we just uploaded to Chatwoot.
            // See ConciergeAvatarService docs for why this matters.
            $avatarUrl = $conciergeUserData['avatar_url'] ?? null;
            if ($avatarUrl && $membership && !$membership->get('avatarUrl')) {
                $membership->set('avatarUrl', $avatarUrl);
                $this->entityManager->saveEntity($membership, ['silent' => true]);
            }
        } catch (\Throwable $e) {
            $this->log->warning(
                'SeedChatwootAccount: Failed to ensure concierge membership: ' . $e->getMessage()
            );
        }
    }

    private function needsConciergeBootstrap(Entity $account): bool
    {
        if (!$account->get('apiKey')) {
            return true;
        }

        $conciergeUserId = $account->get('conciergeUserId');
        if (!$conciergeUserId) {
            return true;
        }

        $conciergeUser = $this->entityManager->getEntityById('ChatwootUser', $conciergeUserId);
        if (!$conciergeUser) {
            return true;
        }

        $membership = $this->entityManager
            ->getRDBRepository('ChatwootAccountUserMembership')
            ->where([
                'chatwootAccountId' => $account->getId(),
                'chatwootUserId' => $conciergeUserId,
            ])
            ->findOne();

        return !$membership;
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
