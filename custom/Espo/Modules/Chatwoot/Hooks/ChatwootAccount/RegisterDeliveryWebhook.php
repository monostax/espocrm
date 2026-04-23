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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootAccount;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Automatically registers Chatwoot webhooks when a new ChatwootAccount is created:
 *
 * 1. WhatsApp Delivery Status — subscribed to `message_updated` and `message_created`,
 *    points to the CRM's DeliveryWebhook controller for campaign tracking.
 *
 * 2. Hatchet AI Agent — subscribed to `message_created` and `conversation_updated`,
 *    points to the Hatchet webhook ingest endpoint so incoming messages and
 *    conversation status changes trigger the AI agent workflow.
 *
 * Each creates a ChatwootAccountWebhook entity which triggers the SyncWithChatwoot
 * hook to register it on the Chatwoot side.
 */
class RegisterDeliveryWebhook
{
    public static int $order = 30;

    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private Log $log
    ) {}

    public function afterSave(Entity $entity, array $options): void
    {
        $chatwootAccountId = $entity->get('chatwootAccountId');

        if (!$chatwootAccountId) {
            return;
        }

        // Idempotent: each register method checks if the webhook already exists.
        // This allows safe execution on every save and self-heals missing webhooks.
        $this->registerDeliveryWebhook($entity, $chatwootAccountId);
        $this->registerHatchetWebhook($entity, $chatwootAccountId);
    }

    private function webhookExists(Entity $entity, string $name): bool
    {
        $existing = $this->entityManager
            ->getRDBRepository('ChatwootAccountWebhook')
            ->where([
                'accountId' => $entity->getId(),
                'name' => $name,
            ])
            ->findOne();

        return (bool) $existing;
    }

    /**
     * Register WhatsApp Delivery Status webhook.
     */
    private function registerDeliveryWebhook(Entity $entity, int $chatwootAccountId): void
    {
        if ($this->webhookExists($entity, 'WhatsApp Delivery Status')) {
            return;
        }

        $crmBackendUrl = getenv('CRM_BACKEND_URL') ?: $this->config->get('siteUrl');

        if (!$crmBackendUrl) {
            $this->log->warning(
                "RegisterDeliveryWebhook: Cannot register delivery webhook for account {$entity->getId()} — " .
                "neither CRM_BACKEND_URL env nor siteUrl config is set."
            );
            return;
        }

        $webhookUrl = rtrim($crmBackendUrl, '/') . '/api/v1/WhatsAppDeliveryWebhook/' . $chatwootAccountId;

        try {
            $this->entityManager->createEntity('ChatwootAccountWebhook', [
                'name' => 'WhatsApp Delivery Status',
                'accountId' => $entity->getId(),
                'url' => $webhookUrl,
                'subscriptions' => ['message_updated', 'message_created'],
            ]);

            $this->log->info(
                "RegisterDeliveryWebhook: Registered delivery webhook for account " .
                "{$entity->getId()} (Chatwoot #{$chatwootAccountId}) at {$webhookUrl}"
            );
        } catch (\Exception $e) {
            $this->log->error(
                "RegisterDeliveryWebhook: Failed to register delivery webhook for account " .
                "{$entity->getId()}: {$e->getMessage()}"
            );
        }
    }

    /**
     * Register Hatchet AI Agent webhook.
     *
     * Sends `message_created` and `conversation_updated` events to the Hatchet
     * webhook ingest endpoint, which triggers the AI agent workflows.
     * The URL is provided by the HATCHET_WEBHOOK_URL environment variable
     * (internal k8s service URL).
     */
    private function registerHatchetWebhook(Entity $entity, int $chatwootAccountId): void
    {
        if ($this->webhookExists($entity, 'Hatchet AI Agent')) {
            return;
        }

        $hatchetWebhookUrl = getenv('HATCHET_CHATWOOT_WEBHOOK_URL');

        if (!$hatchetWebhookUrl) {
            $this->log->debug(
                "RegisterDeliveryWebhook: HATCHET_CHATWOOT_WEBHOOK_URL not set, skipping Hatchet webhook for account {$entity->getId()}"
            );
            return;
        }

        try {
            $this->entityManager->createEntity('ChatwootAccountWebhook', [
                'name' => 'Hatchet AI Agent',
                'accountId' => $entity->getId(),
                'url' => $hatchetWebhookUrl,
                'subscriptions' => ['message_created', 'conversation_updated'],
            ]);

            $this->log->info(
                "RegisterDeliveryWebhook: Registered Hatchet AI Agent webhook for account " .
                "{$entity->getId()} (Chatwoot #{$chatwootAccountId}) at {$hatchetWebhookUrl}"
            );
        } catch (\Exception $e) {
            $this->log->error(
                "RegisterDeliveryWebhook: Failed to register Hatchet webhook for account " .
                "{$entity->getId()}: {$e->getMessage()}"
            );
        }
    }
}
