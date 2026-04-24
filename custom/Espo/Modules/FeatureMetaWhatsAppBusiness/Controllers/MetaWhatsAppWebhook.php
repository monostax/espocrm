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

namespace Espo\Modules\FeatureMetaWhatsAppBusiness\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\InjectableFactory;
use Espo\Entities\User;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\ChatwootWhatsAppWebhookConfigService;
use stdClass;

/**
 * Controller for one-shot Meta WhatsApp webhook configuration.
 *
 * Pushes the `clientSecret` from a meta-whatsapp OAuthProvider row to every
 * ChatwootPlatform's install-wide WhatsApp webhook config map so Chatwoot
 * can validate HMAC signatures on event POSTs.
 *
 * Admin-only — operates on shared OAuthProvider credentials.
 */
class MetaWhatsAppWebhook
{
    public function __construct(
        private InjectableFactory $injectableFactory,
        private User $user,
    ) {}

    /**
     * POST MetaWhatsAppWebhook/configure
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

        $service = $this->injectableFactory->create(ChatwootWhatsAppWebhookConfigService::class);
        $result = $service->configure((string) $oAuthProviderId);

        return (object) $result;
    }
}
