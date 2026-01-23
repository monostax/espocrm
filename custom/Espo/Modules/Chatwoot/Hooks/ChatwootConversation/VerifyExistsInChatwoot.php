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

use Espo\Core\Exceptions\Error;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Hook to delete ChatwootConversation from Chatwoot before removal from EspoCRM.
 * 
 * This hook deletes the conversation on Chatwoot before deleting from EspoCRM.
 * If Chatwoot returns 404 (conversation doesn't exist), the deletion proceeds normally.
 * 
 * Note: Only administrators can delete conversations in Chatwoot. The account API key
 * must be from an administrator user.
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
     * Delete conversation from Chatwoot BEFORE entity is removed from database.
     * 
     * @param Entity $entity
     * @param array<string, mixed> $options
     * @throws Error
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
                ' has no chatwootConversationId, skipping Chatwoot deletion'
            );
            return;
        }
        
        $this->log->info('Attempting to delete Chatwoot conversation with ID: ' . $chatwootConversationId);
        
        $accountId = $entity->get('chatwootAccountId');
        if (!$accountId) {
            $this->log->warning(
                'ChatwootConversation ' . $entity->getId() . 
                ' has no chatwootAccountId, cannot delete from Chatwoot'
            );
            // Allow deletion from EspoCRM anyway - we can't sync
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

            // Delete conversation from Chatwoot
            $this->log->info(
                'Deleting Chatwoot conversation: ' . $chatwootConversationId . 
                ' from account ' . $chatwootAccountId
            );
            
            $this->apiClient->deleteConversation(
                $platformUrl,
                $apiKey,
                $chatwootAccountId,
                $chatwootConversationId
            );
            
            $this->log->info('Successfully deleted Chatwoot conversation: ' . $chatwootConversationId);

        } catch (\Exception $e) {
            // If the resource doesn't exist (404), allow deletion from EspoCRM
            // The conversation is already gone from Chatwoot
            if (str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), 'not found')) {
                $this->log->warning(
                    'Chatwoot conversation ' . $chatwootConversationId . 
                    ' not found in Chatwoot (already deleted?). Allowing deletion from EspoCRM.'
                );
                return;
            }
            
            // For authorization errors, log but allow local deletion
            // The API key might be from an agent, not an administrator
            if (str_contains($e->getMessage(), 'Unauthorized') || 
                str_contains($e->getMessage(), '401') || 
                str_contains($e->getMessage(), '403')) {
                $this->log->warning(
                    'Not authorized to delete Chatwoot conversation ' . $chatwootConversationId . 
                    '. The API key might not be from an administrator. ' .
                    'Conversation will remain in Chatwoot. Error: ' . $e->getMessage()
                );
                return;
            }
            
            $this->log->error(
                'Failed to delete Chatwoot conversation ' . $chatwootConversationId . ': ' . $e->getMessage()
            );
            
            // Re-throw - this will prevent the database DELETE from happening
            throw new Error(
                'Failed to delete conversation from Chatwoot: ' . $e->getMessage() . 
                '. The conversation was not deleted from EspoCRM to maintain synchronization. ' .
                'Please check if the conversation still exists in Chatwoot or try again.'
            );
        }
    }
}
