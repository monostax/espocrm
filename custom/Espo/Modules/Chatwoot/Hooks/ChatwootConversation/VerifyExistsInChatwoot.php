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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootConversation;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Hook to verify ChatwootConversation exists in Chatwoot before removal.
 * 
 * This hook checks if the conversation exists on Chatwoot before deleting from EspoCRM.
 * If Chatwoot returns 404 (conversation doesn't exist), the deletion proceeds normally.
 * This allows cleanup of orphaned records where the conversation was deleted externally
 * from Chatwoot.
 * 
 * Note: Chatwoot API does not support deleting conversations, so we only verify
 * existence and allow local deletion regardless of whether it exists on Chatwoot.
 */
class VerifyExistsInChatwoot
{
    public static int $order = 10;

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    /**
     * Verify conversation exists on Chatwoot before removal.
     * If 404, log and allow deletion. If exists, also allow deletion.
     * 
     * @param Entity $entity
     * @param array<string, mixed> $options
     */
    public function beforeRemove(Entity $entity, array $options): void
    {
        $this->log->info('DELETE HOOK CALLED for ChatwootConversation: ' . $entity->getId());
        
        $chatwootConversationId = $entity->get('chatwootConversationId');
        
        // If there's no chatwootConversationId, this conversation was never synced to Chatwoot
        // Allow deletion from EspoCRM
        if (!$chatwootConversationId) {
            $this->log->info(
                'ChatwootConversation ' . $entity->getId() . 
                ' has no chatwootConversationId, skipping Chatwoot verification'
            );
            return;
        }
        
        $accountId = $entity->get('chatwootAccountId');
        if (!$accountId) {
            $this->log->warning(
                'ChatwootConversation ' . $entity->getId() . 
                ' has no chatwootAccountId, allowing local deletion'
            );
            return;
        }

        try {
            // Get the account entity
            $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
            
            if (!$account) {
                $this->log->warning(
                    'ChatwootAccount not found: ' . $accountId . '. Allowing local deletion.'
                );
                return;
            }

            $chatwootAccountId = $account->get('chatwootAccountId');
            if (!$chatwootAccountId) {
                $this->log->warning(
                    'ChatwootAccount has no chatwootAccountId. Allowing local deletion.'
                );
                return;
            }

            $apiKey = $account->get('apiKey');
            if (!$apiKey) {
                $this->log->warning('ChatwootAccount has no API key. Allowing local deletion.');
                return;
            }

            $platformId = $account->get('platformId');
            if (!$platformId) {
                $this->log->warning('ChatwootAccount has no platformId. Allowing local deletion.');
                return;
            }

            $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
            
            if (!$platform) {
                $this->log->warning(
                    'ChatwootPlatform not found: ' . $platformId . '. Allowing local deletion.'
                );
                return;
            }

            $platformUrl = $platform->get('backendUrl');

            if (!$platformUrl) {
                $this->log->warning('ChatwootPlatform missing URL. Allowing local deletion.');
                return;
            }

            // Check if conversation exists on Chatwoot
            $this->log->info(
                'Verifying Chatwoot conversation ' . $chatwootConversationId . 
                ' exists in account ' . $chatwootAccountId
            );
            
            $conversation = $this->apiClient->getConversation(
                $platformUrl,
                $apiKey,
                $chatwootAccountId,
                $chatwootConversationId
            );
            
            if ($conversation === null) {
                // 404 - conversation doesn't exist on Chatwoot
                $this->log->info(
                    'Chatwoot conversation ' . $chatwootConversationId . 
                    ' not found in Chatwoot (404). Allowing deletion from EspoCRM.'
                );
            } else {
                // Conversation exists on Chatwoot, but we can't delete it via API
                // Allow local deletion anyway
                $this->log->info(
                    'Chatwoot conversation ' . $chatwootConversationId . 
                    ' exists in Chatwoot. Allowing deletion from EspoCRM ' .
                    '(note: conversation will remain in Chatwoot as API does not support deletion).'
                );
            }

        } catch (\Exception $e) {
            // If we get a 404-like error message, allow deletion
            if (str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), 'not found')) {
                $this->log->warning(
                    'Chatwoot conversation ' . $chatwootConversationId . 
                    ' not found in Chatwoot (already deleted?). Allowing deletion from EspoCRM.'
                );
                return;
            }
            
            // For other errors, log but still allow deletion
            // We don't want to block EspoCRM cleanup due to API issues
            $this->log->warning(
                'Could not verify Chatwoot conversation ' . $chatwootConversationId . 
                ' existence: ' . $e->getMessage() . '. Allowing deletion from EspoCRM anyway.'
            );
        }
    }
}
