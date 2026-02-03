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
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\InjectableFactory;
use Espo\Modules\Chatwoot\Services\WahaSessionApp as WahaSessionAppService;
use stdClass;

/**
 * Controller for WahaSessionApp virtual entity.
 */
class WahaSessionApp
{
    private InjectableFactory $injectableFactory;

    public function __construct(InjectableFactory $injectableFactory)
    {
        $this->injectableFactory = $injectableFactory;
    }

    /**
     * GET WahaSessionApp - List all apps.
     * Route: GET api/v1/WahaSessionApp?platformId=xxx&sessionName=yyy (optional filters)
     *
     * @throws Error
     * @throws Forbidden
     */
    public function getActionIndex(Request $request, Response $response): stdClass
    {
        $platformId = $request->getQueryParam('platformId');
        $sessionName = $request->getQueryParam('sessionName');

        $service = $this->getWahaSessionAppService();

        // If both platformId and sessionName are provided, filter by session
        if ($platformId && $sessionName) {
            $result = $service->findBySession($platformId, $sessionName);
        } else {
            $result = $service->find($platformId);
        }

        return (object) [
            'total' => $result->getTotal(),
            'list' => $result->getValueMapList(),
        ];
    }

    /**
     * GET WahaSessionApp/:id - Get a single app.
     * ID format: platformId_wahaAppId
     *
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function getActionRead(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest("ID is required.");
        }

        [$platformId, $appId] = $this->parseId($id);

        $service = $this->getWahaSessionAppService();

        return $service->read($platformId, $appId);
    }

    /**
     * POST WahaSessionApp - Create a new app.
     *
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     */
    public function postActionCreate(Request $request, Response $response): stdClass
    {
        $data = $request->getParsedBody();

        if (!isset($data->platformId)) {
            throw new BadRequest("platformId is required.");
        }

        $platformId = $data->platformId;

        $service = $this->getWahaSessionAppService();

        return $service->create($platformId, $data);
    }

    /**
     * PUT WahaSessionApp/:id - Update an app.
     * ID format: platformId_wahaAppId
     *
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     */
    public function putActionUpdate(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest("ID is required.");
        }

        [$platformId, $appId] = $this->parseId($id);

        $data = $request->getParsedBody();

        $service = $this->getWahaSessionAppService();

        return $service->update($platformId, $appId, $data);
    }

    /**
     * DELETE WahaSessionApp/:id - Delete an app.
     * ID format: platformId_wahaAppId
     *
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     */
    public function deleteActionDelete(Request $request, Response $response): bool
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest("ID is required.");
        }

        [$platformId, $appId] = $this->parseId($id);

        $service = $this->getWahaSessionAppService();
        $service->delete($platformId, $appId);

        return true;
    }

    /**
     * GET WahaSessionApp/:id/:link - Get linked records.
     * WahaSessionApp is a virtual entity, returns empty list.
     */
    public function getActionListLinked(Request $request, Response $response): stdClass
    {
        return (object) [
            'total' => 0,
            'list' => [],
        ];
    }

    /**
     * POST WahaSessionApp/:id/createLink - Stub for link creation.
     * WahaSessionApp is a virtual entity - returns true.
     */
    public function postActionCreateLink(Request $request, Response $response): bool
    {
        return true;
    }

    /**
     * DELETE WahaSessionApp/:id/removeLink - Stub for link removal.
     * WahaSessionApp is a virtual entity - returns true.
     */
    public function deleteActionRemoveLink(Request $request, Response $response): bool
    {
        return true;
    }

    /**
     * Parse composite ID (platformId_wahaAppId).
     *
     * @param string $id
     * @return array{0: string, 1: string}
     * @throws BadRequest
     */
    private function parseId(string $id): array
    {
        $parts = explode('_', $id, 2);

        if (count($parts) !== 2) {
            throw new BadRequest("Invalid ID format. Expected: platformId_wahaAppId");
        }

        return [$parts[0], $parts[1]];
    }

    private function getWahaSessionAppService(): WahaSessionAppService
    {
        return $this->injectableFactory->create(WahaSessionAppService::class);
    }
}
