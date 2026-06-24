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

namespace Espo\Modules\FeatureMetaInstagram\Classes\FieldProcessing\OAuthAccount;

/**
 * List-context connection status: LOCAL computation only (no outbound API
 * calls), so rendering a list of many OAuthAccounts stays fast and never
 * triggers per-row rate limits. Registered via `listLoaderClassNameList`.
 */
class ConnectionStatusListLoader extends ConnectionStatusLoader
{
    protected function isLive(): bool
    {
        return false;
    }
}
