<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Hooks\ChatwootLabel;

use Espo\Core\Exceptions\Error;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Hook to synchronize ChatwootLabel with Chatwoot API.
 * 
 * - beforeSave: Create/update label in Chatwoot
 * - beforeRemove: Delete label from Chatwoot
 */
class SyncWithChatwoot
{
    public static int $order = 10;

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    /**
     * Create or update label in Chatwoot before saving to database.
     *
     * @param Entity $entity The ChatwootLabel entity
     * @param array<string, mixed> $options
     * @throws Error
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        // Skip if silent (internal sync operation)
        if (!empty($options['silent'])) {
            return;
        }

        // Skip if already has chatwootLabelId and no relevant fields changed
        if (!$entity->isNew() && $entity->get('chatwootLabelId')) {
            $changedFields = ['name', 'description', 'color', 'showOnSidebar'];
            $hasRelevantChanges = false;
            
            foreach ($changedFields as $field) {
                if ($entity->isAttributeChanged($field)) {
                    $hasRelevantChanges = true;
                    break;
                }
            }
            
            if (!$hasRelevantChanges) {
                return;
            }
        }

        try {
            $chatwootAccount = $this->getChatwootAccount($entity);
            
            if (!$chatwootAccount) {
                throw new Error('ChatwootAccount is required for ChatwootLabel.');
            }

            $platform = $this->entityManager->getEntityById(
                'ChatwootPlatform',
                $chatwootAccount->get('platformId')
            );

            if (!$platform) {
                throw new Error('ChatwootPlatform not found for account.');
            }

            $platformUrl = $platform->get('backendUrl');
            $accountApiKey = $chatwootAccount->get('apiKey');
            $chatwootAccountId = $chatwootAccount->get('chatwootAccountId');

            if (!$platformUrl || !$accountApiKey || !$chatwootAccountId) {
                throw new Error('ChatwootAccount is not properly configured (missing URL, API key, or account ID).');
            }

            // Sanitize label name to match Chatwoot requirements (alphanumeric, -, _)
            $safeName = $entity->get('name');
            $safeName = preg_replace('/[^a-zA-Z0-9\-_]/', '-', $safeName);
            $safeName = preg_replace('/-+/', '-', $safeName); // Replace multiple hyphens with single
            $safeName = trim($safeName, '-');
            
            // If sanitation resulted in modification, update the entity
            if ($safeName !== $entity->get('name')) {
                $entity->set('name', $safeName);
            }

            $labelData = [
                'title' => $entity->get('name'),
                'description' => $entity->get('description') ?? '',
                'color' => $entity->get('color') ?? '#1f93ff',
                'show_on_sidebar' => $entity->get('showOnSidebar') ?? true,
            ];

            if ($entity->isNew()) {
                // Create new label in Chatwoot
                $this->log->info('Creating Chatwoot label: ' . $entity->get('name'));
                
                $response = $this->apiClient->createLabel(
                    $platformUrl,
                    $accountApiKey,
                    $chatwootAccountId,
                    $labelData
                );

                if (!isset($response['id'])) {
                    throw new Error('Chatwoot API response missing label ID.');
                }

                $entity->set('chatwootLabelId', $response['id']);
                $entity->set('syncStatus', 'synced');
                $entity->set('lastSyncedAt', date('Y-m-d H:i:s'));
                $entity->set('lastSyncError', null);


                $this->log->info('Chatwoot label created with ID: ' . $response['id']);
            } else {
                // Update existing label in Chatwoot
                $chatwootLabelId = $entity->get('chatwootLabelId');
                
                if ($chatwootLabelId) {
                    $this->log->info('Updating Chatwoot label ID: ' . $chatwootLabelId);
                    
                    $this->apiClient->updateLabel(
                        $platformUrl,
                        $accountApiKey,
                        $chatwootAccountId,
                        $chatwootLabelId,
                        $labelData
                    );

                    $entity->set('syncStatus', 'synced');
                    $entity->set('lastSyncedAt', date('Y-m-d H:i:s'));
                    $entity->set('lastSyncError', null);

                    $this->log->info('Chatwoot label updated: ' . $chatwootLabelId);
                }
            }
        } catch (\Exception $e) {
            $this->log->error(
                'Failed to sync ChatwootLabel with Chatwoot: ' . $e->getMessage()
            );
            
            $entity->set('syncStatus', 'error');
            $entity->set('lastSyncError', $e->getMessage());
            
            // For new entities, we must fail - can't save without chatwootLabelId
            if ($entity->isNew()) {
                throw new Error('Failed to create label in Chatwoot: ' . $e->getMessage());
            }
        }
    }

    /**
     * Delete label from Chatwoot before removing from database.
     *
     * @param Entity $entity The ChatwootLabel entity
     * @param array<string, mixed> $options
     */
    public function beforeRemove(Entity $entity, array $options): void
    {
        // Skip if silent (internal sync operation) or cascadeParent (parent being deleted)
        if (!empty($options['silent']) || !empty($options['cascadeParent'])) {
            return;
        }

        $chatwootLabelId = $entity->get('chatwootLabelId');
        
        if (!$chatwootLabelId) {
            return;
        }

        try {
            $chatwootAccount = $this->getChatwootAccount($entity);
            
            if (!$chatwootAccount) {
                $this->log->warning('ChatwootAccount not found for label deletion');
                return;
            }

            $platform = $this->entityManager->getEntityById(
                'ChatwootPlatform',
                $chatwootAccount->get('platformId')
            );

            if (!$platform) {
                $this->log->warning('ChatwootPlatform not found for label deletion');
                return;
            }

            $platformUrl = $platform->get('backendUrl');
            $accountApiKey = $chatwootAccount->get('apiKey');
            $chatwootAccountId = $chatwootAccount->get('chatwootAccountId');

            if (!$platformUrl || !$accountApiKey || !$chatwootAccountId) {
                $this->log->warning('ChatwootAccount not properly configured for label deletion');
                return;
            }

            $this->log->info('Deleting Chatwoot label ID: ' . $chatwootLabelId);
            
            $this->apiClient->deleteLabel(
                $platformUrl,
                $accountApiKey,
                $chatwootAccountId,
                $chatwootLabelId
            );

            $this->log->info('Chatwoot label deleted: ' . $chatwootLabelId);
        } catch (\Exception $e) {
            // Log but don't fail - allow deletion in CRM even if Chatwoot fails
            $this->log->error(
                'Failed to delete label from Chatwoot: ' . $e->getMessage()
            );
        }
    }

    /**
     * Get the ChatwootAccount for the label.
     */
    private function getChatwootAccount(Entity $entity): ?Entity
    {
        $accountId = $entity->get('chatwootAccountId');
        
        if (!$accountId) {
            return null;
        }

        return $this->entityManager->getEntityById('ChatwootAccount', $accountId);
    }
}
