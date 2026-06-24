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

namespace Espo\Modules\FeatureOAuthEnhanced\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\InjectableFactory;
use Espo\Modules\FeatureOAuthEnhanced\Services\MetaSystemUserTokenService;
use stdClass;

/**
 * Controller for storing a manually-supplied Meta System User access token
 * on an OAuthAccount.
 *
 * The standard OAuth `POST /OAuth/{id}/connection` flow performs an
 * authorization-code exchange, which does not apply to Meta System Users
 * (the admin pastes a pre-generated token instead). This controller bridges
 * that gap while reusing the same encrypted storage so all downstream Graph
 * consumers ({@see \Espo\Tools\OAuth\TokensProvider}) keep working unchanged.
 *
 * @noinspection PhpUnused
 */
class MetaSystemUserToken
{
    public function __construct(
        private InjectableFactory $injectableFactory,
    ) {}

    /**
     * POST MetaSystemUserToken/set
     *
     * Body:
     *   {
     *     oAuthAccountId: string,   // The OAuthAccount to attach the token to
     *     token: string,            // The Meta System User access token (plaintext)
     *     businessId?: string       // Optional Business Manager id
     *   }
     *
     * @throws BadRequest
     */
    public function postActionSet(Request $request): stdClass
    {
        $body = $request->getParsedBody();

        $oAuthAccountId = $body->oAuthAccountId ?? null;
        $token = $body->token ?? null;
        $businessId = $body->businessId ?? null;

        if (!is_string($oAuthAccountId) || $oAuthAccountId === '') {
            throw new BadRequest('oAuthAccountId is required.');
        }

        if (!is_string($token) || trim($token) === '') {
            throw new BadRequest('token is required.');
        }

        $service = $this->injectableFactory->create(MetaSystemUserTokenService::class);

        $result = $service->set(
            $oAuthAccountId,
            $token,
            is_string($businessId) ? $businessId : null,
        );

        return (object) $result;
    }

    /**
     * POST MetaSystemUserToken/generate
     *
     * Programmatically mints a System User token via Meta's System Users API
     * (install app + POST /{su-id}/access_tokens) and stores it.
     *
     * Body:
     *   {
     *     oAuthAccountId: string,    // The OAuthAccount (must have a metaSystemUserId)
     *     scopes?: string[],         // Scopes to request; defaults to WhatsApp + business_management
     *     callerToken?: string,      // Admin/admin-system-user token; null = reuse stored token
     *     expiring?: boolean         // 60-day expiring token (default false = non-expiring)
     *   }
     *
     * @throws BadRequest
     */
    public function postActionGenerate(Request $request): stdClass
    {
        $body = $request->getParsedBody();

        $oAuthAccountId = $body->oAuthAccountId ?? null;

        if (!is_string($oAuthAccountId) || $oAuthAccountId === '') {
            throw new BadRequest('oAuthAccountId is required.');
        }

        $scopes = $body->scopes ?? null;

        if (is_array($scopes)) {
            $scopes = array_values(array_filter(
                array_map(fn($s) => is_string($s) ? $s : '', $scopes),
                fn($s) => $s !== '',
            ));
        } else {
            $scopes = [];
        }

        if (!$scopes) {
            // Sensible default for this app's Meta integrations.
            $scopes = [
                'whatsapp_business_management',
                'whatsapp_business_messaging',
                'business_management',
            ];
        }

        $callerToken = $body->callerToken ?? null;
        $expiring = (bool) ($body->expiring ?? false);

        $service = $this->injectableFactory->create(MetaSystemUserTokenService::class);

        $result = $service->generate(
            $oAuthAccountId,
            $scopes,
            is_string($callerToken) && trim($callerToken) !== '' ? $callerToken : null,
            $expiring,
        );

        return (object) $result;
    }
}
