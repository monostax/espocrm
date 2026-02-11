<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\FeaturePwaPush\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Modules\FeaturePwaPush\Services\VapidKeyService;

/**
 * Rebuild action to generate VAPID keys if they don't exist.
 */
class SeedVapidKeys implements RebuildAction
{
    public function __construct(
        private VapidKeyService $vapidKeyService
    ) {}

    public function process(): void
    {
        $this->vapidKeyService->ensureKeys();
    }
}
