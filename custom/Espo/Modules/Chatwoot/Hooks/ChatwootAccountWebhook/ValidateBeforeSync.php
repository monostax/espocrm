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

use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Validation hook for ChatwootAccountWebhook.
 * Ensures all required fields are present before syncing with Chatwoot.
 */
class ValidateBeforeSync
{
    public static int $order = 5; // Run before SyncWithChatwoot

    public function __construct(
        private EntityManager $entityManager
    ) {}

    /**
     * Validate required fields before saving.
     *
     * @param Entity $entity
     * @param array<string, mixed> $options
     * @throws BadRequest
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        // Only validate on new entities or when key fields change
        if (!$entity->isNew() && !$entity->isAttributeChanged('accountId') && 
            !$entity->isAttributeChanged('url') && !$entity->isAttributeChanged('subscriptions')) {
            return;
        }

        // Validate account is linked
        $accountId = $entity->get('accountId');
        if (!$accountId) {
            throw new BadRequest('ChatwootAccount is required for ChatwootAccountWebhook.');
        }

        // Verify the account exists and has necessary data
        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
        if (!$account) {
            throw new BadRequest('ChatwootAccount not found.');
        }

        if (!$account->get('chatwootAccountId')) {
            throw new BadRequest(
                'ChatwootAccount must be synchronized with Chatwoot before creating webhooks. ' .
                'Please ensure the account has a valid Chatwoot Account ID.'
            );
        }

        if (!$account->get('apiKey')) {
            throw new BadRequest(
                'ChatwootAccount does not have an API key configured. ' .
                'API key is required to manage webhooks.'
            );
        }

        // Validate URL
        $url = $entity->get('url');
        if (!$url) {
            throw new BadRequest('URL is required for webhook.');
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new BadRequest('Invalid URL format.');
        }

        // Validate subscriptions
        $subscriptions = $entity->get('subscriptions');
        if (!$subscriptions || !is_array($subscriptions) || empty($subscriptions)) {
            throw new BadRequest('At least one subscription event is required.');
        }

        $validSubscriptions = [
            'conversation_created',
            'conversation_status_changed',
            'conversation_updated',
            'contact_created',
            'contact_updated',
            'message_created',
            'message_updated',
            'webwidget_triggered'
        ];

        foreach ($subscriptions as $subscription) {
            if (!in_array($subscription, $validSubscriptions)) {
                throw new BadRequest('Invalid subscription event: ' . $subscription);
            }
        }

        // Validate name
        $name = $entity->get('name');
        if (!$name) {
            throw new BadRequest('Name is required for webhook.');
        }
    }
}