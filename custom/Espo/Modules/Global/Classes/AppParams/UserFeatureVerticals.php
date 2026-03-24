<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\Global\Classes\AppParams;

use Espo\Entities\User;
use Espo\Modules\Global\Classes\Utils\FeatureVerticalChecker;
use Espo\Tools\App\AppParam;

/**
 * AppParam that provides the current user's feature verticals.
 *
 * Returned as part of the /api/v1/App/user response under `userFeatureVerticals`.
 * The frontend uses this in dynamic logic conditions via `$user.featureVerticals`.
 */
class UserFeatureVerticals implements AppParam
{
    public function __construct(
        private User $user,
        private FeatureVerticalChecker $checker,
    ) {}

    /**
     * @return string[]
     */
    public function get(): array
    {
        return $this->checker->getVerticals($this->user);
    }
}
