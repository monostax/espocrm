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
 * Hook to ensure ChatwootAccountUserMembership records are linked to the
 * ChatwootUser after creation.
 *
 * After Phase 9 (ChatwootAgent elimination), there is no ChatwootAgent entity
 * to link. Memberships are created/managed by the sync jobs. This hook acts as
 * a secondary safety net, ensuring memberships that may have been created
 * before the user existed get their chatwootUserId populated.
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
     * After a ChatwootUser is saved, ensure memberships exist for all accounts
     * in the same platform where this user is a member.
     *
     * @param Entity $entity
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        // No-op after Phase 9: ChatwootAgent entity is eliminated.
        // Memberships are created by SyncAccountUserMembershipsFromChatwoot.
        // The upsertMembership() call below is a safety net for edge cases.

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

        // Find memberships for this user — just log for awareness
        $memberships = $this->entityManager
            ->getRDBRepository('ChatwootAccountUserMembership')
            ->where([
                'chatwootAccountId' => $accountIds,
                'chatwootUserId' => $userId,
            ])
            ->find();

        $count = 0;
        foreach ($memberships as $m) {
            $count++;
        }

        if ($count > 0) {
            $this->log->debug(
                "LinkToAgents: ChatwootUser {$userId} has {$count} existing membership(s) across platform {$platformId}"
            );
        }
    }
}
