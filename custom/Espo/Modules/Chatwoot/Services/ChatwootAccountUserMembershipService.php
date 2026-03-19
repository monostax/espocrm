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
