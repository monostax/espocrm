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

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootAccountUserMembershipService;

/**
 * Hook to link ChatwootAgent to matching ChatwootUser after save.
 * This ensures bidirectional sync - when an agent is created or synced,
 * if there's a ChatwootUser with the same email in the same platform, they get linked.
 *
 * Also upserts the ChatwootAccountUserMembership for the agent↔user pair.
 * The membership upsert fires BEFORE the chatwootUserId early-return guard
 * (Decision #7) so that it covers all three creation paths:
 *   - SyncWithChatwoot.beforeSave (order=10) sets chatwootUserId before this hook
 *   - EnsurePlatformUser.afterSave (order=25) sets chatwootUserId via silent save
 *   - LinkToUser itself discovers and links a user (below)
 *
 * Phase 5: Also propagates ChatwootUser.assignedUserId → agent (user→agent direction,
 * Decision #6). This is the derived assignment path — the authoritative source is
 * ChatwootUser.assignedUserId.
 *
 * Note: LinkToUser does NOT check $options['silent'], so this upsert fires on
 * ALL afterSave invocations including silent saves from LinkToAgents and
 * EnsurePlatformUser. This is intentionally desired.
 *
 * Co-ordering: LinkToUser and SyncTeamMembership both run at order=20.
 * EspoCRM processes same-order hooks alphabetically, so LinkToUser runs
 * before SyncTeamMembership. This is the correct order.
 */
class LinkToUser
{
    public static int $order = 20; // Run after SyncWithChatwoot

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
        private ChatwootAccountUserMembershipService $membershipService
    ) {}

    /**
     * After a ChatwootAgent is saved, ensure membership exists, propagate
     * ChatwootUser assignment to agent, and find/link matching ChatwootUser
     * if not already linked.
     *
     * @param Entity $entity
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        // Skip if this is a recursive call from linking
        if (!empty($options['skipLinkToUser'])) {
            return;
        }

        // If already linked to user+account, ensure membership exists,
        // propagate assignment from ChatwootUser, and return.
        // This covers the SyncWithChatwoot path, EnsurePlatformUser path, and
        // the LinkToAgents silent-save path.
        if ($entity->get('chatwootUserId') && $entity->get('chatwootAccountId')) {
            $this->membershipService->upsertMembership(
                $entity->get('chatwootAccountId'),
                $entity->get('chatwootUserId'),
                $entity->get('role') ?? 'agent',
                $entity->getId()
            );

            // Propagate ChatwootUser.assignedUserId → agent (user→agent direction)
            $this->propagateAssignmentFromChatwootUser($entity);

            return;
        }

        $email = $entity->get('email');
        $accountId = $entity->get('chatwootAccountId');

        if (!$email || !$accountId) {
            return;
        }

        // Get the account to find the platform
        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
        if (!$account) {
            return;
        }

        $platformId = $account->get('platformId');
        if (!$platformId) {
            return;
        }

        // Find ChatwootUser with the same email in the same platform
        $chatwootUser = $this->entityManager
            ->getRDBRepository('ChatwootUser')
            ->where([
                'email' => $email,
                'platformId' => $platformId,
            ])
            ->findOne();

        if ($chatwootUser) {
            $entity->set('chatwootUserId', $chatwootUser->getId());
            $entity->set('confirmed', true); // User exists, so agent is confirmed

            // Propagate ChatwootUser.assignedUserId → agent (user→agent direction)
            $chatwootUserAssignedUserId = $chatwootUser->get('assignedUserId');
            if ($chatwootUserAssignedUserId && $entity->get('assignedUserId') !== $chatwootUserAssignedUserId) {
                $entity->set('assignedUserId', $chatwootUserAssignedUserId);
            }

            $this->entityManager->saveEntity($entity, ['silent' => true, 'skipLinkToUser' => true, 'skipEnsurePlatformUser' => true]);

            $this->log->info(
                "LinkToUser: Linked ChatwootAgent {$entity->getId()} to ChatwootUser {$chatwootUser->getId()} by email {$email} in platform {$platformId}"
            );

            // Create membership for the newly linked agent↔user pair
            $this->membershipService->upsertMembership(
                $accountId,
                $chatwootUser->getId(),
                $entity->get('role') ?? 'agent',
                $entity->getId()
            );
        }
    }

    /**
     * Propagate assignedUserId from the linked ChatwootUser to the agent.
     * This is the user→agent direction (Decision #6). Only saves if the value differs.
     */
    private function propagateAssignmentFromChatwootUser(Entity $entity): void
    {
        $chatwootUserId = $entity->get('chatwootUserId');
        if (!$chatwootUserId) {
            return;
        }

        $chatwootUser = $this->entityManager->getEntityById('ChatwootUser', $chatwootUserId);
        if (!$chatwootUser) {
            return;
        }

        $chatwootUserAssignedUserId = $chatwootUser->get('assignedUserId');
        $agentAssignedUserId = $entity->get('assignedUserId');

        // Only update if different (including null→value and value→null)
        if ($chatwootUserAssignedUserId !== $agentAssignedUserId) {
            $entity->set('assignedUserId', $chatwootUserAssignedUserId);
            $this->entityManager->saveEntity($entity, [
                'silent' => true,
                'skipLinkToUser' => true,
                'skipEnsurePlatformUser' => true,
            ]);

            $this->log->info(
                "LinkToUser: Propagated assignedUserId from ChatwootUser {$chatwootUserId} to ChatwootAgent {$entity->getId()}: " .
                ($chatwootUserAssignedUserId ?: 'null')
            );
        }
    }
}
