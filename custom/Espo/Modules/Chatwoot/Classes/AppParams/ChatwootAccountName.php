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
 * AppParam that provides the Chatwoot Account name for the current user.
 *
 * Resolution path:
 *   EspoCRM User → ChatwootUser (via assignedUserId) → ChatwootAccountUserMembership → ChatwootAccount → name
 *
 * This is returned as part of the /api/v1/App/user response.
 */
class ChatwootAccountName implements AppParam
{
    public function __construct(
        private User $user,
        private EntityManager $entityManager,
        private Log $log
    ) {}

    /**
     * Get the Chatwoot Account name for the current user.
     *
     * @return string|null The Chatwoot account name or null if user has no Chatwoot account
     */
    public function get(): ?string
    {
        try {
            $userId = $this->user->getId();
            $this->log->debug("ChatwootAccountName: Getting account name for user {$userId}");

            // Step 1: Find ChatwootUser assigned to this EspoCRM user.
            $chatwootUser = $this->entityManager
                ->getRDBRepository('ChatwootUser')
                ->where(['assignedUserId' => $userId])
                ->order('createdAt', 'ASC')
                ->findOne();

            if (!$chatwootUser) {
                $this->log->debug("ChatwootAccountName: No ChatwootUser found for user {$userId}");
                return null;
            }

            // Step 2: Find membership for this ChatwootUser.
            $membership = $this->entityManager
                ->getRDBRepository('ChatwootAccountUserMembership')
                ->where(['chatwootUserId' => $chatwootUser->getId()])
                ->order('createdAt', 'ASC')
                ->findOne();

            if ($membership) {
                $accountEntityId = $membership->get('chatwootAccountId');
                if ($accountEntityId) {
                    return $this->resolveAccountName($accountEntityId);
                }
            }

            $this->log->debug("ChatwootAccountName: No membership found for ChatwootUser " . $chatwootUser->getId());
            return null;
        } catch (\Exception $e) {
            $this->log->error(
                'ChatwootAccountName: Failed to get account name for user ' . $this->user->getId() . ': ' . $e->getMessage()
            );
            return null;
        }
    }

    /**
     * Resolve the Chatwoot Account name from a ChatwootAccount entity ID.
     */
    private function resolveAccountName(string $entityId): ?string
    {
        $account = $this->entityManager->getEntityById('ChatwootAccount', $entityId);
        if (!$account) {
            $this->log->debug("ChatwootAccountName: ChatwootAccount not found: {$entityId}");
            return null;
        }

        $name = $account->get('name');
        if (!$name) {
            $this->log->debug("ChatwootAccountName: Account has no name");
            return null;
        }

        $this->log->debug("ChatwootAccountName: Found account name: {$name}");
        return $name;
    }
}
