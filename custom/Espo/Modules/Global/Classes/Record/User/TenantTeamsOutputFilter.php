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

namespace Espo\Modules\Global\Classes\Record\User;

use Espo\Core\Record\Output\Filter;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Tenant\TenantVisibility;
use Espo\ORM\Entity;

/**
 * Hides a user's teams from viewers who do not share all of that user's
 * workspaces, so a staff profile does not list other customers' teams.
 *
 * An output filter, not a read loader: Record\Service::update() runs read
 * loaders on the entity it is about to save, and a filtered `teamsIds` there
 * would be saved, dropping the hidden memberships.
 *
 * @implements Filter<User>
 */
class TenantTeamsOutputFilter implements Filter
{
    public function __construct(
        private User $user,
        private TenantVisibility $tenantVisibility,
    ) {}

    public function filter(Entity $entity): void
    {
        $this->tenantVisibility->hideTeamsFromOutput($this->user, $entity);
    }
}
