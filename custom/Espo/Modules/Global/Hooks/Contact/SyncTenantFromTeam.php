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

namespace Espo\Modules\Global\Hooks\Contact;

use Espo\Modules\Global\Tools\Tenant\TenantFromTeamsSync;
use Espo\ORM\Entity;

/**
 * Auto-derive Contact.tenantId from the first team in teamsIds.
 *
 * Tenant is the multi-tenancy boundary; teams are RBAC permissions.
 * A Contact must always be owned by exactly one Tenant, but can be
 * shared across multiple Teams WITHIN that same tenant.
 *
 * Runs at order 5 so ValidateUniqueCpf (order 9) sees a populated tenantId.
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
        $this->sync->applyBeforeSave($entity, 'Contact');
    }
}
