<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureSelfService\Tools\Policy;

use RuntimeException;

/**
 * Self-service is switched off for this deployment or paused at runtime.
 * HTTP surfaces map it to the stable code {@see self::CODE}.
 */
class SelfServiceUnavailable extends RuntimeException
{
    public const CODE = 'SELF_SERVICE_DISABLED';

    public function __construct(
        public readonly string $state,
    ) {
        parent::__construct("Self-service is {$state}.");
    }
}
