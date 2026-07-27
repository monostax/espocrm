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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootAccount;

use Espo\Modules\Global\Services\TeamTenantAccess;
use Espo\ORM\Entity;

/**
 * Derives ChatwootAccount.tenant from the account's teams.
 *
 * Resolution lives in TeamTenantAccess, which matches a tenant's base user team
 * AND its other user teams. Matching only the base team used to leave a null
 * tenant for legitimate secondary-team assignments — and every Chatwoot consumer
 * treats a null tenant as a missing key rather than an optional filter
 * (ContactReconciler skips reconciliation entirely, SyncContactsFromChatwoot
 * skips the account), so the integration silently stopped linking contacts.
 *
 * Runs after CascadeTeamsFromAccount (order=1) and before ValidateBeforeSync
 * (order=9) so teams are already populated but platform validation has not yet
 * fired.
 *
 * Legacy hook signature (array $options) is retained deliberately — this class
 * predates the typed BeforeSave interface and GeneralInvoker dispatches both.
 *
 * Idempotent: never overwrites an explicitly-set tenantId.
 */
class AssignTenantFromTeam
{
    public static int $order = 5;

    public function __construct(
        private TeamTenantAccess $teamTenantAccess,
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

        // Respect explicit tenant assignments.
        if ($entity->get('tenantId')) {
            return;
        }

        $tenantId = $this->teamTenantAccess->deriveTenantId(
            $entity,
            'Chatwoot account',
            includePersistedTeams: true,
        );

        if ($tenantId !== null) {
            $entity->set('tenantId', $tenantId);
        }
    }
}
