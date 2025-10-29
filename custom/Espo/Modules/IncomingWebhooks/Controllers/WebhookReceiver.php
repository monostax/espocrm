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

namespace Espo\Modules\IncomingWebhooks\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use stdClass;

class WebhookReceiver
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}
    
    public function postActionReceive(Request $request, Response $response): stdClass
    {
        try {
            // Get request data
            $data = $request->getParsedBody();
            $apiKey = $request->getRouteParam("apiKey");
            
            // Log the webhook
            $this->log->info("Webhook received", ["data" => $data, "apiKey" => $apiKey]);
            
            // Validate content type
            $contentType = $_SERVER["CONTENT_TYPE"] ?? "";
            if (!$contentType || strpos($contentType, "application/json") === false) {
                throw new BadRequest("Content-Type must be application/json");
            }
            
            // Extract headers
            $headers = $this->extractHeaders();
            
            // Generate webhook name
            $name = $this->generateWebhookName($data);
            
            // Extract event type
            $event = $this->extractEventType($data);
            
            // Extract webhook ID for idempotency
            $webhookId = $this->extractWebhookId($data);
            
            // Create the incoming webhook record
            $webhook = $this->entityManager->getNewEntity("IncomingWebhook");
            
            $webhook->set([
                "name" => $name,
                "event" => $event,
                "payload" => $data,
                "headers" => $this->sanitizeHeaders($headers),
                "status" => "Pending",
                "webhookId" => $webhookId,
                "sourceIp" => $this->getClientIp(),
                "retryCount" => 0
            ]);
            
            // Save the webhook
            $this->entityManager->saveEntity($webhook);
            
            $this->log->info("Webhook saved successfully", ["id" => $webhook->getId()]);
            
            // Set success response
            $response->setStatus(200);
            $response->setHeader("Content-Type", "application/json");
            
            return (object) [
                "success" => true,
                "id" => $webhook->getId(),
                "message" => "Webhook received and stored successfully"
            ];
            
        } catch (\Exception $e) {
            $this->log->error("Webhook processing failed: " . $e->getMessage());
            
            $response->setStatus(500);
            
            return (object) [
                "success" => false,
                "error" => $e->getMessage(),
                "message" => "Failed to process webhook"
            ];
        }
    }
    
    private function extractHeaders(): array
    {
        $headers = [];
        
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, "HTTP_") === 0) {
                $header = str_replace(" ", "-", ucwords(str_replace("_", " ", strtolower(substr($key, 5)))));
                $headers[$header] = $value;
            }
        }
        
        if (isset($_SERVER["CONTENT_TYPE"])) {
            $headers["Content-Type"] = $_SERVER["CONTENT_TYPE"];
        }
        
        return $headers;
    }
    
    private function generateWebhookName($payload): string
    {
        $timestamp = date("Y-m-d H:i:s");
        
        if (isset($payload->type)) {
            return "Webhook: {$payload->type} - {$timestamp}";
        }
        
        if (isset($payload->event)) {
            return "Webhook: {$payload->event} - {$timestamp}";
        }
        
        return "Webhook: Generic - {$timestamp}";
    }
    
    private function extractEventType($payload): ?string
    {
        $eventFields = ["type", "event", "event_type", "action", "trigger"];
        
        foreach ($eventFields as $field) {
            if (isset($payload->$field) && is_string($payload->$field)) {
                return $payload->$field;
            }
        }
        
        return null;
    }
    
    private function extractWebhookId($payload): ?string
    {
        $idFields = ["id", "webhook_id", "event_id", "request_id", "delivery_id"];
        
        foreach ($idFields as $field) {
            if (isset($payload->$field) && (is_string($payload->$field) || is_numeric($payload->$field))) {
                return (string) $payload->$field;
            }
        }
        
        return null;
    }
    
    private function sanitizeHeaders(array $headers): array
    {
        $sensitiveHeaders = ["Authorization", "Cookie", "Set-Cookie", "X-API-Key", "X-Auth-Token"];
        
        $sanitized = [];
        
        foreach ($headers as $key => $value) {
            if (in_array($key, $sensitiveHeaders)) {
                $sanitized[$key] = "[REDACTED]";
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }
    
    private function getClientIp(): string
    {
        $ipHeaders = ["HTTP_X_FORWARDED_FOR", "HTTP_X_REAL_IP", "HTTP_X_CLIENT_IP", "HTTP_CLIENT_IP"];
        
        foreach ($ipHeaders as $header) {
            if (isset($_SERVER[$header]) && $_SERVER[$header] !== "unknown") {
                $ips = explode(",", $_SERVER[$header]);
                return trim($ips[0]);
            }
        }
        
        return $_SERVER["REMOTE_ADDR"] ?? "unknown";
    }
}

