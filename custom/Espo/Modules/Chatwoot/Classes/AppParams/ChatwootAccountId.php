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
 * AppParam that provides the Chatwoot Account ID for the current user.
 * 
 * This is returned as part of the /api/v1/App/user response.
 */
class ChatwootAccountId implements AppParam
{
    public function __construct(
        private User $user,
        private EntityManager $entityManager,
        private Log $log
    ) {}

    /**
     * Get the Chatwoot Account ID for the current user.
     *
     * The relationship chain is:
     * EspoCRM User -> ChatwootUser (via assignedUserId) -> ChatwootAccount (via chatwootAccountId)
     *
     * @return int|null The Chatwoot account ID or null if user has no Chatwoot account
     */
    public function get(): ?int
    {
        try {
            $userId = $this->user->getId();
            $this->log->debug("ChatwootAccountId: Getting account ID for user {$userId}");

            // Find ChatwootUser linked to current EspoCRM user via assignedUser
            $chatwootUser = $this->entityManager
                ->getRDBRepository('ChatwootUser')
                ->where(['assignedUserId' => $userId])
                ->findOne();

            if (!$chatwootUser) {
                $this->log->debug("ChatwootAccountId: No ChatwootUser found for user {$userId}");
                return null;
            }

            $chatwootUserId = $chatwootUser->getId();
            $this->log->debug("ChatwootAccountId: Found ChatwootUser: {$chatwootUserId}");

            // Get the ChatwootAccount directly from ChatwootUser
            $accountId = $chatwootUser->get('chatwootAccountId');
            if (!$accountId) {
                $this->log->debug("ChatwootAccountId: ChatwootUser has no chatwootAccountId");
                return null;
            }

            $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
            if (!$account) {
                $this->log->debug("ChatwootAccountId: ChatwootAccount not found: {$accountId}");
                return null;
            }

            // Get the Chatwoot account ID (the ID in Chatwoot's system)
            $chatwootAccountId = $account->get('chatwootAccountId');
            
            if (!$chatwootAccountId) {
                $this->log->debug("ChatwootAccountId: Account has no chatwootAccountId");
                return null;
            }

            $this->log->debug("ChatwootAccountId: Found chatwootAccountId: {$chatwootAccountId}");
            
            return $chatwootAccountId;
        } catch (\Exception $e) {
            $this->log->error(
                'ChatwootAccountId: Failed to get account ID for user ' . $this->user->getId() . ': ' . $e->getMessage()
            );
            return null;
        }
    }
}



