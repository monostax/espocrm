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

namespace Espo\Modules\Global\Hooks\Lead;

use Espo\Modules\Global\Tools\Tenant\TenantFromTeamsSync;
use Espo\ORM\Entity;

/**
 * Auto-derive Lead.tenantId from teams (Contact pattern).
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
        $this->sync->applyBeforeSave($entity, 'Lead');
    }
}
