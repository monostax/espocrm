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

namespace Espo\Modules\Waha\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Controllers\Record;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\Waha\Services\CommunicationChannel as CommunicationChannelService;
use stdClass;

/**
 * Controller for CommunicationChannel entity.
 */
class CommunicationChannel extends Record
{
    /**
     * Override create to automatically activate the channel after creation.
     * This makes the QR code appear immediately on the detail view.
     *
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     */
    public function postActionCreate(Request $request, Response $response): stdClass
    {
        // First, create the entity using parent logic
        $result = parent::postActionCreate($request, $response);

        // Get the created entity ID
        $channelId = $result->id ?? null;

        if ($channelId) {
            try {
                // Activate the channel immediately
                $service = $this->getCommunicationChannelService();
                $entity = $service->activate($channelId);

                // Return the updated entity data (with activation status)
                return $entity->getValueMap();
            } catch (\Exception $e) {
                // If activation fails, still return the created entity
                // The error will be visible in the status/errorMessage fields
                // User can retry activation manually
                return $result;
            }
        }

        return $result;
    }

    /**
     * POST CommunicationChannel/:id/activate - Activate a channel
     *
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function postActionActivate(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest("ID is required.");
        }

        $service = $this->getCommunicationChannelService();
        $entity = $service->activate($id);

        return $entity->getValueMap();
    }

    /**
     * POST CommunicationChannel/:id/disconnect - Disconnect a channel
     *
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function postActionDisconnect(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest("ID is required.");
        }

        $service = $this->getCommunicationChannelService();
        $entity = $service->disconnect($id);

        return $entity->getValueMap();
    }

    /**
     * POST CommunicationChannel/:id/reconnect - Reconnect a channel
     *
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function postActionReconnect(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest("ID is required.");
        }

        $service = $this->getCommunicationChannelService();
        $entity = $service->reconnect($id);

        return $entity->getValueMap();
    }

    /**
     * GET CommunicationChannel/action/qrCode?id=xxx - Get QR code
     *
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     */
    public function getActionQrCode(Request $request, Response $response): stdClass
    {
        $id = $request->getQueryParam('id');

        if (!$id) {
            throw new BadRequest("ID is required.");
        }

        $service = $this->getCommunicationChannelService();

        return $service->getQrCode($id);
    }

    /**
     * POST CommunicationChannel/:id/checkStatus - Check and update status
     *
     * @throws BadRequest
     * @throws Error
     */
    public function postActionCheckStatus(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest("ID is required.");
        }

        $service = $this->getCommunicationChannelService();
        $entity = $service->checkStatus($id);

        return $entity->getValueMap();
    }

    private function getCommunicationChannelService(): CommunicationChannelService
    {
        return $this->injectableFactory->create(CommunicationChannelService::class);
    }
}

