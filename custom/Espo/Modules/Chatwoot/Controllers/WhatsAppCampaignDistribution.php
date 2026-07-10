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
use Espo\Core\Api\Response;
use Espo\Core\Controllers\Record;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\Chatwoot\Services\WhatsAppCampaignDistributionService;
use stdClass;

/**
 * Controller for WhatsApp Campaign Distribution entity.
 *
 * Extends Record for standard CRUD and adds activate and stop lifecycle actions.
 */
class WhatsAppCampaignDistribution extends Record
{
    /**
     * Activate a campaign distribution.
     *
     * POST /api/v1/WhatsAppCampaignDistribution/:id/activate
     */
    public function postActionActivate(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest('Missing distribution ID.');
        }

        $distribution = $this->getDistributionService()->activate($id);

        return (object) $distribution->getValueMap();
    }

    /**
     * Stop an active campaign distribution.
     *
     * POST /api/v1/WhatsAppCampaignDistribution/:id/stop
     */
    public function postActionStop(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest('Missing distribution ID.');
        }

        $distribution = $this->getDistributionService()->stop($id);

        return (object) $distribution->getValueMap();
    }

    private function getDistributionService(): WhatsAppCampaignDistributionService
    {
        return $this->injectableFactory->create(WhatsAppCampaignDistributionService::class);
    }
}
