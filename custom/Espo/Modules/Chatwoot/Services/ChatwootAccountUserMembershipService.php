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

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Centralized service for ChatwootAccountUserMembership upsert, resolution,
 * and Chatwoot Platform API sync.
 *
 * All agent-specific fields (isAI, aiPrompt, email, availableName, etc.)
 * now live directly on the membership entity.
 *
 * Uses constructor DI (no binding config needed; InjectableFactory auto-resolves).
 */
class ChatwootAccountUserMembershipService
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
        private ChatwootApiClient $apiClient
    ) {}

    /**
     * Find-or-create a membership by (chatwootAccountId, chatwootUserId).
     *
     * - If not found: creates entity with name, role, syncStatus='synced', teams from account.
     * - If found: updates role if changed.
     * - Saves with ['silent' => true] to avoid triggering API sync hooks.
     *
     * @param string $accountId EspoCRM ChatwootAccount entity ID
     * @param string $userId    EspoCRM ChatwootUser entity ID
     * @param string $role      'agent' or 'administrator'
     * @param int|null $chatwootAccountUserId Chatwoot account_user ID (optional)
     * @param bool|null $isAI   When non-null, sets the membership's `isAI` flag. Pass `null`
     *                          (default) to leave it at the entity default (false) on create
     *                          and untouched on update. Pass `true` for concierge/AI-agent
     *                          memberships.
     * @param bool|null $globalAdmin When non-null, sets the membership's `globalAdmin` flag
     *                          (mirror of Chatwoot `account_users.global_admin` — account-wide
     *                          inbox visibility for administrators). Pass `null` to leave the
     *                          entity default (false) on create and untouched on update.
     * @return Entity The upserted ChatwootAccountUserMembership entity
     */
    public function upsertMembership(
        string $accountId,
        string $userId,
        string $role,
        ?int $chatwootAccountUserId = null,
        ?bool $isAI = null,
        ?bool $globalAdmin = null
    ): Entity {
        $existing = $this->entityManager
            ->getRDBRepository('ChatwootAccountUserMembership')
            ->where([
                'chatwootAccountId' => $accountId,
                'chatwootUserId' => $userId,
            ])
            ->findOne();

        if (!$existing) {
            return $this->createMembership($accountId, $userId, $role, $chatwootAccountUserId, $isAI, $globalAdmin);
        }

        return $this->updateMembership($existing, $role, $chatwootAccountUserId, $isAI, $globalAdmin);
    }

    /**
     * Resolve the membership entity for a given platform user ID + account.
     *
     * Two-step lookup:
     *   1. Find ChatwootUser by (chatwootUserId, platformId)
     *   2. Find ChatwootAccountUserMembership by (chatwootAccountId, chatwootUserId)
     *
     * @param int    $chatwootPlatformUserId The Chatwoot platform user ID (= ChatwootUser.chatwootUserId)
     * @param string $platformId             EspoCRM ChatwootPlatform entity ID
     * @param string $espoAccountId          EspoCRM ChatwootAccount entity ID
     * @return Entity|null The membership, or null if user/membership not found
     */
    public function resolveMembershipByPlatformUserId(
        int $chatwootPlatformUserId,
        string $platformId,
        string $espoAccountId
    ): ?Entity {
        $localUser = $this->entityManager
            ->getRDBRepository('ChatwootUser')
            ->where([
                'chatwootUserId' => $chatwootPlatformUserId,
                'platformId' => $platformId,
            ])
            ->findOne();

        if (!$localUser) {
            return null;
        }

        return $this->entityManager
            ->getRDBRepository('ChatwootAccountUserMembership')
            ->where([
                'chatwootAccountId' => $espoAccountId,
                'chatwootUserId' => $localUser->getId(),
            ])
            ->findOne();
    }

    /**
     * Update sync lifecycle fields on a membership entity.
     *
     * Owns ONLY: syncStatus, lastSyncedAt, lastSyncError.
     *
     * @param Entity $membership ChatwootAccountUserMembership entity
     * @param string $syncStatus The new sync status (e.g. 'synced', 'error')
     * @param string|null $syncError Error message, or null to clear
     */
    public function updateSyncStatus(
        Entity $membership,
        string $syncStatus,
        ?string $syncError = null
    ): void {
        $dirty = false;

        if ($membership->get('syncStatus') !== $syncStatus) {
            $membership->set('syncStatus', $syncStatus);
            $dirty = true;
        }

        $now = date('Y-m-d H:i:s');
        if ($membership->get('lastSyncedAt') !== $now) {
            $membership->set('lastSyncedAt', $now);
            $dirty = true;
        }

        if ($membership->get('lastSyncError') !== $syncError) {
            $membership->set('lastSyncError', $syncError);
            $dirty = true;
        }

        if ($dirty) {
            $this->entityManager->saveEntity($membership, ['silent' => true]);
        }
    }

    /**
     * Enable AI profile on a membership.
     *
     * Validates that the membership's ChatwootUser has an assignedUser with an email.
     * Sets isAI = true on the membership. If the membership has no chatwootAccountUserId,
     * calls syncAgentToChatwoot() to create the user/agent on Chatwoot.
     *
     * @param Entity $membership ChatwootAccountUserMembership entity
     * @return Entity The refreshed membership entity
     * @throws \Espo\Core\Exceptions\BadRequest
     */
    public function enableAiProfile(Entity $membership): Entity
    {
        $accountId = $membership->get('chatwootAccountId');
        $userId = $membership->get('chatwootUserId');

        if (!$accountId || !$userId) {
            throw new \Espo\Core\Exceptions\BadRequest('Membership must have both a Chat Account and Chat User to enable AI profile.');
        }

        $chatwootUser = $this->entityManager->getEntityById('ChatwootUser', $userId);
        if (!$chatwootUser) {
            throw new \Espo\Core\Exceptions\BadRequest('Chat User not found.');
        }

        $assignedUserId = $chatwootUser->get('assignedUserId');

        if (!$assignedUserId) {
            throw new \Espo\Core\Exceptions\BadRequest(
                'Chat User must be linked to a CRM User to create an AI agent profile.'
            );
        }

        $crmUser = $this->entityManager->getEntityById('User', $assignedUserId);
        if (!$crmUser) {
            throw new \Espo\Core\Exceptions\BadRequest('CRM User linked to Chat User was not found.');
        }

        $email = $this->extractCrmUserEmail($crmUser);

        if (!$email) {
            throw new \Espo\Core\Exceptions\BadRequest('CRM User email is required to create an AI agent profile.');
        }

        // Keep ChatwootUser email in sync if it differed
        if ($email !== $chatwootUser->get('email')) {
            $chatwootUser->set('email', $email);
            $this->entityManager->saveEntity($chatwootUser, ['silent' => true]);
        }

        // Ensure the membership has the email populated so syncAgentToChatwoot()
        // can use it. The membership.email varchar field may be null if the sync
        // job hasn't run yet for this membership.
        if (!$membership->get('email')) {
            $membership->set('email', $email);
        }

        // Set isAI on the membership
        $membership->set('isAI', true);

        // If membership has no chatwootAccountUserId, sync to Chatwoot to create user/agent
        if (!$membership->get('chatwootAccountUserId')) {
            try {
                $this->syncAgentToChatwoot($membership);
            } catch (\Throwable $e) {
                $this->log->error(
                    "enableAiProfile: Failed to sync agent to Chatwoot for membership {$membership->getId()}: " . $e->getMessage()
                );
                throw new \Espo\Core\Exceptions\BadRequest(
                    'Failed to create AI agent profile on Chatwoot: ' . $e->getMessage()
                );
            }
        }

        $this->entityManager->saveEntity($membership, ['silent' => true]);

        $this->log->info(
            "enableAiProfile: Enabled isAI on membership {$membership->getId()}"
        );

        // Reload to get fresh state
        return $this->entityManager->getEntityById('ChatwootAccountUserMembership', $membership->getId());
    }

    /**
     * Disable AI profile on a membership.
     *
     * Sets isAI = false. Does not remove the agent from Chatwoot.
     *
     * @param Entity $membership ChatwootAccountUserMembership entity
     * @return Entity The refreshed membership entity
     */
    public function disableAiProfile(Entity $membership): Entity
    {
        $membership->set('isAI', false);
        $this->entityManager->saveEntity($membership, ['silent' => true]);

        $this->log->info(
            "disableAiProfile: Disabled isAI on membership {$membership->getId()}"
        );

        // Reload to get fresh state
        return $this->entityManager->getEntityById('ChatwootAccountUserMembership', $membership->getId());
    }

    /**
     * Sync a membership as an agent to Chatwoot.
     *
     * Absorbs the old ChatwootAgent SyncWithChatwoot hook logic.
     * Resolves platform credentials, creates platform user if needed,
     * then creates/updates agent on the account API.
     * Populates membership fields from the Chatwoot API response.
     *
     * @param Entity $membership ChatwootAccountUserMembership entity
     */
    public function syncAgentToChatwoot(Entity $membership): void
    {
        $accountId = $membership->get('chatwootAccountId');
        $userId = $membership->get('chatwootUserId');

        if (!$accountId || !$userId) {
            throw new \RuntimeException('Membership must have chatwootAccountId and chatwootUserId to sync.');
        }

        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
        if (!$account) {
            throw new \RuntimeException("ChatwootAccount {$accountId} not found.");
        }

        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $account->get('platformId'));
        if (!$platform) {
            throw new \RuntimeException('ChatwootPlatform not found for account.');
        }

        $platformUrl = $platform->get('backendUrl');
        $accessToken = $platform->get('accessToken');
        $accountApiKey = $account->get('apiKey');
        $chatwootAccountId = $account->get('chatwootAccountId');

        if (!$platformUrl || !$accessToken || !$accountApiKey || !$chatwootAccountId) {
            throw new \RuntimeException('Missing platform URL, access token, API key, or Chatwoot account ID.');
        }

        $chatwootUser = $this->entityManager->getEntityById('ChatwootUser', $userId);
        if (!$chatwootUser) {
            throw new \RuntimeException("ChatwootUser {$userId} not found.");
        }

        // Resolve email through a multi-step fallback chain:
        // 1. Membership's email varchar field (populated by sync job from Chatwoot API)
        // 2. ChatwootUser's assigned CRM User email (most reliable source)
        // 3. ChatwootUser's email field (EspoCRM "email" type — stored in junction table,
        //    may be empty if entity was created with ['silent' => true])
        $email = $membership->get('email');

        if (!$email) {
            $assignedUserId = $chatwootUser->get('assignedUserId');
            if ($assignedUserId) {
                $crmUser = $this->entityManager->getEntityById('User', $assignedUserId);
                if ($crmUser) {
                    $email = $this->extractCrmUserEmail($crmUser);
                }
            }
        }

        if (!$email) {
            $email = $chatwootUser->get('email');
        }

        $name = $membership->get('name') ?: $chatwootUser->get('name') ?: 'Agent';
        $role = $membership->get('role') ?? 'agent';

        $chatwootAccountUserId = $membership->get('chatwootAccountUserId');

        if (!$chatwootAccountUserId) {
            // --- Create path: create user on platform if needed, then create agent on account ---
            $platformUserId = $chatwootUser->get('chatwootUserId');

            if (!$platformUserId && $email) {
                try {
                    // Create platform user via Platform API
                    $userResponse = $this->apiClient->createUser($platformUrl, $accessToken, [
                        'name' => $name,
                        'email' => $email,
                        'password' => bin2hex(random_bytes(16)),
                    ]);

                    $platformUserId = $userResponse['id'] ?? null;

                    if ($platformUserId) {
                        $chatwootUser->set('chatwootUserId', $platformUserId);
                        $this->entityManager->saveEntity($chatwootUser, ['silent' => true]);
                    }
                } catch (\Throwable $e) {
                    // If the user already exists on Chatwoot, continue and let createAgent()
                    // attach/reuse by email. If that also conflicts, recover from listAgents().
                    if ($this->isDuplicateChatwootEntityError($e->getMessage())) {
                        $this->log->warning(
                            "syncAgentToChatwoot: Chatwoot user already exists for email {$email}; " .
                            "continuing with agent reconciliation. Error: " . $e->getMessage()
                        );
                    } else {
                        throw $e;
                    }
                }
            }

            if (!$platformUserId && !$email) {
                throw new \RuntimeException('Could not resolve or create platform user ID.');
            }

            try {
                // Create agent on the account API
                $agentResponse = $this->apiClient->createAgent(
                    $platformUrl,
                    $accountApiKey,
                    $chatwootAccountId,
                    [
                        'name' => $name,
                        'email' => $email,
                        'role' => $role,
                        'availability_status' => $membership->get('availabilityStatus') ?? 'online',
                        'auto_offline' => $membership->get('autoOffline') ?? true,
                    ]
                );

                // Populate membership fields from API response
                $this->populateMembershipFromAgentResponse($membership, $agentResponse);

                if (!$chatwootUser->get('chatwootUserId') && isset($agentResponse['id'])) {
                    $chatwootUser->set('chatwootUserId', (int) $agentResponse['id']);
                    $this->entityManager->saveEntity($chatwootUser, ['silent' => true]);
                }
            } catch (\Throwable $e) {
                if (!$email || !$this->isDuplicateChatwootEntityError($e->getMessage())) {
                    throw $e;
                }

                $existingAgent = $this->findAgentByEmail($platformUrl, $accountApiKey, (int) $chatwootAccountId, $email);

                if (!$existingAgent) {
                    throw $e;
                }

                $this->populateMembershipFromAgentResponse($membership, $existingAgent);

                if (!$chatwootUser->get('chatwootUserId') && isset($existingAgent['id'])) {
                    $chatwootUser->set('chatwootUserId', (int) $existingAgent['id']);
                    $this->entityManager->saveEntity($chatwootUser, ['silent' => true]);
                }

                $this->log->info(
                    "syncAgentToChatwoot: Reused existing Chatwoot agent for membership {$membership->getId()} by email {$email}"
                );
            }

            $this->log->info(
                "syncAgentToChatwoot: Created agent on Chatwoot for membership {$membership->getId()}"
            );
        } else {
            // --- Update path: update agent if relevant fields changed ---
            $updateData = [];

            if ($membership->isAttributeChanged('role')) {
                $updateData['role'] = $role;
            }
            if ($membership->isAttributeChanged('availabilityStatus')) {
                $updateData['availability_status'] = $membership->get('availabilityStatus');
            }
            if ($membership->isAttributeChanged('autoOffline')) {
                $updateData['auto_offline'] = $membership->get('autoOffline');
            }

            if (!empty($updateData)) {
                $agentResponse = $this->apiClient->updateAgent(
                    $platformUrl,
                    $accountApiKey,
                    $chatwootAccountId,
                    $chatwootAccountUserId,
                    $updateData
                );

                $this->populateMembershipFromAgentResponse($membership, $agentResponse);

                $this->log->info(
                    "syncAgentToChatwoot: Updated agent on Chatwoot for membership {$membership->getId()}"
                );
            }
        }
    }

    /**
     * Remove an agent from Chatwoot for a given membership.
     *
     * Resolves platformUserId via the membership's ChatwootUser,
     * then calls the delete agent API. Handles 404 gracefully.
     *
     * @param Entity $membership ChatwootAccountUserMembership entity
     */
    public function removeAgentFromChatwoot(Entity $membership): void
    {
        $accountId = $membership->get('chatwootAccountId');
        $chatwootAccountUserId = $membership->get('chatwootAccountUserId');

        if (!$accountId || !$chatwootAccountUserId) {
            $this->log->debug(
                "removeAgentFromChatwoot: Skipping — membership {$membership->getId()} " .
                "has no accountId or chatwootAccountUserId"
            );
            return;
        }

        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
        if (!$account) {
            $this->log->warning("removeAgentFromChatwoot: ChatwootAccount {$accountId} not found.");
            return;
        }

        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $account->get('platformId'));
        if (!$platform) {
            $this->log->warning("removeAgentFromChatwoot: ChatwootPlatform not found.");
            return;
        }

        $platformUrl = $platform->get('backendUrl');
        $accountApiKey = $account->get('apiKey');
        $chatwootAccountId = $account->get('chatwootAccountId');

        if (!$platformUrl || !$accountApiKey || !$chatwootAccountId) {
            $this->log->warning("removeAgentFromChatwoot: Missing API credentials.");
            return;
        }

        try {
            $this->apiClient->deleteAgent(
                $platformUrl,
                $accountApiKey,
                $chatwootAccountId,
                $chatwootAccountUserId
            );

            $this->log->info(
                "removeAgentFromChatwoot: Deleted agent {$chatwootAccountUserId} from Chatwoot " .
                "for membership {$membership->getId()}"
            );
        } catch (\Exception $e) {
            // 404 is fine — agent already gone
            if (str_contains($e->getMessage(), '404')) {
                $this->log->info(
                    "removeAgentFromChatwoot: Agent {$chatwootAccountUserId} already gone from Chatwoot (404)"
                );
                return;
            }

            $this->log->error(
                "removeAgentFromChatwoot: Failed to delete agent {$chatwootAccountUserId}: " .
                $e->getMessage()
            );
        }
    }

    /**
     * Create a new membership entity.
     */
    private function createMembership(
        string $accountId,
        string $userId,
        string $role,
        ?int $chatwootAccountUserId = null,
        ?bool $isAI = null,
        ?bool $globalAdmin = null
    ): Entity {
        // Load the ChatwootAccount to get teamsIds
        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
        $teamsIds = $account ? $account->getLinkMultipleIdList('teams') : [];

        // Load the ChatwootUser to get the name.
        // A membership REQUIRES a resolvable ChatwootUser (the chatwootUser link is
        // `required: true`). If the user cannot be loaded, refusing to create the
        // membership prevents orphan records with a dangling FK and a bogus
        // 'Unknown' name (see BackfillAccountUserMemberships orphan incident).
        $user = $this->entityManager->getEntityById('ChatwootUser', $userId);

        if (!$user) {
            throw new \RuntimeException(
                "Cannot create ChatwootAccountUserMembership: ChatwootUser '{$userId}' " .
                "does not exist (account='{$accountId}'). Refusing to create an orphan membership."
            );
        }

        $name = $user->get('name');

        if ($name === null || $name === '') {
            throw new \RuntimeException(
                "Cannot create ChatwootAccountUserMembership: ChatwootUser '{$userId}' " .
                "has no name (account='{$accountId}'). Refusing to create a membership with an empty name."
            );
        }

        $data = [
            'name' => $name,
            'chatwootAccountId' => $accountId,
            'chatwootUserId' => $userId,
            'role' => $role,
            'syncStatus' => 'synced',
            'teamsIds' => $teamsIds,
        ];

        if ($chatwootAccountUserId !== null) {
            $data['chatwootAccountUserId'] = $chatwootAccountUserId;
        }

        if ($isAI !== null) {
            $data['isAI'] = $isAI;
        }

        if ($globalAdmin !== null) {
            $data['globalAdmin'] = $globalAdmin;
        }

        $membership = $this->entityManager->createEntity(
            'ChatwootAccountUserMembership',
            $data,
            ['silent' => true]
        );

        $this->log->info(
            "ChatwootAccountUserMembershipService: Created membership for account={$accountId} user={$userId}"
        );

        return $membership;
    }

    /**
     * Update an existing membership if dirty.
     */
    private function updateMembership(
        Entity $membership,
        string $role,
        ?int $chatwootAccountUserId = null,
        ?bool $isAI = null,
        ?bool $globalAdmin = null
    ): Entity {
        $dirty = false;

        if ($membership->get('role') !== $role) {
            $membership->set('role', $role);
            $dirty = true;
        }

        if ($chatwootAccountUserId !== null && $membership->get('chatwootAccountUserId') !== $chatwootAccountUserId) {
            $membership->set('chatwootAccountUserId', $chatwootAccountUserId);
            $dirty = true;
        }

        if ($isAI !== null && (bool) $membership->get('isAI') !== $isAI) {
            $membership->set('isAI', $isAI);
            $dirty = true;
        }

        if ($globalAdmin !== null && (bool) $membership->get('globalAdmin') !== $globalAdmin) {
            $membership->set('globalAdmin', $globalAdmin);
            $dirty = true;
        }

        if ($dirty) {
            $this->entityManager->saveEntity($membership, ['silent' => true]);

            $this->log->debug(
                "ChatwootAccountUserMembershipService: Updated membership {$membership->getId()}"
            );
        }

        return $membership;
    }

    /**
     * Populate membership fields from a Chatwoot agent API response.
     */
    private function populateMembershipFromAgentResponse(Entity $membership, array $response): void
    {
        if (isset($response['id'])) {
            $membership->set('chatwootAccountUserId', (int) $response['id']);
        }
        if (isset($response['available_name'])) {
            $membership->set('availableName', $response['available_name']);
        }
        if (isset($response['availability_status'])) {
            $membership->set('availabilityStatus', $response['availability_status']);
        }
        if (isset($response['auto_offline'])) {
            $membership->set('autoOffline', $response['auto_offline']);
        }
        if (isset($response['confirmed'])) {
            $membership->set('confirmed', $response['confirmed']);
        }
        // Prefer the original blob URL (`avatar_url`) over the resized
        // `thumbnail` representation. The thumbnail is a `resize_to_fill`
        // re-encode whose bytes differ from what we push, so mirroring it back
        // would never byte-match `crmAvatarSyncHash` and would drive an
        // infinite re-encode loop (generation loss → grayscale noise).
        $avatarUrl = $response['avatar_url'] ?? $response['thumbnail'] ?? null;
        if ($avatarUrl !== null) {
            $membership->set('avatarUrl', $avatarUrl);
        }
        if (isset($response['custom_role_id'])) {
            $membership->set('customRoleId', $response['custom_role_id']);
        }
        if (isset($response['email'])) {
            $membership->set('email', $response['email']);
        }
        if (isset($response['role'])) {
            $membership->set('role', $response['role']);
        }
    }

    private function extractCrmUserEmail(Entity $user): ?string
    {
        $email = $user->get('emailAddress');

        if (!$email) {
            $emailAddressData = $user->get('emailAddressData') ?? [];

            if (is_array($emailAddressData) && !empty($emailAddressData)) {
                $first = $emailAddressData[0] ?? null;

                if (is_object($first)) {
                    $email = (string) ($first->emailAddress ?? '');
                } elseif (is_array($first)) {
                    $email = (string) ($first['emailAddress'] ?? '');
                }
            }
        }

        return $email ?: null;
    }

    /**
     * Treats known 422 uniqueness responses as recoverable duplicate conflicts.
     */
    private function isDuplicateChatwootEntityError(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, 'already been taken') ||
            str_contains($normalized, 'already exists') ||
            str_contains($normalized, 'has already been taken');
    }

    /**
     * Find an existing Chatwoot account agent by email.
     *
     * @return array<string, mixed>|null
     */
    private function findAgentByEmail(
        string $platformUrl,
        string $accountApiKey,
        int $chatwootAccountId,
        string $email
    ): ?array {
        $agents = $this->apiClient->listAgents($platformUrl, $accountApiKey, $chatwootAccountId);

        foreach ($agents as $agent) {
            $agentEmail = strtolower(trim((string) ($agent['email'] ?? '')));

            if ($agentEmail !== '' && $agentEmail === strtolower(trim($email))) {
                return $agent;
            }
        }

        return null;
    }
}
