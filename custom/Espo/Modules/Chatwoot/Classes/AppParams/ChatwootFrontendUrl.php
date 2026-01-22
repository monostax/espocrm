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

namespace Espo\Modules\Chatwoot\Classes\AppParams;

use Espo\Entities\User;
use Espo\ORM\EntityManager;
use Espo\Tools\App\AppParam;
use Espo\Core\Utils\Log;

/**
 * AppParam that provides the Chatwoot Frontend URL for the current user.
 * 
 * This is returned as part of the /api/v1/App/user response.
 * The frontend URL is used for iframe embedding and user-facing redirects.
 */
class ChatwootFrontendUrl implements AppParam
{
    public function __construct(
        private User $user,
        private EntityManager $entityManager,
        private Log $log
    ) {}

    /**
     * Get the Chatwoot Frontend URL for the current user.
     *
     * The relationship chain is:
     * EspoCRM User -> ChatwootUser (via assignedUserId) -> ChatwootPlatform (via platform) -> frontendUrl
     *
     * @return string|null The frontend URL or null if not configured
     */
    public function get(): ?string
    {
        try {
            $userId = $this->user->getId();
            $this->log->debug("ChatwootFrontendUrl: Getting frontend URL for user {$userId}");

            // Find ChatwootUser linked to current EspoCRM user via assignedUser
            $chatwootUser = $this->entityManager
                ->getRDBRepository('ChatwootUser')
                ->where(['assignedUserId' => $userId])
                ->findOne();

            if (!$chatwootUser) {
                $this->log->debug("ChatwootFrontendUrl: No ChatwootUser found for user {$userId}");
                return null;
            }

            // Get platform directly from ChatwootUser (it has a direct link to platform)
            $platformId = $chatwootUser->get('platformId');
            if (!$platformId) {
                $this->log->debug("ChatwootFrontendUrl: ChatwootUser has no platformId");
                return null;
            }

            $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
            if (!$platform) {
                $this->log->debug("ChatwootFrontendUrl: ChatwootPlatform not found: {$platformId}");
                return null;
            }

            // Get frontend URL from platform
            $frontendUrl = $platform->get('frontendUrl');

            if (!$frontendUrl) {
                $this->log->debug("ChatwootFrontendUrl: Platform missing frontendUrl");
                return null;
            }

            // Remove trailing slash for consistency
            $frontendUrl = rtrim($frontendUrl, '/');

            $this->log->debug("ChatwootFrontendUrl: Found frontendUrl: {$frontendUrl}");
            
            return $frontendUrl;
        } catch (\Exception $e) {
            $this->log->error(
                'ChatwootFrontendUrl: Failed to get frontend URL for user ' . $this->user->getId() . ': ' . $e->getMessage()
            );
            return null;
        }
    }
}
