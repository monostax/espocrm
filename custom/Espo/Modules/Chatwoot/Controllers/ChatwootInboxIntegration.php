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
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\Chatwoot\Services\ChatwootInboxIntegration as ChatwootInboxIntegrationService;
use stdClass;

/**
 * Controller for ChatwootInboxIntegration entity.
 */
class ChatwootInboxIntegration extends Record
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
        $result = parent::postActionCreate($request, $response);
        $channelId = $result->id ?? null;

        if ($channelId) {
            $service = $this->getChatwootInboxIntegrationService();

            try {
                $entity = $service->activate($channelId);
                $chatwootInboxRecordId = $service->findLinkedChatwootInboxRecordId($channelId);

                if (!$chatwootInboxRecordId) {
                    $service->removeIntegration($channelId);

                    throw new Error("Activation failed: Chatwoot inbox was not linked.");
                }

                $valueMap = $entity->getValueMap();
                $valueMap->chatwootInboxRecordId = $chatwootInboxRecordId;

                return $valueMap;
            } catch (\Exception $e) {
                $service->removeIntegration($channelId);

                if ($e instanceof Error) {
                    throw $e;
                }

                throw new Error($e->getMessage());
            }
        }

        return $result;
    }

    /**
     * POST ChatwootInboxIntegration/:id/activate - Activate a channel
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

        $service = $this->getChatwootInboxIntegrationService();
        $entity = $service->activate($id);

        return $entity->getValueMap();
    }

    /**
     * POST ChatwootInboxIntegration/:id/disconnect - Disconnect a channel
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

        $service = $this->getChatwootInboxIntegrationService();
        $entity = $service->disconnect($id);

        return $entity->getValueMap();
    }

    /**
     * POST ChatwootInboxIntegration/:id/reconnect - Reconnect a channel
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

        $service = $this->getChatwootInboxIntegrationService();
        $entity = $service->reconnect($id);

        return $entity->getValueMap();
    }

    /**
     * GET ChatwootInboxIntegration/action/qrCode?id=xxx - Get QR code
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

        $service = $this->getChatwootInboxIntegrationService();

        return $service->getQrCode($id);
    }

    /**
     * POST ChatwootInboxIntegration/:id/checkStatus - Check and update status
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

        $service = $this->getChatwootInboxIntegrationService();
        $entity = $service->checkStatus($id);

        return $entity->getValueMap();
    }

    private function getChatwootInboxIntegrationService(): ChatwootInboxIntegrationService
    {
        return $this->injectableFactory->create(ChatwootInboxIntegrationService::class);
    }
}
