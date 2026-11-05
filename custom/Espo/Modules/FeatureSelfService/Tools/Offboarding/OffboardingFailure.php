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

declare(strict_types=1);

namespace Espo\Modules\FeatureSelfService\Tools\Offboarding;

use RuntimeException;

/**
 * An offboarding error that retrying won't fix (missing Chatwoot platform).
 * The record stops at `failed` until an operator fixes the cause and starts
 * it again.
 */
class OffboardingFailure extends RuntimeException
{
}
