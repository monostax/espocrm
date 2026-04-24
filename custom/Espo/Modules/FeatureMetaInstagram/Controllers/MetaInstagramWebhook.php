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

namespace Espo\Modules\FeatureMetaInstagram\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\InjectableFactory;
use Espo\Entities\User;
use Espo\Modules\FeatureMetaInstagram\Services\ChatwootWebhookConfigService;
use stdClass;

/**
 * Controller for one-shot Meta Instagram webhook configuration.
 *
 * Exposes a POST endpoint that orchestrates the end-to-end webhook setup:
 *   1. Generate (if missing) and persist a webhookVerifyToken on the
 *      meta-instagram OAuthProvider row.
 *   2. Register the Meta App's Instagram product webhook callback_url +
 *      verify_token at the Meta App level.
 *   3. Sync the same verify_token to every ChatwootPlatform.
 *
 * Admin-only — the underlying operation mutates shared OAuthProvider
 * credentials and cross-system config.
 */
class MetaInstagramWebhook
{
    public function __construct(
        private InjectableFactory $injectableFactory,
        private User $user,
    ) {}

    /**
     * POST MetaInstagramWebhook/configure
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
            throw new Forbidden('Admin access required to configure Meta webhook.');
        }

        $data = $request->getParsedBody();
        $oAuthProviderId = $data->oAuthProviderId ?? null;

        if (!$oAuthProviderId) {
            throw new BadRequest('oAuthProviderId is required.');
        }

        $service = $this->injectableFactory->create(ChatwootWebhookConfigService::class);
        $result = $service->configure((string) $oAuthProviderId);

        return (object) $result;
    }
}
