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
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\ORM\EntityManager;
use Espo\Tools\App\AppParam;
use Espo\Core\Utils\Log;

/**
 * AppParam that provides the Chatwoot SSO login URL for the current user.
 *
 * Resolution path (Phase 5):
 *   EspoCRM User → ChatwootUser (via assignedUserId) → ChatwootPlatform → SSO URL
 *
 * This is returned as part of the /api/v1/App/user response.
 */
class ChatwootSsoUrl implements AppParam
{
    public function __construct(
        private User $user,
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    /**
     * Get the Chatwoot SSO URL for the current user.
     *
     * @return string|null The SSO login URL or null if user has no Chatwoot account
     */
    public function get(): ?string
    {
        try {
            $userId = $this->user->getId();
            $this->log->debug("ChatwootSsoUrl: Getting SSO URL for user {$userId}");

            // Single-path lookup: ChatwootUser assigned to this EspoCRM user.
            // Deterministic ordering (oldest first) for multi-platform stability (Decision #13).
            $chatwootUser = $this->entityManager
                ->getRDBRepository('ChatwootUser')
                ->where(['assignedUserId' => $userId])
                ->order('createdAt', 'ASC')
                ->findOne();

            if (!$chatwootUser) {
                $this->log->debug("ChatwootSsoUrl: No ChatwootUser found for user {$userId}");
                return null;
            }

            $this->log->debug("ChatwootSsoUrl: Found ChatwootUser: " . $chatwootUser->getId());

            // Check if user has been synced with Chatwoot
            $chatwootUserId = $chatwootUser->get('chatwootUserId');
            if (!$chatwootUserId) {
                $this->log->debug("ChatwootSsoUrl: ChatwootUser has no chatwootUserId");
                return null;
            }

            // Get platform directly from ChatwootUser (it has a direct link to platform)
            $platformId = $chatwootUser->get('platformId');
            if (!$platformId) {
                $this->log->debug("ChatwootSsoUrl: ChatwootUser has no platformId");
                return null;
            }

            $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
            if (!$platform) {
                $this->log->debug("ChatwootSsoUrl: ChatwootPlatform not found: {$platformId}");
                return null;
            }

            // Get platform URL and access token
            $platformUrl = $platform->get('backendUrl');
            $accessToken = $platform->get('accessToken');

            if (!$platformUrl || !$accessToken) {
                $this->log->debug("ChatwootSsoUrl: Platform missing URL or access token");
                return null;
            }

            // Get SSO login URL from Chatwoot API
            $this->log->debug("ChatwootSsoUrl: Fetching login URL from Chatwoot API for user {$chatwootUserId}");
            $ssoUrl = $this->apiClient->getUserLoginUrl($platformUrl, $accessToken, $chatwootUserId);
            $this->log->debug("ChatwootSsoUrl: Successfully got SSO URL: {$ssoUrl}");

            return $ssoUrl;
        } catch (\Exception $e) {
            $this->log->error(
                'ChatwootSsoUrl: Failed to get SSO URL for user ' . $this->user->getId() . ': ' . $e->getMessage()
            );
            return null;
        }
    }
}
