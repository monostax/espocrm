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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootAccount;

use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Validates that the ChatwootPlatform is configured before creating a ChatwootAccount.
 * Runs BEFORE the entity is saved to the database.
 */
class ValidateBeforeSync
{
    public static int $order = 9; // Run before SyncWithChatwoot

    public function __construct(
        private EntityManager $entityManager
    ) {}

    /**
     * Validate platform is set and configured before save.
     * Only runs on entity creation.
     * 
     * @param Entity $entity
     * @param array<string, mixed> $options
     * @throws BadRequest
     * @throws Error
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        // Only run for new ChatwootAccount records (not updates)
        if (!$entity->isNew()) {
            return;
        }

        // Ensure platform is set
        $platformId = $entity->get('platformId');
        if (!$platformId) {
            throw new BadRequest('Platform is required for ChatwootAccount.');
        }

        // Validate that the platform exists and is properly configured
        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
        
        if (!$platform) {
            throw new Error('Selected ChatwootPlatform does not exist.');
        }

        // Validate platform has URL
        $url = $platform->get('backendUrl');
        if (!$url) {
            throw new Error('ChatwootPlatform does not have a URL configured. Please configure the platform URL first.');
        }

        // Validate platform has access token
        $accessToken = $platform->get('accessToken');
        if (!$accessToken) {
            throw new Error('ChatwootPlatform does not have an access token configured. Please configure the access token first.');
        }
    }
}

