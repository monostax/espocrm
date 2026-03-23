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
 * AppParam that provides the EspoCRM entity ID of the user's ChatwootAccount.
 *
 * Resolution path:
 *   EspoCRM User → ChatwootUser (via assignedUserId) → ChatwootAccountUserMembership → chatwootAccountId (EspoCRM entity FK)
 *
 * This is needed for filtering ChatwootContactInboxes by the `chatwootAccount` link FK.
 * Returned as part of the /api/v1/App/user response.
 */
class ChatwootAccountEntityId implements AppParam
{
    public function __construct(
        private User $user,
        private EntityManager $entityManager,
        private Log $log
    ) {}

    /**
     * Get the EspoCRM entity ID of the ChatwootAccount for the current user.
     *
     * @return string|null The EspoCRM entity ID or null if user has no Chatwoot account
     */
    public function get(): ?string
    {
        try {
            $userId = $this->user->getId();
            $this->log->debug("ChatwootAccountEntityId: Getting account entity ID for user {$userId}");

            // Step 1: Find ChatwootUser assigned to this EspoCRM user.
            // Deterministic ordering (oldest first) for multi-platform stability.
            $chatwootUser = $this->entityManager
                ->getRDBRepository('ChatwootUser')
                ->where(['assignedUserId' => $userId])
                ->order('createdAt', 'ASC')
                ->findOne();

            if (!$chatwootUser) {
                $this->log->debug("ChatwootAccountEntityId: No ChatwootUser found for user {$userId}");
                return null;
            }

            $this->log->debug("ChatwootAccountEntityId: Found ChatwootUser: " . $chatwootUser->getId());

            // Step 2: Find membership for this ChatwootUser.
            // Deterministic ordering (oldest first) for multi-account stability.
            $membership = $this->entityManager
                ->getRDBRepository('ChatwootAccountUserMembership')
                ->where(['chatwootUserId' => $chatwootUser->getId()])
                ->order('createdAt', 'ASC')
                ->findOne();

            if ($membership) {
                // Step 3: Return the EspoCRM entity FK (not the external int).
                $accountEntityId = $membership->get('chatwootAccountId');
                if ($accountEntityId) {
                    $this->log->debug("ChatwootAccountEntityId: Found account entity ID: {$accountEntityId}");
                    return $accountEntityId;
                }
            }

            $this->log->debug("ChatwootAccountEntityId: No membership found for ChatwootUser " . $chatwootUser->getId());
            return null;
        } catch (\Exception $e) {
            $this->log->error(
                'ChatwootAccountEntityId: Failed to get account entity ID for user ' . $this->user->getId() . ': ' . $e->getMessage()
            );
            return null;
        }
    }
}
