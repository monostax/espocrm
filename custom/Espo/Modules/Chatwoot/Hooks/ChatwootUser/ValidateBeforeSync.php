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
 * Validates that the ChatwootPlatform is configured before creating a ChatwootUser.
 * ChatwootUser is now platform-level (not account-level).
 * Runs BEFORE the entity is saved to the database.
 */
class ValidateBeforeSync implements CreateHook
{
    public static int $order = 9; // Run before SyncWithChatwoot

    public function __construct(
        private EntityManager $entityManager
    ) {}

    /**
     * Validate platform is set and configured before create.
     * 
     * @throws BadRequest
     * @throws Error
     */
    public function process(Entity $entity, CreateParams $params): void
    {
        // Ensure platform is set
        $platformId = $entity->get('platformId');
        if (!$platformId) {
            throw new BadRequest('Platform is required for ChatwootUser.');
        }

        // Validate that the platform exists
        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
        
        if (!$platform) {
            throw new Error('Selected ChatwootPlatform does not exist.');
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

        // Check for duplicate email in the same platform
        $existingUser = $this->entityManager
            ->getRDBRepository('ChatwootUser')
            ->where([
                'email' => $entity->get('email'),
                'platformId' => $platformId,
            ])
            ->findOne();

        if ($existingUser) {
            throw new BadRequest('A ChatwootUser with this email already exists on this platform.');
        }
    }
}
