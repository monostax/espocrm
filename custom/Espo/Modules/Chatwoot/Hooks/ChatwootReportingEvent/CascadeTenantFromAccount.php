<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Hooks\ChatwootReportingEvent;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Cascades tenant from the parent ChatwootAccount onto this row.
 *
 * Denormalizes ChatwootAccount.tenant onto ChatwootReportingEvent so
 * per-tenant reports can index/group directly on
 * chatwoot_reporting_event without joining through the account table.
 * Mirrors the existing ChatwootAiAgentRun/CascadeTenantFromAccount hook.
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
