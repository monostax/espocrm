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

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Log;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Controller to receive WAHA label webhook events.
 * 
 * Handles label.chat.added and label.chat.deleted events from WAHA.
 * Validates HMAC signature and queues a job for async processing.
 */
class WahaLabelWebhook
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
        private JobSchedulerFactory $jobSchedulerFactory
    ) {}

    /**
     * Receive a label webhook from WAHA.
     * 
     * POST /api/v1/WahaLabelWebhook/:channelId
     *
     * @param Request $request
     * @param Response $response
     * @return stdClass
     * @throws BadRequest
     * @throws Forbidden
     * @throws NotFound
     */
    public function postActionReceive(Request $request, Response $response): stdClass
    {
        $channelId = $request->getRouteParam('channelId');
        
        if (!$channelId) {
            throw new BadRequest('Missing channelId parameter');
        }

        // Get the raw body for HMAC validation
        $rawBody = file_get_contents('php://input');
        $data = json_decode($rawBody);

        if (!$data) {
            throw new BadRequest('Invalid JSON payload');
        }

        $this->log->info("WahaLabelWebhook: Received webhook for channel {$channelId}", [
            'event' => $data->event ?? 'unknown',
        ]);

        // Get the channel to validate HMAC
        $channel = $this->entityManager->getEntityById('ChatwootInboxIntegration', $channelId);

        if (!$channel) {
            $this->log->warning("WahaLabelWebhook: Channel not found: {$channelId}");
            throw new NotFound("Channel not found: {$channelId}");
        }

        // Validate HMAC signature
        $signature = $this->getSignatureFromHeaders();
        $webhookSecret = $channel->get('wahaWebhookSecret');

        if (!$webhookSecret) {
            $this->log->warning("WahaLabelWebhook: Channel {$channelId} has no webhook secret configured");
            throw new Forbidden('Webhook secret not configured');
        }

        if (!$this->validateHmacSignature($rawBody, $signature, $webhookSecret)) {
            $this->log->warning("WahaLabelWebhook: Invalid signature for channel {$channelId}");
            throw new Forbidden('Invalid signature');
        }

        // Validate event type
        $event = $data->event ?? null;
        if (!in_array($event, ['label.chat.added', 'label.chat.deleted'])) {
            $this->log->info("WahaLabelWebhook: Ignoring event type: {$event}");
            return (object) [
                'success' => true,
                'message' => 'Event ignored',
            ];
        }

        // Queue job for async processing
        $jobScheduler = $this->jobSchedulerFactory->create();
        
        $jobScheduler
            ->setClassName('Espo\\Modules\\Chatwoot\\Jobs\\ProcessWahaLabelWebhook')
            ->setData([
                'channelId' => $channelId,
                'event' => $event,
                'payload' => $data->payload ?? null,
                'session' => $data->session ?? null,
            ])
            ->schedule();

        $this->log->info("WahaLabelWebhook: Queued ProcessWahaLabelWebhook job for channel {$channelId}");

        return (object) [
            'success' => true,
            'message' => 'Webhook received and queued for processing',
        ];
    }

    /**
     * Get HMAC signature from request headers.
     * WAHA sends the signature in the X-Webhook-Hmac header.
     *
     * @return string|null
     */
    private function getSignatureFromHeaders(): ?string
    {
        // WAHA sends signature in X-Webhook-Hmac header
        $headerNames = [
            'HTTP_X_WEBHOOK_HMAC',
            'HTTP_X_WAHA_SIGNATURE',  // fallback for older versions
        ];

        foreach ($headerNames as $headerName) {
            if (isset($_SERVER[$headerName])) {
                return $_SERVER[$headerName];
            }
        }

        return null;
    }

    /**
     * Validate HMAC signature.
     *
     * @param string $rawBody
     * @param string|null $signature
     * @param string $secret
     * @return bool
     */
    private function validateHmacSignature(string $rawBody, ?string $signature, string $secret): bool
    {
        if (!$signature) {
            return false;
        }

        // Remove algorithm prefix if present (e.g., "sha512=...")
        if (strpos($signature, '=') !== false) {
            $parts = explode('=', $signature, 2);
            $signature = $parts[1] ?? $signature;
        }

        // WAHA uses sha512 algorithm
        $expectedSignature = hash_hmac('sha512', $rawBody, $secret);

        // Use timing-safe comparison
        return hash_equals($expectedSignature, $signature);
    }
}
