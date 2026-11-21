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

namespace Espo\Modules\Global\Tools\Tenant;

use Espo\Core\Container\Loader;
use Espo\Core\InjectableFactory;

/**
 * Container loader for `aclManager`. FeatureSelfService replaces it with
 * SeatAclLoader, whose SeatAclManager extends TenantAclManager.
 */
class TenantAclLoader implements Loader
{
    public function __construct(private InjectableFactory $injectableFactory) {}

    public function load(): TenantAclManager
    {
        return $this->injectableFactory->create(TenantAclManager::class);
    }
}
