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
use Espo\Modules\Chatwoot\Services\WahaSession as WahaSessionService;
use Espo\Modules\Chatwoot\Services\WahaSessionApp as WahaSessionAppService;
use stdClass;

/**
 * Controller for WahaSession virtual entity.
 */
class WahaSession
{
    private InjectableFactory $injectableFactory;

    public function __construct(InjectableFactory $injectableFactory)
    {
        $this->injectableFactory = $injectableFactory;
    }

    /**
     * GET WahaSession - List all sessions.
     * Route: GET api/v1/WahaSession?platformId=xxx (optional)
     * If platformId is not provided, fetches sessions from all platforms.
     *
     * @throws Error
     * @throws Forbidden
     */
    public function getActionIndex(Request $request, Response $response): stdClass
    {
        $platformId = $request->getQueryParam('platformId');

        $service = $this->getWahaSessionService();
        $result = $service->find($platformId);

        return (object) [
            'total' => $result->getTotal(),
            'list' => $result->getValueMapList(),
        ];
    }

    /**
     * GET WahaSession/:id - Get a single session.
     * ID format: platformId_sessionName
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

        [$platformId, $sessionName] = $this->parseId($id);

        $service = $this->getWahaSessionService();

        return $service->read($platformId, $sessionName);
    }

    /**
     * POST WahaSession - Create a new session.
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

        $service = $this->getWahaSessionService();

        return $service->create($platformId, $data);
    }

    /**
     * PUT WahaSession/:id - Update a session.
     * ID format: platformId_sessionName
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

        [$platformId, $sessionName] = $this->parseId($id);

        $data = $request->getParsedBody();

        $service = $this->getWahaSessionService();

        return $service->update($platformId, $sessionName, $data);
    }

    /**
     * DELETE WahaSession/:id - Delete a session.
     * ID format: platformId_sessionName
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

        [$platformId, $sessionName] = $this->parseId($id);

        $service = $this->getWahaSessionService();
        $service->delete($platformId, $sessionName);

        return true;
    }

    /**
     * POST WahaSession/:id/start - Start a session.
     *
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     */
    public function postActionStart(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest("ID is required.");
        }

        [$platformId, $sessionName] = $this->parseId($id);

        $service = $this->getWahaSessionService();

        return $service->start($platformId, $sessionName);
    }

    /**
     * POST WahaSession/:id/stop - Stop a session.
     *
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     */
    public function postActionStop(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest("ID is required.");
        }

        [$platformId, $sessionName] = $this->parseId($id);

        $service = $this->getWahaSessionService();

        return $service->stop($platformId, $sessionName);
    }

    /**
     * POST WahaSession/:id/restart - Restart a session.
     *
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     */
    public function postActionRestart(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest("ID is required.");
        }

        [$platformId, $sessionName] = $this->parseId($id);

        $service = $this->getWahaSessionService();

        return $service->restart($platformId, $sessionName);
    }

    /**
     * POST WahaSession/:id/logout - Logout from a session.
     *
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     */
    public function postActionLogout(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest("ID is required.");
        }

        [$platformId, $sessionName] = $this->parseId($id);

        $service = $this->getWahaSessionService();

        return $service->logout($platformId, $sessionName);
    }

    /**
     * GET WahaSession/action/qrCode?id=xxx - Get QR code for pairing.
     * ID format: platformId_sessionName
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

        [$platformId, $sessionName] = $this->parseId($id);

        $service = $this->getWahaSessionService();

        return $service->getQrCode($platformId, $sessionName);
    }

    /**
     * GET WahaSession/:id/apps - List apps for a session.
     *
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     */
    public function getActionListApps(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest("ID is required.");
        }

        [$platformId, $sessionName] = $this->parseId($id);

        $service = $this->getWahaSessionAppService();
        $result = $service->findBySession($platformId, $sessionName);

        return (object) [
            'total' => $result->getTotal(),
            'list' => $result->getValueMapList(),
        ];
    }

    /**
     * GET WahaSession/:id/:link - Get linked records.
     * WahaSession is a virtual entity, returns empty list for other links.
     */
    public function getActionListLinked(Request $request, Response $response): stdClass
    {
        $link = $request->getRouteParam('link');

        // For 'apps' link, delegate to listApps
        if ($link === 'apps') {
            return $this->getActionListApps($request, $response);
        }

        return (object) [
            'total' => 0,
            'list' => [],
        ];
    }

    /**
     * POST WahaSession/:id/createLink - Stub for link creation.
     * WahaSession is a virtual entity - links are managed by the API, not the database.
     * Returns true to indicate "success" without actually doing anything.
     */
    public function postActionCreateLink(Request $request, Response $response): bool
    {
        // Virtual entity - link relationship is informational only.
        // The platformId is set during session creation in the WAHA API.
        return true;
    }

    /**
     * DELETE WahaSession/:id/removeLink - Stub for link removal.
     * WahaSession is a virtual entity - links are managed by the API, not the database.
     * Returns true to indicate "success" without actually doing anything.
     */
    public function deleteActionRemoveLink(Request $request, Response $response): bool
    {
        // Virtual entity - link relationship is informational only.
        return true;
    }

    /**
     * Parse composite ID (platformId_sessionName).
     *
     * @param string $id
     * @return array{0: string, 1: string}
     * @throws BadRequest
     */
    private function parseId(string $id): array
    {
        $parts = explode('_', $id, 2);

        if (count($parts) !== 2) {
            throw new BadRequest("Invalid ID format. Expected: platformId_sessionName");
        }

        return [$parts[0], $parts[1]];
    }

    private function getWahaSessionService(): WahaSessionService
    {
        return $this->injectableFactory->create(WahaSessionService::class);
    }

    private function getWahaSessionAppService(): WahaSessionAppService
    {
        return $this->injectableFactory->create(WahaSessionAppService::class);
    }
}
