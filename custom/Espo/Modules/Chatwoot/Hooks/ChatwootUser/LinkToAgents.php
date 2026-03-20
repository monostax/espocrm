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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootUser;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootAccountUserMembershipService;

/**
 * Hook to link ChatwootUser to matching ChatwootAgents after creation.
 *
 * This ensures bidirectional sync - when a user is created, any agents
 * with the same email across ALL accounts in the same platform get linked.
 *
 * Also creates ChatwootAccountUserMembership for each linked agent.
 * Note: LinkToAgents saves the agent with ['silent' => true], which triggers
 * LinkToUser.afterSave (it doesn't check silent). LinkToUser would also upsert
 * the membership via its new guard. However, the explicit call here ensures it
 * works even if LinkToUser is modified in the future. The upsert is idempotent.
 */
class LinkToAgents
{
    public static int $order = 20; // Run after SyncWithChatwoot

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
        private ChatwootAccountUserMembershipService $membershipService
    ) {}

    /**
     * After a ChatwootUser is saved, find and link matching unlinked
     * ChatwootAgents across all accounts in the same platform.
     *
     * @param Entity $entity
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        // Link unlinked agents by email match.
        $email = $entity->get('email');
        $platformId = $entity->get('platformId');
        $userId = $entity->getId();

        if (!$email || !$platformId) {
            return;
        }

        // Get all account IDs for this platform
        $accounts = $this->entityManager
            ->getRDBRepository('ChatwootAccount')
            ->where(['platformId' => $platformId])
            ->find();

        $accountIds = [];
        foreach ($accounts as $account) {
            $accountIds[] = $account->getId();
        }

        if (empty($accountIds)) {
            return;
        }

        // Find ChatwootAgents with the same email in any account of this platform that aren't linked yet
        $agents = $this->entityManager
            ->getRDBRepository('ChatwootAgent')
            ->where([
                'email' => $email,
                'chatwootAccountId' => $accountIds,
                'OR' => [
                    ['chatwootUserId' => null],
                    ['chatwootUserId' => ''],
                ],
            ])
            ->find();

        $linkedCount = 0;
        foreach ($agents as $agent) {
            $agent->set('chatwootUserId', $userId);
            $agent->set('confirmed', true); // User exists, so agent is confirmed

            $this->entityManager->saveEntity($agent, ['silent' => true]);

            // Upsert membership for each linked agent (idempotent)
            $this->membershipService->upsertMembership(
                $agent->get('chatwootAccountId'),
                $userId,
                $agent->get('role') ?? 'agent',
                $agent->getId()
            );

            $linkedCount++;
        }

        if ($linkedCount > 0) {
            $this->log->info(
                "LinkToAgents: Linked ChatwootUser {$userId} to {$linkedCount} ChatwootAgent(s) by email {$email} across platform {$platformId}"
            );
        }
    }
}
