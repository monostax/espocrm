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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootAccountWebhook;

use Espo\Core\Exceptions\Error;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Hook to synchronize ChatwootAccountWebhook with Chatwoot API.
 * Creates/updates webhooks on Chatwoot BEFORE saving to database.
 */
class SyncWithChatwoot
{
    public static int $order = 10; // Run after ValidateBeforeSync

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    /**
     * Create or update webhook on Chatwoot BEFORE entity is saved to database.
     * 
     * @param Entity $entity
     * @param array<string, mixed> $options
     * @throws Error
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        // Skip if this is a silent save (internal operation)
        if (!empty($options['silent'])) {
            return;
        }

        $accountId = $entity->get('accountId');
        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
        
        if (!$account) {
            throw new Error('ChatwootAccount not found: ' . $accountId);
        }

        $chatwootAccountId = $account->get('chatwootAccountId');
        $apiKey = $account->get('apiKey');
        
        // Get platform from account
        $platformId = $account->get('platformId');
        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
        
        if (!$platform) {
            throw new Error('ChatwootPlatform not found: ' . $platformId);
        }

        $platformUrl = $platform->get('url');

        if (!$platformUrl) {
            throw new Error('ChatwootPlatform does not have a URL.');
        }

        if (!$apiKey) {
            throw new Error('ChatwootAccount does not have an API key.');
        }

        try {
            if ($entity->isNew()) {
                // CREATE: Create webhook on Chatwoot
                $this->log->info('Creating Chatwoot webhook: ' . $entity->get('name'));
                
                $webhookData = $this->prepareWebhookData($entity);
                $webhookResponse = $this->apiClient->createWebhook(
                    $platformUrl,
                    $apiKey,
                    $chatwootAccountId,
                    $webhookData
                );

                // Extract webhook ID from response
                // Chatwoot may return the webhook data directly or nested in payload.webhook
                $chatwootWebhookId = null;
                
                if (isset($webhookResponse['id'])) {
                    // Direct format: {"id": 123, "name": "...", ...}
                    $chatwootWebhookId = $webhookResponse['id'];
                } elseif (isset($webhookResponse['payload']['webhook']['id'])) {
                    // Nested format: {"payload": {"webhook": {"id": 123, ...}}}
                    $chatwootWebhookId = $webhookResponse['payload']['webhook']['id'];
                }

                if (!$chatwootWebhookId) {
                    $this->log->error(
                        'Chatwoot API response missing webhook ID. Full response: ' . 
                        json_encode($webhookResponse)
                    );
                    throw new Error('Chatwoot API response missing webhook ID.');
                }

                $entity->set('chatwootWebhookId', $chatwootWebhookId);
                
                $this->log->info('Chatwoot webhook created successfully with ID: ' . $chatwootWebhookId);

            } else {
                // UPDATE: Update webhook on Chatwoot
                $chatwootWebhookId = $entity->get('chatwootWebhookId');
                
                if (!$chatwootWebhookId) {
                    throw new Error(
                        'Cannot update webhook: chatwootWebhookId is missing. ' .
                        'This webhook may not have been properly synchronized.'
                    );
                }

                // Only update if relevant fields changed
                if ($entity->isAttributeChanged('name') || 
                    $entity->isAttributeChanged('url') || 
                    $entity->isAttributeChanged('subscriptions')) {
                    
                    $this->log->info('Updating Chatwoot webhook: ' . $chatwootWebhookId);
                    
                    $webhookData = $this->prepareWebhookData($entity);
                    $this->apiClient->updateWebhook(
                        $platformUrl,
                        $apiKey,
                        $chatwootAccountId,
                        $chatwootWebhookId,
                        $webhookData
                    );
                    
                    $this->log->info('Chatwoot webhook updated successfully: ' . $chatwootWebhookId);
                }
            }

        } catch (\Exception $e) {
            $this->log->error(
                'Failed to sync webhook with Chatwoot for ' . $entity->get('name') . 
                ': ' . $e->getMessage()
            );
            
            throw new Error(
                'Failed to ' . ($entity->isNew() ? 'create' : 'update') . 
                ' webhook on Chatwoot: ' . $e->getMessage()
            );
        }
    }

    /**
     * Delete webhook from Chatwoot AFTER entity is removed from database.
     * 
     * @param Entity $entity
     * @param array<string, mixed> $options
     */
    public function afterRemove(Entity $entity, array $options): void
    {
        // Skip if this is a silent delete (internal operation)
        if (!empty($options['silent'])) {
            return;
        }

        $chatwootWebhookId = $entity->get('chatwootWebhookId');
        
        // If no Chatwoot webhook ID, nothing to delete
        if (!$chatwootWebhookId) {
            $this->log->warning(
                'Webhook deleted from EspoCRM but has no chatwootWebhookId: ' . $entity->get('id')
            );
            return;
        }

        try {
            $accountId = $entity->get('accountId');
            $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
            
            if (!$account) {
                $this->log->warning('Cannot delete webhook from Chatwoot: Account not found');
                return;
            }

            $chatwootAccountId = $account->get('chatwootAccountId');
            $apiKey = $account->get('apiKey');
            
            $platformId = $account->get('platformId');
            $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
            
            if (!$platform) {
                $this->log->warning('Cannot delete webhook from Chatwoot: Platform not found');
                return;
            }

            $platformUrl = $platform->get('url');

            if (!$apiKey || !$platformUrl) {
                $this->log->warning('Cannot delete webhook from Chatwoot: Missing credentials');
                return;
            }

            $this->log->info('Deleting Chatwoot webhook: ' . $chatwootWebhookId);
            
            $this->apiClient->deleteWebhook(
                $platformUrl,
                $apiKey,
                $chatwootAccountId,
                $chatwootWebhookId
            );
            
            $this->log->info('Chatwoot webhook deleted successfully: ' . $chatwootWebhookId);

        } catch (\Exception $e) {
            // Log but don't throw - the entity is already deleted from EspoCRM
            $this->log->error(
                'Failed to delete webhook from Chatwoot (ID: ' . $chatwootWebhookId . '): ' . 
                $e->getMessage()
            );
        }
    }

    /**
     * Prepare webhook data for Chatwoot API.
     *
     * @param Entity $entity
     * @return array<string, mixed>
     */
    private function prepareWebhookData(Entity $entity): array
    {
        $data = [
            'url' => $entity->get('url'),
            'name' => $entity->get('name'),
            'subscriptions' => $entity->get('subscriptions') ?? []
        ];

        return $data;
    }
}