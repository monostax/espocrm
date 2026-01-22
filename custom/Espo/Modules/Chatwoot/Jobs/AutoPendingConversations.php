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

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use Espo\ORM\Entity;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Scheduled job to automatically manage conversation status based on last message direction.
 * 
 * This provides a default behavior where:
 * - If conversation is "open" and last message is "outgoing" → move to "pending"
 *   (We sent last message, waiting for customer response)
 * - If conversation is "pending" and last message is "incoming" → move to "open"
 *   (Customer replied, needs our attention)
 * 
 * This creates a natural workflow cycle:
 * Agent replies → Pending → Customer replies → Open → Agent replies → Pending ...
 */
class AutoPendingConversations implements JobDataLess
{
    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    public function run(): void
    {
        $this->log->debug('AutoPendingConversations: Job started');

        try {
            $toPending = 0;
            $toOpen = 0;
            $errors = 0;

            // 1. Find "open" conversations with last message "outgoing" → move to "pending"
            $openOutgoing = $this->entityManager
                ->getRDBRepository('ChatwootConversation')
                ->where([
                    'status' => 'open',
                    'lastMessageType' => 'outgoing',
                ])
                ->find();

            foreach ($openOutgoing as $conversation) {
                try {
                    if ($this->toggleConversationStatus($conversation, 'pending')) {
                        $toPending++;
                    }
                } catch (\Exception $e) {
                    $errors++;
                    $this->log->warning(
                        'AutoPendingConversations: Failed to move conversation ' . 
                        $conversation->getId() . ' to pending: ' . $e->getMessage()
                    );
                }
            }

            // 2. Find "pending" conversations with last message "incoming" → move to "open"
            $pendingIncoming = $this->entityManager
                ->getRDBRepository('ChatwootConversation')
                ->where([
                    'status' => 'pending',
                    'lastMessageType' => 'incoming',
                ])
                ->find();

            foreach ($pendingIncoming as $conversation) {
                try {
                    if ($this->toggleConversationStatus($conversation, 'open')) {
                        $toOpen++;
                    }
                } catch (\Exception $e) {
                    $errors++;
                    $this->log->warning(
                        'AutoPendingConversations: Failed to move conversation ' . 
                        $conversation->getId() . ' to open: ' . $e->getMessage()
                    );
                }
            }

            if ($toPending > 0 || $toOpen > 0 || $errors > 0) {
                $this->log->info(
                    "AutoPendingConversations: Job completed - {$toPending} to pending, {$toOpen} to open, {$errors} errors"
                );
            } else {
                $this->log->debug('AutoPendingConversations: No conversations to process');
            }

        } catch (\Throwable $e) {
            $this->log->error(
                'AutoPendingConversations: Job failed - ' . $e->getMessage() . 
                ' at ' . $e->getFile() . ':' . $e->getLine()
            );
        }
    }

    /**
     * Toggle a conversation's status in both Chatwoot and EspoCRM.
     * 
     * @param Entity $conversation The conversation entity
     * @param string $newStatus The target status ('open', 'pending', etc.)
     * @return bool True if status was changed, false if skipped
     */
    private function toggleConversationStatus(Entity $conversation, string $newStatus): bool
    {
        $conversationId = $conversation->getId();
        $chatwootConversationId = $conversation->get('chatwootConversationId');

        if (!$chatwootConversationId) {
            $this->log->warning(
                "AutoPendingConversations: Conversation {$conversationId} has no chatwootConversationId"
            );
            return false;
        }

        // Get the ChatwootAccount
        $accountId = $conversation->get('chatwootAccountId');
        if (!$accountId) {
            $this->log->warning(
                "AutoPendingConversations: Conversation {$conversationId} has no chatwootAccountId"
            );
            return false;
        }

        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
        if (!$account) {
            $this->log->warning(
                "AutoPendingConversations: ChatwootAccount {$accountId} not found"
            );
            return false;
        }

        // Check if auto-pending is enabled for this account (default: true)
        $autoPendingEnabled = $account->get('autoPendingEnabled') ?? true;
        if (!$autoPendingEnabled) {
            $this->log->debug(
                "AutoPendingConversations: Auto-pending disabled for account {$accountId}"
            );
            return false;
        }

        $chatwootAccountId = $account->get('chatwootAccountId');
        if (!$chatwootAccountId) {
            $this->log->warning(
                "AutoPendingConversations: Account {$accountId} has no chatwootAccountId"
            );
            return false;
        }

        $accountApiKey = $account->get('apiKey');
        if (!$accountApiKey) {
            $this->log->warning(
                "AutoPendingConversations: Account {$accountId} has no API key"
            );
            return false;
        }

        // Get the platform
        $platformId = $account->get('platformId');
        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
        if (!$platform) {
            $this->log->warning(
                "AutoPendingConversations: Platform {$platformId} not found"
            );
            return false;
        }

        $platformUrl = $platform->get('backendUrl');
        if (!$platformUrl) {
            $this->log->warning(
                "AutoPendingConversations: Platform {$platformId} has no URL"
            );
            return false;
        }

        // Toggle status in Chatwoot
        $this->apiClient->toggleConversationStatus(
            $platformUrl,
            $accountApiKey,
            $chatwootAccountId,
            $chatwootConversationId,
            $newStatus
        );

        // Update local record
        $conversation->set('status', $newStatus);
        $this->entityManager->saveEntity($conversation, ['silent' => true]);

        $this->log->info(
            "AutoPendingConversations: Moved conversation {$chatwootConversationId} to {$newStatus}"
        );

        return true;
    }
}
