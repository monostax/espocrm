<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

declare(strict_types=1);

namespace Espo\Modules\Global\Tools\TargetList\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\Global\Tools\TargetList\LaunchOutreachService;

/**
 * POST /TargetList/{id}/launchOutreach  (Resources/routes.json)
 * body: { "funnelId": "...", "stageId": "..." }
 *
 * Bulk-creates outbound Opportunities for the list's eligible contacts —
 * see LaunchOutreachService for guards, attribution and ACL. Returns
 * { audience, scheduled, skippedAlreadyAttributed, skippedOpenInFunnel,
 *   chunks }.
 *
 * @noinspection PhpUnused
 */
class PostLaunchOutreach implements Action
{
    public function __construct(
        private LaunchOutreachService $service,
    ) {}

    public function process(Request $request): Response
    {
        $id = $request->getRouteParam('id');

        $body = $request->getParsedBody();

        $funnelId = $body->funnelId ?? null;
        $stageId = $body->stageId ?? null;

        if (!is_string($id) || $id === '') {
            throw new BadRequest('No id.');
        }

        if (!is_string($funnelId) || $funnelId === '' || !is_string($stageId) || $stageId === '') {
            throw new BadRequest('funnelId and stageId are required.');
        }

        return ResponseComposer::json(
            $this->service->launch($id, $funnelId, $stageId)
        );
    }
}
