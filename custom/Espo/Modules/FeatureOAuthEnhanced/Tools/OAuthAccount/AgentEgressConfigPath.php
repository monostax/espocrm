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

namespace Espo\Modules\FeatureOAuthEnhanced\Tools\OAuthAccount;

use Espo\Modules\FeatureCredential\Tools\AgentEgress\AgentEgressShape;

/**
 * Allowed OAuthAccount.agentEgress configPath roots and nested data.* paths.
 */
class AgentEgressConfigPath
{
    /** Fixed roots that map into the live token / account bag. */
    public const ALLOWED_EXACT = [
        'accessToken',
        'access_token',
        'refreshToken',
        'refresh_token',
    ];

    public static function isAllowed(string $path): bool
    {
        if (!AgentEgressShape::isValidDotPath($path)) {
            return false;
        }

        if (in_array($path, self::ALLOWED_EXACT, true)) {
            return true;
        }

        if (!str_starts_with($path, 'data.')) {
            return false;
        }

        // data.<segment>(.<segment>)*
        $rest = substr($path, strlen('data.'));

        return $rest !== '' && AgentEgressShape::isValidDotPath($rest);
    }
}
