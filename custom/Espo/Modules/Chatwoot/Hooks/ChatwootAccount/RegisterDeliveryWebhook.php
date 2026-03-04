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
 * Automatically registers a Chatwoot webhook for WhatsApp delivery status
 * tracking when a new ChatwootAccount is created.
 *
 * Creates a ChatwootAccountWebhook entity subscribed to `message_updated` and
 * `message_created`, which triggers the SyncWithChatwoot hook to register it
 * on the Chatwoot side.
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
        if (!$entity->isNew()) {
            return;
        }

        $chatwootAccountId = $entity->get('chatwootAccountId');

        if (!$chatwootAccountId) {
            return;
        }

        $crmBackendUrl = getenv('CRM_BACKEND_URL') ?: $this->config->get('siteUrl');

        if (!$crmBackendUrl) {
            $this->log->warning(
                "RegisterDeliveryWebhook: Cannot register webhook for account {$entity->getId()} — " .
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
}
