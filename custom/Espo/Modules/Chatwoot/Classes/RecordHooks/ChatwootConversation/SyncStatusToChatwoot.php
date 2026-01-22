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

namespace Espo\Modules\Chatwoot\Classes\RecordHooks\ChatwootConversation;

use Espo\Core\Acl;
use Espo\Core\Acl\Table as AclTable;
use Espo\Core\Record\Hook\UpdateHook;
use Espo\Core\Record\UpdateParams;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Hook to synchronize ChatwootConversation status changes with Chatwoot.
 * When a conversation's status is changed (e.g., via Kanban drag-and-drop),
 * this hook syncs the change back to Chatwoot.
 * 
 * Security: Enforces ACL checks to ensure users can only modify conversations
 * they have access to (respects team-based multi-tenancy).
 */
class SyncStatusToChatwoot implements UpdateHook
{
    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log,
        private Acl $acl,
        private User $user
    ) {}

    /**
     * Sync status change to Chatwoot BEFORE entity is saved to database.
     * If the operation fails, an exception is thrown and the change is not saved.
     * 
     * @throws Error
     * @throws Forbidden
     */
    public function process(Entity $entity, UpdateParams $params): void
    {
        // Only process if status field was changed
        if (!$entity->isAttributeChanged('status')) {
            return;
        }

        $newStatus = $entity->get('status');
        $oldStatus = $entity->getFetched('status');
        
        // Skip if status hasn't actually changed (could be null -> same value)
        if ($newStatus === $oldStatus) {
            return;
        }

        // =====================================================================
        // SECURITY: Verify ACL permissions before syncing to Chatwoot
        // This is defense-in-depth - the Record service should have already
        // checked ACL, but we verify again to prevent any bypass scenarios.
        // =====================================================================
        
        // Check scope-level edit permission
        if (!$this->acl->check('ChatwootConversation', AclTable::ACTION_EDIT)) {
            $this->log->warning(
                'User ' . $this->user->getId() . ' attempted to change conversation status ' .
                'without scope-level edit permission'
            );
            throw new Forbidden('Access denied. You do not have permission to edit conversations.');
        }
        
        // Check entity-level edit permission (respects team-based access)
        if (!$this->acl->check($entity, AclTable::ACTION_EDIT)) {
            $this->log->warning(
                'User ' . $this->user->getId() . ' attempted to change status of conversation ' .
                $entity->getId() . ' without entity-level edit permission (team restriction)'
            );
            throw new Forbidden('Access denied. You do not have permission to edit this conversation.');
        }

        // Validate status value
        $validStatuses = ['open', 'resolved', 'pending', 'snoozed'];
        if (!in_array($newStatus, $validStatuses, true)) {
            throw new Error("Invalid status value: {$newStatus}. Must be one of: " . implode(', ', $validStatuses));
        }

        // Get the Chatwoot conversation ID
        $chatwootConversationId = $entity->get('chatwootConversationId');
        if (!$chatwootConversationId) {
            $this->log->warning(
                'ChatwootConversation ' . $entity->getId() . 
                ' has no chatwootConversationId, skipping Chatwoot sync'
            );
            return;
        }

        // Get the Chatwoot account
        $accountId = $entity->get('chatwootAccountId');
        if (!$accountId) {
            $this->log->warning(
                'ChatwootConversation ' . $entity->getId() . 
                ' has no chatwootAccountId, skipping Chatwoot sync'
            );
            return;
        }

        try {
            $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
            
            if (!$account) {
                throw new Error('ChatwootAccount not found: ' . $accountId);
            }

            $chatwootAccountId = $account->get('chatwootAccountId');
            if (!$chatwootAccountId) {
                throw new Error('ChatwootAccount has not been synchronized with Chatwoot.');
            }

            // Get account API key
            $accountApiKey = $account->get('apiKey');
            if (!$accountApiKey) {
                throw new Error('ChatwootAccount does not have an API key configured.');
            }

            // Get platform from account
            $platformId = $account->get('platformId');
            $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
            
            if (!$platform) {
                throw new Error('ChatwootPlatform not found: ' . $platformId);
            }

            // Get platform URL
            $platformUrl = $platform->get('backendUrl');
            if (!$platformUrl) {
                throw new Error('ChatwootPlatform does not have a URL.');
            }

            // Toggle status on Chatwoot
            $this->log->info(
                'User ' . $this->user->getId() . ' syncing conversation ' . $chatwootConversationId . 
                ' status change to Chatwoot: ' . $oldStatus . ' -> ' . $newStatus
            );
            
            $this->apiClient->toggleConversationStatus(
                $platformUrl,
                $accountApiKey,
                $chatwootAccountId,
                $chatwootConversationId,
                $newStatus
            );

            $this->log->info(
                'Successfully synced conversation ' . $chatwootConversationId . 
                ' status to ' . $newStatus . ' in Chatwoot'
            );

        } catch (Forbidden $e) {
            // Re-throw Forbidden exceptions as-is
            throw $e;
        } catch (\Exception $e) {
            $this->log->error(
                'Failed to sync conversation status to Chatwoot for conversation ' . 
                $entity->getId() . ': ' . $e->getMessage()
            );

            // Re-throw to prevent the status change from being saved
            throw new Error(
                'Failed to update conversation status on Chatwoot: ' . $e->getMessage() . 
                '. The status change was not saved to maintain synchronization.'
            );
        }
    }
}

