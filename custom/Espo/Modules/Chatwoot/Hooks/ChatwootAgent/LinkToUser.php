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

/**
 * Hook to link ChatwootAgent to matching ChatwootUser after save.
 * This ensures bidirectional sync - when an agent is created or synced,
 * if there's a ChatwootUser with the same email in the same platform, they get linked.
 */
class LinkToUser
{
    public static int $order = 20; // Run after SyncWithChatwoot

    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    /**
     * After a ChatwootAgent is saved, find and link matching ChatwootUser if not already linked.
     * Searches for user by email within the same platform (via account).
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

        // Skip if already linked to a user
        if ($entity->get('chatwootUserId')) {
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
            $this->entityManager->saveEntity($entity, ['silent' => true, 'skipLinkToUser' => true]);

            $this->log->info(
                "LinkToUser: Linked ChatwootAgent {$entity->getId()} to ChatwootUser {$chatwootUser->getId()} by email {$email} in platform {$platformId}"
            );
        }
    }
}
