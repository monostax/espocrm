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

namespace Espo\Modules\FeatureMetaLeadAds\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\InjectableFactory;
use Espo\Entities\User;
use Espo\Modules\FeatureMetaLeadAds\Services\WebhookConfigService;
use stdClass;

/**
 * Controller for one-shot Meta Lead Ads webhook configuration.
 *
 * Exposes a POST endpoint that orchestrates the App-level webhook setup
 * for a specific meta-leadads OAuthProvider:
 *   1. Generate (if missing) and persist a webhookVerifyToken on the
 *      OAuthProvider row.
 *   2. Register the Meta App's Lead Ads webhook callback_url + verify_token
 *      at the Meta App level (POST /{appId}/subscriptions).
 *   3. Return the per-provider callback URL + verify_token + current
 *      subscriptions so the admin can confirm the registration.
 *
 * Admin-only — the underlying operation mutates OAuthProvider credentials
 * and registers an external webhook with Meta.
 */
class MetaLeadAdsWebhookConfig
{
    public function __construct(
        private InjectableFactory $injectableFactory,
        private User $user,
    ) {}

    /**
     * POST MetaLeadAdsWebhookConfig/configure
     *
     * Body: { oAuthProviderId: string }
     *
     * @throws BadRequest
     * @throws Forbidden
     * @throws Error
     */
    public function postActionConfigure(Request $request): stdClass
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden('Admin access required to configure Meta Lead Ads webhook.');
        }

        $data = $request->getParsedBody();
        $oAuthProviderId = $data->oAuthProviderId ?? null;

        if (!$oAuthProviderId) {
            throw new BadRequest('oAuthProviderId is required.');
        }

        $service = $this->injectableFactory->create(WebhookConfigService::class);
        $result = $service->configure((string) $oAuthProviderId);

        return (object) $result;
    }
}
