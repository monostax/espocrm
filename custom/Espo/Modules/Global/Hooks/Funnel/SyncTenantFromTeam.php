<?php

declare(strict_types=1);

/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Global\Hooks\Funnel;

use Espo\Modules\Global\Tools\Tenant\TenantFromTeamsSync;
use Espo\ORM\Entity;

/**
 * Auto-derive Funnel.tenantId from the teams in teamsIds.
 *
 * Tenant is the multi-tenancy boundary; teams are RBAC permissions. A Funnel
 * must always be owned by exactly one Tenant, but can be shared across
 * multiple Teams WITHIN that same tenant.
 *
 * Runs at order 5 so EnsureSingleDefault (order 10) sees a populated tenantId.
 */
class SyncTenantFromTeam
{
    public static int $order = 5;

    public function __construct(
        private TenantFromTeamsSync $sync,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        $this->sync->applyBeforeSave($entity, 'Funnel');
    }
}
