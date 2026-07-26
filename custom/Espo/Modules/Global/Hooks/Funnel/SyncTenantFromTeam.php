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
 * Auto-derive Funnel.tenantId primarily from team (ownership) then teams ACL.
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
        $preferred = [];
        $teamId = $entity->get('teamId');

        if (is_string($teamId) && trim($teamId) !== '') {
            $preferred[] = $teamId;
        }

        $this->sync->applyBeforeSave($entity, 'Funnel', $preferred);
    }
}
