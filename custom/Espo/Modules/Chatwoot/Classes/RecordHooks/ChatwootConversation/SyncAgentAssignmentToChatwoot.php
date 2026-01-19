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
 * Hook to synchronize ChatwootConversation agent assignment changes with Chatwoot.
 * When a conversation's assignee is changed, this hook syncs the change back to Chatwoot.
 * 
 * Security: Enforces ACL checks to ensure users can only modify conversations
 * they have access to (respects team-based multi-tenancy).
 */
class SyncAgentAssignmentToChatwoot implements UpdateHook
{
    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log,
        private Acl $acl,
        private User $user
    ) {}

    /**
     * Sync agent assignment change to Chatwoot BEFORE entity is saved to database.
     * If the operation fails, an exception is thrown and the change is not saved.
     * 
     * @throws Error
     * @throws Forbidden
     */
    public function process(Entity $entity, UpdateParams $params): void
    {
        // Only process if assigneeId field was changed
        if (!$entity->isAttributeChanged('assigneeId')) {
            return;
        }

        $newAssigneeId = $entity->get('assigneeId');
        $oldAssigneeId = $entity->getFetched('assigneeId');
        
        // Skip if assignee hasn't actually changed
        if ($newAssigneeId === $oldAssigneeId) {
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
                'User ' . $this->user->getId() . ' attempted to change conversation assignee ' .
                'without scope-level edit permission'
            );
            throw new Forbidden('Access denied. You do not have permission to edit conversations.');
        }
        
        // Check entity-level edit permission (respects team-based access)
        if (!$this->acl->check($entity, AclTable::ACTION_EDIT)) {
            $this->log->warning(
                'User ' . $this->user->getId() . ' attempted to change assignee of conversation ' .
                $entity->getId() . ' without entity-level edit permission (team restriction)'
            );
            throw new Forbidden('Access denied. You do not have permission to edit this conversation.');
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
            $platformUrl = $platform->get('url');
            if (!$platformUrl) {
                throw new Error('ChatwootPlatform does not have a URL.');
            }

            // Assign conversation on Chatwoot
            $this->log->info(
                'User ' . $this->user->getId() . ' syncing conversation ' . $chatwootConversationId . 
                ' assignee change to Chatwoot: ' . ($oldAssigneeId ?? 'null') . ' -> ' . ($newAssigneeId ?? 'null')
            );
            
            $this->apiClient->assignConversation(
                $platformUrl,
                $accountApiKey,
                $chatwootAccountId,
                $chatwootConversationId,
                $newAssigneeId // Pass null to unassign
            );

            $this->log->info(
                'Successfully synced conversation ' . $chatwootConversationId . 
                ' assignee to ' . ($newAssigneeId ?? 'unassigned') . ' in Chatwoot'
            );

        } catch (Forbidden $e) {
            // Re-throw Forbidden exceptions as-is
            throw $e;
        } catch (\Exception $e) {
            $this->log->error(
                'Failed to sync conversation assignee to Chatwoot for conversation ' . 
                $entity->getId() . ': ' . $e->getMessage()
            );

            // Re-throw to prevent the assignee change from being saved
            throw new Error(
                'Failed to update conversation assignee on Chatwoot: ' . $e->getMessage() . 
                '. The assignee change was not saved to maintain synchronization.'
            );
        }
    }
}
