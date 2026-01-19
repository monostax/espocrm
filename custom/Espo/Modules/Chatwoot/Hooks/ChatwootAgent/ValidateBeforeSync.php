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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootAgent;

use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Validates that the ChatwootAccount and ChatwootPlatform are configured
 * before creating or updating a ChatwootAgent.
 * Runs BEFORE the entity is saved to the database.
 */
class ValidateBeforeSync
{
    public static int $order = 9; // Run before SyncWithChatwoot

    public function __construct(
        private EntityManager $entityManager
    ) {}

    /**
     * Validate account and platform are set and configured before save.
     * 
     * @param Entity $entity
     * @param array<string, mixed> $options
     * @throws BadRequest
     * @throws Error
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        // Skip validation if this is a silent save (from sync job)
        if (!empty($options['silent'])) {
            return;
        }

        // Ensure account is set
        $accountId = $entity->get('chatwootAccountId');
        if (!$accountId) {
            throw new BadRequest('Chatwoot Account is required for ChatwootAgent.');
        }

        // Validate that the account exists
        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
        
        if (!$account) {
            throw new Error('Selected ChatwootAccount does not exist.');
        }

        // Validate account has a chatwootAccountId (has been synced to Chatwoot)
        $chatwootAccountId = $account->get('chatwootAccountId');
        if (!$chatwootAccountId) {
            throw new Error('ChatwootAccount has not been synchronized with Chatwoot yet. Please wait for the account to be created on Chatwoot first.');
        }

        // Validate account has an API key
        $apiKey = $account->get('apiKey');
        if (!$apiKey) {
            throw new Error('ChatwootAccount does not have an API key configured.');
        }

        // Get platform from account
        $platformId = $account->get('platformId');
        if (!$platformId) {
            throw new Error('ChatwootAccount does not have a platform configured.');
        }

        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
        
        if (!$platform) {
            throw new Error('ChatwootPlatform not found.');
        }

        // Validate platform has URL
        $url = $platform->get('url');
        if (!$url) {
            throw new Error('ChatwootPlatform does not have a URL configured.');
        }

        // Validate email is set for new agents (required by Chatwoot API)
        if ($entity->isNew() && !$entity->get('email')) {
            throw new BadRequest('Email is required for ChatwootAgent.');
        }

        // Validate name is set for new agents
        if ($entity->isNew() && !$entity->get('name')) {
            throw new BadRequest('Name is required for ChatwootAgent.');
        }

        // Validate role is set
        if (!$entity->get('role')) {
            $entity->set('role', 'agent');
        }

        // Validate password requirements if provided
        $password = $entity->get('password');
        if ($password) {
            $this->validatePassword($password);
        }
    }

    /**
     * Validate password meets Chatwoot requirements.
     * 
     * @throws BadRequest
     */
    private function validatePassword(string $password): void
    {
        // Minimum 6 characters
        if (strlen($password) < 6) {
            throw new BadRequest('Password must be at least 6 characters long.');
        }

        // Must contain at least 1 special character
        $specialChars = '!@#$%^&*()_+\-=\[\]{}|"\\/.,`<>:;?~\'';
        if (!preg_match('/[' . preg_quote($specialChars, '/') . ']/', $password)) {
            throw new BadRequest('Password must contain at least 1 special character (!@#$%^&*()_+-=[]{}|"/\.,`<>:;?~\').');
        }
    }
}
