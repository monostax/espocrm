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
 * Centralized service for ChatwootAccountUserMembership upsert and resolution.
 * Used by 5+ callsites: LinkToUser, LinkToAgents, SyncAgentsFromChatwoot,
 * SyncInboxMembersFromChatwoot, BackfillAccountUserMemberships, and
 * RepairAccountUserMembershipInvariants.
 *
 * Uses constructor DI (no binding config needed; InjectableFactory auto-resolves).
 */
class ChatwootAccountUserMembershipService
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    /**
     * Find-or-create a membership by (chatwootAccountId, chatwootUserId).
     *
     * - If not found: creates entity with name, role, syncStatus='synced', teams from account.
     * - If found: updates role if changed. Always overwrites chatwootAgentId when $agentId
     *   is provided (Decision #10 — prevents stale references after agent re-creation).
     * - Saves with ['silent' => true] to avoid triggering API sync hooks.
     *
     * @param string $accountId EspoCRM ChatwootAccount entity ID
     * @param string $userId    EspoCRM ChatwootUser entity ID
     * @param string $role      'agent' or 'administrator'
     * @param string|null $agentId EspoCRM ChatwootAgent entity ID (optional)
     * @return Entity The upserted ChatwootAccountUserMembership entity
     */
    public function upsertMembership(
        string $accountId,
        string $userId,
        string $role,
        ?string $agentId = null,
        ?int $chatwootAccountUserId = null
    ): Entity {
        $existing = $this->entityManager
            ->getRDBRepository('ChatwootAccountUserMembership')
            ->where([
                'chatwootAccountId' => $accountId,
                'chatwootUserId' => $userId,
            ])
            ->findOne();

        if (!$existing) {
            return $this->createMembership($accountId, $userId, $role, $agentId, $chatwootAccountUserId);
        }

        return $this->updateMembership($existing, $role, $agentId, $chatwootAccountUserId);
    }

    /**
     * Resolve the membership entity for a given ChatwootAgent.
     *
     * @param Entity $agent ChatwootAgent entity
     * @return Entity|null The membership, or null if agent lacks accountId/userId
     */
    public function resolveMembershipForAgent(Entity $agent): ?Entity
    {
        $accountId = $agent->get('chatwootAccountId');
        $userId = $agent->get('chatwootUserId');

        if (!$accountId || !$userId) {
            return null;
        }

        return $this->entityManager
            ->getRDBRepository('ChatwootAccountUserMembership')
            ->where([
                'chatwootAccountId' => $accountId,
                'chatwootUserId' => $userId,
            ])
            ->findOne();
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
     * Link an inbox to memberships resolved from a set of agent IDs.
     * Agents without resolvable memberships are silently skipped.
     *
     * @param Entity $inbox     ChatwootInbox entity
     * @param array<string> $agentIds  EspoCRM ChatwootAgent entity IDs
     */
    public function linkInboxToMemberships(Entity $inbox, array $agentIds): void
    {
        foreach ($agentIds as $agentId) {
            $agent = $this->entityManager->getEntityById('ChatwootAgent', $agentId);

            if (!$agent) {
                continue;
            }

            $membership = $this->resolveMembershipForAgent($agent);

            if (!$membership) {
                continue;
            }

            // relateById is idempotent — EspoCRM checks the middle table with withDeleted()
            // No SKIP_HOOKS needed: no hooks exist on accountUserMemberships relation in Phase 2
            $this->entityManager
                ->getRDBRepository('ChatwootInbox')
                ->getRelation($inbox, 'accountUserMemberships')
                ->relateById($membership->getId());
        }
    }

    /**
     * Update sync lifecycle fields on a membership entity.
     *
     * Owns ONLY: syncStatus, lastSyncedAt, lastSyncError.
     * Does NOT set chatwootAccountUserId — that is upsertMembership()'s responsibility
     * (Decision #9: single owner per field).
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
     * Create a new membership entity.
     */
    private function createMembership(
        string $accountId,
        string $userId,
        string $role,
        ?string $agentId,
        ?int $chatwootAccountUserId = null
    ): Entity {
        // Load the ChatwootAccount to get teamsIds
        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
        $teamsIds = $account ? $account->getLinkMultipleIdList('teams') : [];

        // Load the ChatwootUser to get the name
        $user = $this->entityManager->getEntityById('ChatwootUser', $userId);
        $name = $user ? $user->get('name') : 'Unknown';

        $data = [
            'name' => $name,
            'chatwootAccountId' => $accountId,
            'chatwootUserId' => $userId,
            'chatwootAgentId' => $agentId,
            'role' => $role,
            'syncStatus' => 'synced',
            'teamsIds' => $teamsIds,
        ];

        if ($chatwootAccountUserId !== null) {
            $data['chatwootAccountUserId'] = $chatwootAccountUserId;
        }

        $membership = $this->entityManager->createEntity(
            'ChatwootAccountUserMembership',
            $data,
            ['silent' => true]
        );

        $this->log->info(
            "ChatwootAccountUserMembershipService: Created membership for account={$accountId} user={$userId}" .
            ($agentId ? " agent={$agentId}" : '')
        );

        return $membership;
    }

    /**
     * Enable AI profile for a membership by creating or re-enabling a ChatwootAgent.
     *
     * If an agent already exists for this (account, user) pair:
     *   - If isAI is false: set isAI = true, link if needed
     *   - If isAI is true: no-op
     * If no agent exists: create one via non-silent createEntity (triggers full hook chain).
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

        // Check if a ChatwootAgent already exists for this (account, user) pair
        $existingAgent = $this->entityManager
            ->getRDBRepository('ChatwootAgent')
            ->where([
                'chatwootAccountId' => $accountId,
                'chatwootUserId' => $userId,
            ])
            ->findOne();

        if ($existingAgent) {
            if ($existingAgent->get('isAI')) {
                // Already enabled — no-op
                $this->log->info(
                    "enableAiProfile: Agent {$existingAgent->getId()} already has isAI=true for membership {$membership->getId()}"
                );
            } else {
                // Re-enable AI on existing agent
                $existingAgent->set('isAI', true);
                $this->entityManager->saveEntity($existingAgent, ['silent' => true]);

                $this->log->info(
                    "enableAiProfile: Re-enabled isAI on existing agent {$existingAgent->getId()} for membership {$membership->getId()}"
                );
            }

            // Ensure membership has the agent linked
            if ($membership->get('chatwootAgentId') !== $existingAgent->getId()) {
                $membership->set('chatwootAgentId', $existingAgent->getId());
                $this->entityManager->saveEntity($membership, ['silent' => true]);
            }

            // Reload to get fresh state
            return $this->entityManager->getEntityById('ChatwootAccountUserMembership', $membership->getId());
        }

        // No existing agent — create one via non-silent createEntity (triggers full hook chain)
        $chatwootUser = $this->entityManager->getEntityById('ChatwootUser', $userId);
        if (!$chatwootUser) {
            throw new \Espo\Core\Exceptions\BadRequest('Chat User not found.');
        }

        $email = $chatwootUser->get('email');
        $name = $chatwootUser->get('name') ?: $membership->get('name');
        $role = $membership->get('role') ?? 'agent';

        // Generate a password that satisfies ValidateBeforeSync requirements
        // (min 6 chars, at least 1 special char). This password is never actually
        // used — createChatwootUserFirst() finds the existing user by email+platform
        // and skips user creation entirely (Decision #9).
        $generatedPassword = bin2hex(random_bytes(8)) . '!A1';

        $agentData = [
            'name' => $name,
            'email' => $email,
            'password' => $generatedPassword,
            'chatwootAccountId' => $accountId,
            'role' => $role,
            'isAI' => true,
        ];

        try {
            // Non-silent createEntity triggers full hook chain:
            // CascadeTeamsFromAccount → ValidateAssignedUserTeam → ValidateBeforeSync →
            // SyncWithChatwoot (creates user + agent on Chatwoot) → LinkToUser (upserts membership)
            $this->entityManager->createEntity('ChatwootAgent', $agentData);

            $this->log->info(
                "enableAiProfile: Created new ChatwootAgent for membership {$membership->getId()}"
            );
        } catch (\Throwable $e) {
            $this->log->error(
                "enableAiProfile: Failed to create ChatwootAgent for membership {$membership->getId()}: " . $e->getMessage()
            );

            throw new \Espo\Core\Exceptions\BadRequest(
                'Failed to create AI agent profile: ' . $e->getMessage()
            );
        }

        // Reload membership — LinkToUser afterSave hook sets chatwootAgentId on it
        return $this->entityManager->getEntityById('ChatwootAccountUserMembership', $membership->getId());
    }

    /**
     * Disable AI profile on the linked agent without unlinking.
     *
     * Sets agent.isAI = false. Does NOT null chatwootAgentId on membership —
     * the link is preserved (Decision #10). Nulling chatwootAgentId is not durable
     * because SyncAgentsFromChatwoot, SyncAccountMembersFromChatwoot, LinkToUser,
     * and RepairAccountUserMembershipInvariants would all re-link within minutes.
     *
     * @param Entity $membership ChatwootAccountUserMembership entity
     * @return Entity The refreshed membership entity
     * @throws \Espo\Core\Exceptions\BadRequest
     */
    public function disableAiProfile(Entity $membership): Entity
    {
        $agentId = $membership->get('chatwootAgentId');
        if (!$agentId) {
            throw new \Espo\Core\Exceptions\BadRequest('No agent profile is linked to this membership.');
        }

        $agent = $this->entityManager->getEntityById('ChatwootAgent', $agentId);
        if (!$agent) {
            throw new \Espo\Core\Exceptions\BadRequest('Linked agent profile not found.');
        }

        if (!$agent->get('isAI')) {
            // Already disabled — no-op
            $this->log->info(
                "disableAiProfile: Agent {$agentId} already has isAI=false for membership {$membership->getId()}"
            );
        } else {
            $agent->set('isAI', false);
            $this->entityManager->saveEntity($agent, ['silent' => true]);

            $this->log->info(
                "disableAiProfile: Disabled isAI on agent {$agentId} for membership {$membership->getId()}"
            );
        }

        // Reload membership to get fresh state
        return $this->entityManager->getEntityById('ChatwootAccountUserMembership', $membership->getId());
    }

    /**
     * Update an existing membership if dirty.
     */
    private function updateMembership(
        Entity $membership,
        string $role,
        ?string $agentId,
        ?int $chatwootAccountUserId = null
    ): Entity {
        $dirty = false;

        if ($membership->get('role') !== $role) {
            $membership->set('role', $role);
            $dirty = true;
        }

        // Decision #10: Always overwrite chatwootAgentId when $agentId is provided
        if ($agentId !== null && $membership->get('chatwootAgentId') !== $agentId) {
            $membership->set('chatwootAgentId', $agentId);
            $dirty = true;
        }

        if ($chatwootAccountUserId !== null && $membership->get('chatwootAccountUserId') !== $chatwootAccountUserId) {
            $membership->set('chatwootAccountUserId', $chatwootAccountUserId);
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
}
