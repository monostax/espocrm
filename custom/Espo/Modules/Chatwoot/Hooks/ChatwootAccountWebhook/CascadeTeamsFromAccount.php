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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootAccountWebhook;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Auto-cascades teams from the parent ChatwootAccount to this entity.
 * Ensures proper multi-tenant ACL isolation.
 * Runs early (order=1) so teams are set before validation hooks.
 */
class CascadeTeamsFromAccount
{
    public static int $order = 1;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    /**
     * Cascade teams from parent ChatwootAccount before save.
     * 
     * @param Entity $entity
     * @param array<string, mixed> $options
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        // Skip for sync jobs - they handle team assignment directly
        if (!empty($options['silent'])) {
            return;
        }

        // ChatwootAccountWebhook uses 'account' as the link field name
        $accountId = $entity->get('accountId');
        if (!$accountId) {
            return;
        }

        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
        if (!$account) {
            return;
        }

        // Get teams from the parent account
        $teamsIds = $account->getLinkMultipleIdList('teams');
        if (!empty($teamsIds)) {
            $entity->set('teamsIds', $teamsIds);
        }
    }
}
