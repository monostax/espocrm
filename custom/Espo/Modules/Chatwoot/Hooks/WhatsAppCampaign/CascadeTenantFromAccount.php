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

namespace Espo\Modules\Chatwoot\Hooks\WhatsAppCampaign;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Cascades tenant from the parent ChatwootAccount onto this campaign.
 *
 * Denormalizes ChatwootAccount.tenant onto WhatsAppCampaign so reports
 * and per-tenant filters can index/group directly on whatsapp_campaign
 * without joining through the account table. Mirrors the existing
 * CascadeTenantFromAccount pattern on ChatwootAiAgentRun (order=1).
 *
 * Runs only on insert/when tenantId is empty, so an explicit tenant
 * assignment (e.g. by an admin tool) is never overwritten.
 */
class CascadeTenantFromAccount
{
    public static int $order = 1;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    /**
     * @param Entity $entity
     * @param array<string, mixed> $options
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        if (!empty($options['silent'])) {
            return;
        }

        if ($entity->get('tenantId')) {
            return;
        }

        $accountId = $entity->get('chatwootAccountId');
        if (!$accountId) {
            return;
        }

        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
        if (!$account) {
            return;
        }

        $tenantId = $account->get('tenantId');
        if ($tenantId) {
            $entity->set('tenantId', $tenantId);
        }
    }
}
