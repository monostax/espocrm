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
 * Detail-context connection status: performs a LIVE validity probe against the
 * provider API (graph.instagram.com for meta-instagram) so a single opened
 * record reflects real-time token validity (connected / expired / revoked).
 * Registered via `readLoaderClassNameList`.
 */
class ConnectionStatusDetailLoader extends ConnectionStatusLoader
{
    protected function isLive(): bool
    {
        return true;
    }
}
