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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootUser;

use Espo\Core\Record\Hook\CreateHook;
use Espo\Core\Record\CreateParams;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Validates that the ChatwootAccount and ChatwootPlatform are configured
 * before creating a ChatwootUser.
 * Runs BEFORE the entity is saved to the database.
 */
class ValidateBeforeSync implements CreateHook
{
    public static int $order = 9; // Run before SyncWithChatwoot

    public function __construct(
        private EntityManager $entityManager
    ) {}

    /**
     * Validate account and platform are set and configured before create.
     * 
     * @throws BadRequest
     * @throws Error
     */
    public function process(Entity $entity, CreateParams $params): void
    {
        // Ensure account is set
        $accountId = $entity->get('accountId');
        if (!$accountId) {
            throw new BadRequest('Account is required for ChatwootUser.');
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

        // Validate platform has access token
        $accessToken = $platform->get('accessToken');
        if (!$accessToken) {
            throw new Error('ChatwootPlatform does not have an access token configured.');
        }

        // Validate password is set for new users
        if (!$entity->get('password')) {
            throw new BadRequest('Password is required for ChatwootUser.');
        }

        // Validate email is set
        if (!$entity->get('email')) {
            throw new BadRequest('Email is required for ChatwootUser.');
        }

        // Validate name is set
        if (!$entity->get('name')) {
            throw new BadRequest('Name is required for ChatwootUser.');
        }
    }
}



