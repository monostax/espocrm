<?php

declare(strict_types=1);

/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureMcp\Tools\Setup\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Modules\FeatureMcp\Tools\Setup\ConnectionSetupService;

/**
 * GET /Mcp/action/setup
 * → {"available": true, "user": {...}, "endpoints": [{name, slug, url, description}],
 *    "clients": [{name, clientId, redirectUris}]}
 *
 * Read by the Chatwoot "Conectar IA" settings page. The user's connected apps come from the core
 * `GET /User/:id/connectedApps` route.
 *
 * @noinspection PhpUnused
 */
class GetSetup implements Action
{
    public function __construct(
        private ConnectionSetupService $service,
    ) {}

    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->get());
    }
}
