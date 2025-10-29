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

namespace Espo\Modules\IncomingWebhooks\Services;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\Services\Record;

class WebhookReceiver extends Record
{
    protected string $entityType = 'IncomingWebhook';
    
    /**
     * Process incoming webhook
     */
    public function processIncomingWebhook(
        ?object $payload, 
        array $headers, 
        string $sourceIp, 
        ?string $apiKey = null
    ): Entity {
        
        // Validate payload
        if (!$payload) {
            throw new BadRequest('Webhook payload is required');
        }
        
        // Generate webhook name
        $name = $this->generateWebhookName($payload);
        
        // Extract event type if available
        $event = $this->extractEventType($payload);
        
        // Extract webhook ID for idempotency
        $webhookId = $this->extractWebhookId($payload);
        
        // Extract signature for verification
        $signature = $this->extractSignature($headers);
        
        // Check for duplicate webhook (idempotency)
        if ($webhookId && $this->isDuplicateWebhook($webhookId)) {
            $existing = $this->findExistingWebhook($webhookId);
            $this->log->info("Duplicate webhook received: {$webhookId}");
            return $existing;
        }
        
        // Create the incoming webhook record
        $webhook = $this->entityManager->getNewEntity('IncomingWebhook');
        
        $webhook->set([
            'name' => $name,
            'event' => $event,
            'payload' => $payload,
            'headers' => $this->sanitizeHeaders($headers),
            'status' => 'Pending',
            'webhookId' => $webhookId,
            'signature' => $signature,
            'sourceIp' => $sourceIp,
            'retryCount' => 0
        ]);
        
        // Save the webhook
        $this->entityManager->saveEntity($webhook);
        
        $this->log->info("Incoming webhook received: {$webhook->getId()}", [
            'event' => $event,
            'webhookId' => $webhookId,
            'sourceIp' => $sourceIp
        ]);
        
        // Process the webhook asynchronously (optional)
        $this->queueWebhookProcessing($webhook);
        
        return $webhook;
    }
    
    /**
     * Generate a meaningful name for the webhook
     */
    private function generateWebhookName(object $payload): string
    {
        $timestamp = date('Y-m-d H:i:s');
        
        // Try to extract meaningful info from payload
        if (isset($payload->type)) {
            return "Webhook: {$payload->type} - {$timestamp}";
        }
        
        if (isset($payload->event)) {
            return "Webhook: {$payload->event} - {$timestamp}";
        }
        
        if (isset($payload->action)) {
            return "Webhook: {$payload->action} - {$timestamp}";
        }
        
        return "Webhook: Generic - {$timestamp}";
    }
    
    /**
     * Extract event type from payload
     */
    private function extractEventType(object $payload): ?string
    {
        // Common event field names
        $eventFields = ['type', 'event', 'event_type', 'action', 'trigger'];
        
        foreach ($eventFields as $field) {
            if (isset($payload->$field) && is_string($payload->$field)) {
                return $payload->$field;
            }
        }
        
        return null;
    }
    
    /**
     * Extract webhook ID for idempotency
     */
    private function extractWebhookId(object $payload): ?string
    {
        // Common ID field names
        $idFields = ['id', 'webhook_id', 'event_id', 'request_id', 'delivery_id'];
        
        foreach ($idFields as $field) {
            if (isset($payload->$field) && (is_string($payload->$field) || is_numeric($payload->$field))) {
                return (string) $payload->$field;
            }
        }
        
        return null;
    }
    
    /**
     * Extract signature from headers
     */
    private function extractSignature(array $headers): ?string
    {
        $signatureHeaders = [
            'X-Signature',
            'X-Hub-Signature',
            'X-Hub-Signature-256',
            'X-Webhook-Signature',
            'Stripe-Signature',
            'X-GitHub-Event'
        ];
        
        foreach ($signatureHeaders as $header) {
            if (isset($headers[$header])) {
                return $headers[$header];
            }
        }
        
        return null;
    }
    
    /**
     * Check if webhook is duplicate
     */
    private function isDuplicateWebhook(string $webhookId): bool
    {
        $existing = $this->entityManager
            ->getRDBRepository('IncomingWebhook')
            ->where(['webhookId' => $webhookId])
            ->findOne();
            
        return $existing !== null;
    }
    
    /**
     * Find existing webhook by webhook ID
     */
    private function findExistingWebhook(string $webhookId): Entity
    {
        $existing = $this->entityManager
            ->getRDBRepository('IncomingWebhook')
            ->where(['webhookId' => $webhookId])
            ->findOne();
            
        if (!$existing) {
            throw new Error('Existing webhook not found');
        }
        
        return $existing;
    }
    
    /**
     * Sanitize headers (remove sensitive information)
     */
    private function sanitizeHeaders(array $headers): array
    {
        $sensitiveHeaders = [
            'Authorization',
            'Cookie',
            'Set-Cookie',
            'X-API-Key',
            'X-Auth-Token'
        ];
        
        $sanitized = [];
        
        foreach ($headers as $key => $value) {
            if (in_array($key, $sensitiveHeaders)) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Queue webhook for processing
     */
    private function queueWebhookProcessing(Entity $webhook): void
    {
        // For now, process immediately
        // You can integrate with EspoCRM's job system later for async processing
        $this->processWebhookData($webhook);
    }
    
    /**
     * Process webhook data
     */
    private function processWebhookData(Entity $webhook): void
    {
        try {
            $payload = $webhook->get('payload');
            $event = $webhook->get('event');
            
            $this->log->info("Processing webhook: {$webhook->getId()}", [
                'event' => $event,
                'webhookId' => $webhook->get('webhookId')
            ]);
            
            // Generic processing logic
            // You can add custom processing logic here based on event types
            $this->processGenericWebhook($webhook, $payload);
            
            // Mark as processed
            $webhook->set([
                'status' => 'Processed',
                'processedAt' => date('Y-m-d H:i:s')
            ]);
            
            $this->log->info("Webhook processed successfully: {$webhook->getId()}");
            
        } catch (\Exception $e) {
            $this->log->error("Webhook processing failed: {$webhook->getId()}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Mark as failed
            $webhook->set([
                'status' => 'Failed',
                'errorMessage' => $e->getMessage(),
                'retryCount' => $webhook->get('retryCount') + 1
            ]);
        }
        
        // Save the updated webhook
        $this->entityManager->saveEntity($webhook);
    }
    
    /**
     * Process generic webhook (placeholder for custom logic)
     */
    private function processGenericWebhook(Entity $webhook, object $payload): void
    {
        // This is where you can add your custom processing logic
        // For example:
        // - Create or update entities based on webhook data
        // - Send notifications
        // - Trigger workflows
        // - Integrate with external systems
        
        $this->log->debug("Processing generic webhook", [
            'webhookId' => $webhook->getId(),
            'payloadKeys' => array_keys((array) $payload)
        ]);
        
        // Example: You could implement different processing based on event type
        $event = $webhook->get('event');
        
        switch ($event) {
            case 'user.created':
                // Handle user creation
                break;
            case 'order.completed':
                // Handle order completion
                break;
            default:
                // Default handling
                break;
        }
    }
}

