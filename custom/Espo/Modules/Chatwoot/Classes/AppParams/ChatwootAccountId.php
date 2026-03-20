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
 * Resolution path:
 *   EspoCRM User → ChatwootUser (via assignedUserId) → ChatwootAccountUserMembership → ChatwootAccount → chatwootAccountId (external int)
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
     * @return int|null The Chatwoot account ID or null if user has no Chatwoot account
     */
    public function get(): ?int
    {
        try {
            $userId = $this->user->getId();
            $this->log->debug("ChatwootAccountId: Getting account ID for user {$userId}");

            // Step 1: Find ChatwootUser assigned to this EspoCRM user.
            // Deterministic ordering (oldest first) for multi-platform stability (Decision #13).
            $chatwootUser = $this->entityManager
                ->getRDBRepository('ChatwootUser')
                ->where(['assignedUserId' => $userId])
                ->order('createdAt', 'ASC')
                ->findOne();

            if (!$chatwootUser) {
                $this->log->debug("ChatwootAccountId: No ChatwootUser found for user {$userId}");
                return null;
            }

            $this->log->debug("ChatwootAccountId: Found ChatwootUser: " . $chatwootUser->getId());

            // Step 2: Find membership for this ChatwootUser.
            // Deterministic ordering (oldest first) for multi-account stability (Decision #8, #13).
            $membership = $this->entityManager
                ->getRDBRepository('ChatwootAccountUserMembership')
                ->where(['chatwootUserId' => $chatwootUser->getId()])
                ->order('createdAt', 'ASC')
                ->findOne();

            if ($membership) {
                // Step 3: Resolve external account ID from membership's account link.
                $accountEntityId = $membership->get('chatwootAccountId');
                if ($accountEntityId) {
                    return $this->resolveExternalAccountId($accountEntityId);
                }
            }

            $this->log->debug("ChatwootAccountId: No membership found for ChatwootUser " . $chatwootUser->getId());
            return null;
        } catch (\Exception $e) {
            $this->log->error(
                'ChatwootAccountId: Failed to get account ID for user ' . $this->user->getId() . ': ' . $e->getMessage()
            );
            return null;
        }
    }

    /**
     * Resolve the Chatwoot API account ID from a ChatwootAccount entity ID.
     */
    private function resolveExternalAccountId(string $entityId): ?int
    {
        $account = $this->entityManager->getEntityById('ChatwootAccount', $entityId);
        if (!$account) {
            $this->log->debug("ChatwootAccountId: ChatwootAccount not found: {$entityId}");
            return null;
        }

        $chatwootAccountId = $account->get('chatwootAccountId');
        if (!$chatwootAccountId) {
            $this->log->debug("ChatwootAccountId: Account has no chatwootAccountId");
            return null;
        }

        $this->log->debug("ChatwootAccountId: Found chatwootAccountId: {$chatwootAccountId}");
        return $chatwootAccountId;
    }
}
