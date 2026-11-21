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

namespace Espo\Modules\Global\Classes\FieldProcessing\User;

use Espo\Core\FieldProcessing\Loader;
use Espo\Core\FieldProcessing\Loader\Params;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Tenant\TenantVisibility;
use Espo\ORM\Entity;

/**
 * Same rule as TenantTeamsOutputFilter for paths that skip output filters:
 * export and reports run list loaders only. List loaders never run on an
 * entity that is saved afterwards.
 *
 * @implements Loader<User>
 */
class TenantTeamsListLoader implements Loader
{
    public function __construct(
        private User $user,
        private TenantVisibility $tenantVisibility,
    ) {}

    public function process(Entity $entity, Params $params): void
    {
        $this->tenantVisibility->hideTeamsFromOutput($this->user, $entity);
    }
}
