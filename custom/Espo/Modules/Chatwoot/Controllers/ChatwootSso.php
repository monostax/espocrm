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

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\Error;
use Espo\Core\InjectableFactory;
use Espo\Modules\Chatwoot\Classes\AppParams\ChatwootSsoUrl;
use stdClass;

/**
 * Controller for on-demand Chatwoot SSO URL generation.
 *
 * Provides a lightweight endpoint to fetch a fresh SSO URL without
 * reloading the entire /api/v1/App/user response. This is used by
 * the ChatwootSsoManager on the client when an SSO token has expired
 * or was consumed and a retry is needed.
 */
class ChatwootSso
{
    public function __construct(
        private InjectableFactory $injectableFactory
    ) {}

    /**
     * GET ChatwootSso/freshUrl
     *
     * Returns a fresh SSO URL for the current user.
     *
     * @return stdClass { ssoUrl: string|null }
     * @throws Error
     */
    public function getActionFreshUrl(Request $request): stdClass
    {
        $ssoUrlProvider = $this->injectableFactory->create(ChatwootSsoUrl::class);
        $ssoUrl = $ssoUrlProvider->get();

        return (object) [
            'ssoUrl' => $ssoUrl,
        ];
    }
}
