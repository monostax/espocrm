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

namespace Espo\Modules\FeatureOAuthEnhanced\Controllers;

use Espo\Controllers\OAuthAccount as BaseOAuthAccount;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\FeatureOAuthEnhanced\Tools\OAuthAccount\AgentEgressResolver;
use stdClass;

/**
 * Override OAuthAccount controller to enable ACL-based access control.
 * Removes the hardcoded admin-only restriction from the original controller.
 *
 * @noinspection PhpUnused
 */
class OAuthAccount extends BaseOAuthAccount
{
    /**
     * Override checkAccess to use standard ACL instead of hardcoded admin check.
     * The parent Record controller will handle ACL verification.
     */
    protected function checkAccess(): bool
    {
        return true;
    }

    /**
     * POST OAuthAccount/action/resolveAgentEgress
     *
     * Resolve configured agentEgress paths to live token values (ACL + TokensProvider).
     * accessToken/refreshToken stay forbidden on GET; this is the only API path for materialization.
     *
     * Body: { "id": "<oauthAccountId>" }
     * Returns: { id, values: { "<configPath>": "<secret>", ... } }
     */
    public function postActionResolveAgentEgress(Request $request, Response $response): stdClass
    {
        $data = $request->getParsedBody();
        $id = $data->id ?? null;

        if (!$id || !is_string($id)) {
            throw new BadRequest('Missing required parameter: id');
        }

        /** @var AgentEgressResolver $resolver */
        $resolver = $this->injectableFactory->create(AgentEgressResolver::class);

        try {
            return $resolver->resolve($id);
        } catch (NotFound $e) {
            throw $e;
        } catch (Forbidden $e) {
            throw $e;
        }
    }
}
