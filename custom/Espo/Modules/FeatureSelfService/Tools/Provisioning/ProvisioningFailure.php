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

namespace Espo\Modules\FeatureSelfService\Tools\Provisioning;

use RuntimeException;

/**
 * A provisioning error that retrying won't fix (bad owner, missing
 * configuration). The record stops at `failed` until an operator reruns it.
 */
class ProvisioningFailure extends RuntimeException
{
}
