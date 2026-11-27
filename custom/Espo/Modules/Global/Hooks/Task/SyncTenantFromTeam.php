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

namespace Espo\Modules\Global\Hooks\Task;

use Espo\Modules\Global\Tools\Tenant\TenantFromTeamsSync;
use Espo\ORM\Entity;

/**
 * Auto-derive Task.tenantId from teams, as Opportunity does (2026-11-27). Teams stay the ACL;
 * the tenant is the boundary that jobs and joins bind to.
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
        $this->sync->applyBeforeSave($entity, 'Task');
    }
}
