<?php
/************************************************************************
 * This file is part of Monostax Revenue Center.
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

use Espo\Tools\App\AppParam;

/**
 * AppParam that provides the build version string.
 *
 * The build version is generated during the build process and stored in
 * application/Espo/Resources/defaults/build-version.php
 *
 * Format: YYY.MM.DD.HHMM.xxxxxx (e.g., 2026.03.18.1430.556937)
 *
 * Returned as part of the /api/v1/App/user response under `buildVersion`.
 */
class BuildVersion implements AppParam
{
    private const VERSION_FILE = 'application/Espo/Resources/defaults/build-version.php';

    /**
     * @return string The build version string, or empty string if not available (dev mode)
     */
    public function get(): string
    {
        $path = self::VERSION_FILE;

        if (!file_exists($path)) {
            return '';
        }

        $version = include $path;

        if (!is_string($version)) {
            return '';
        }

        return $version;
    }
}
