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
 * This is returned as part of the /api/v1/App/user response.
 * 
 * Lookup chain: EspoCRM User → ChatwootAgent → ChatwootUser → Platform User ID
 * This supports the simplified architecture where User is linked to Agent directly.
 */
class chatSsoUrl implements AppParam
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
            $this->log->debug("chatSsoUrl: Getting SSO URL for user {$userId}");

            // First try: Find ChatwootAgent linked to current EspoCRM user via assignedUser
            $chatwootUser = $this->findChatwootUserViaAgent($userId);

            // Fallback: Find ChatwootUser linked directly to current EspoCRM user
            // (for backward compatibility with existing setups)
            if (!$chatwootUser) {
                $chatwootUser = $this->entityManager
                    ->getRDBRepository('ChatwootUser')
                    ->where(['assignedUserId' => $userId])
                    ->findOne();
            }

            if (!$chatwootUser) {
                $this->log->debug("chatSsoUrl: No ChatwootUser found for user {$userId}");
                return null;
            }

            $this->log->debug("chatSsoUrl: Found ChatwootUser: " . $chatwootUser->getId());

            // Check if user has been synced with Chatwoot
            $chatwootUserId = $chatwootUser->get('chatwootUserId');
            if (!$chatwootUserId) {
                $this->log->debug("chatSsoUrl: ChatwootUser has no chatwootUserId");
                return null;
            }

            // Get platform directly from ChatwootUser (it has a direct link to platform)
            $platformId = $chatwootUser->get('platformId');
            if (!$platformId) {
                $this->log->debug("chatSsoUrl: ChatwootUser has no platformId");
                return null;
            }

            $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
            if (!$platform) {
                $this->log->debug("chatSsoUrl: ChatwootPlatform not found: {$platformId}");
                return null;
            }

            // Get platform URL and access token
            $platformUrl = $platform->get('backendUrl');
            $accessToken = $platform->get('accessToken');

            if (!$platformUrl || !$accessToken) {
                $this->log->debug("chatSsoUrl: Platform missing URL or access token");
                return null;
            }

            // Get SSO login URL from Chatwoot API
            $this->log->debug("chatSsoUrl: Fetching login URL from Chatwoot API for user {$chatwootUserId}");
            $ssoUrl = $this->apiClient->getUserLoginUrl($platformUrl, $accessToken, $chatwootUserId);
            $this->log->debug("chatSsoUrl: Successfully got SSO URL: {$ssoUrl}");
            
            return $ssoUrl;
        } catch (\Exception $e) {
            $this->log->error(
                'chatSsoUrl: Failed to get SSO URL for user ' . $this->user->getId() . ': ' . $e->getMessage()
            );
            return null;
        }
    }

    /**
     * Find ChatwootUser via ChatwootAgent linkage.
     * 
     * This is the primary lookup path in the simplified architecture:
     * EspoCRM User → ChatwootAgent (via assignedUser) → ChatwootUser (via chatwootUser link)
     * 
     * @param string $userId The EspoCRM user ID
     * @return \Espo\ORM\Entity|null The ChatwootUser entity if found
     */
    private function findChatwootUserViaAgent(string $userId): ?\Espo\ORM\Entity
    {
        // Find any ChatwootAgent assigned to this user
        $agent = $this->entityManager
            ->getRDBRepository('ChatwootAgent')
            ->where(['assignedUserId' => $userId])
            ->findOne();

        if (!$agent) {
            $this->log->debug("chatSsoUrl: No ChatwootAgent found for user {$userId}");
            return null;
        }

        $this->log->debug("chatSsoUrl: Found ChatwootAgent: " . $agent->getId());

        // Get the linked ChatwootUser
        $chatwootUserId = $agent->get('chatwootUserId');
        if (!$chatwootUserId) {
            $this->log->debug("chatSsoUrl: ChatwootAgent has no linked ChatwootUser");
            return null;
        }

        $chatwootUser = $this->entityManager->getEntityById('ChatwootUser', $chatwootUserId);
        if (!$chatwootUser) {
            $this->log->debug("chatSsoUrl: ChatwootUser {$chatwootUserId} not found");
            return null;
        }

        return $chatwootUser;
    }
}

