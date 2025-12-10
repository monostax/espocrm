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

namespace Espo\Modules\Chatwoot\Hooks\IncomingWebhook;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Config;
use Espo\Modules\Chatwoot\Services\ContactSyncService;

/**
 * Hook to process Chatwoot webhooks from IncomingWebhook entity.
 * 
 * This hook runs after an IncomingWebhook is saved and processes
 * Chatwoot-specific events to sync contacts bidirectionally.
 */
class ProcessChatwoot implements AfterSave
{
    public static int $order = 15;

    public function __construct(
        private EntityManager $entityManager,
        private ContactSyncService $syncService,
        private Log $log,
        private Config $config
    ) {}

    /**
     * Process Chatwoot webhook after it's saved.
     */
    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        // Skip if sync is disabled in config
        if (!$this->config->get('chatwootContactSyncEnabled', true)) {
            return;
        }

        // Only process new webhooks in Pending status
        if (!$entity->isNew() || $entity->get('status') !== 'Pending') {
            return;
        }

        // Check if this is a Chatwoot webhook
        if (!$this->isChatwootWebhook($entity)) {
            return;
        }

        $event = $entity->get('event');
        
        $this->log->info('Processing Chatwoot webhook: ' . $entity->getId(), [
            'event' => $event,
            'webhookId' => $entity->get('webhookId')
        ]);

        try {
            // Get payload
            $payload = $entity->get('payload');
            
            if (!$payload) {
                throw new \Exception('Webhook payload is empty');
            }

            // Convert stdClass to array for processing
            $payloadArray = json_decode(json_encode($payload), true);

            // Process based on event type
            switch ($event) {
                case 'contact_created':
                case 'contact_updated':
                    $this->syncService->handleChatwootContactWebhook($payloadArray);
                    break;
                    
                case 'conversation_created':
                case 'message_created':
                    // Handle conversation/message events (may contain contact info)
                    if (isset($payloadArray['contact'])) {
                        $this->syncService->handleChatwootContactWebhook($payloadArray);
                    }
                    break;
                    
                default:
                    $this->log->debug('Chatwoot webhook event not handled: ' . $event);
                    break;
            }

            // Mark as processed
            $entity->set('status', 'Processed');
            $entity->set('processedAt', date('Y-m-d H:i:s'));
            
            $this->entityManager->saveEntity($entity, [
                'skipHooks' => true,
                'silent' => true
            ]);

            $this->log->info('Successfully processed Chatwoot webhook: ' . $entity->getId());

        } catch (\Exception $e) {
            $this->log->error(
                'Failed to process Chatwoot webhook: ' . $entity->getId() . 
                ' - ' . $e->getMessage()
            );

            // Mark as failed
            $entity->set('status', 'Failed');
            $entity->set('errorMessage', $e->getMessage());
            $entity->set('retryCount', $entity->get('retryCount') + 1);
            
            $this->entityManager->saveEntity($entity, [
                'skipHooks' => true,
                'silent' => true
            ]);
        }
    }

    /**
     * Check if the webhook is from Chatwoot.
     *
     * @param Entity $entity
     * @return bool
     */
    private function isChatwootWebhook(Entity $entity): bool
    {
        // Check by event naming convention
        $event = $entity->get('event');
        if ($event && $this->isChatwootEvent($event)) {
            return true;
        }

        // Check by headers
        $headers = $entity->get('headers');
        if ($headers) {
            $headersArray = json_decode(json_encode($headers), true);
            
            // Check for Chatwoot-specific headers
            if (isset($headersArray['User-Agent']) && 
                strpos($headersArray['User-Agent'], 'Chatwoot') !== false) {
                return true;
            }
        }

        // Check payload structure
        $payload = $entity->get('payload');
        if ($payload) {
            $payloadArray = json_decode(json_encode($payload), true);
            
            // Chatwoot webhooks typically have 'account' and specific event structures
            if (isset($payloadArray['account']['id']) && 
                (isset($payloadArray['contact']) || 
                 isset($payloadArray['conversation']) || 
                 isset($payloadArray['message']))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if event name is a Chatwoot event.
     *
     * @param string $event
     * @return bool
     */
    private function isChatwootEvent(string $event): bool
    {
        $chatwootEvents = [
            'contact_created',
            'contact_updated',
            'conversation_created',
            'conversation_updated',
            'conversation_resolved',
            'conversation_opened',
            'message_created',
            'message_updated',
            'webwidget_triggered'
        ];

        return in_array($event, $chatwootEvents);
    }
}

