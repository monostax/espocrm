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
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\InjectableFactory;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\WhatsAppBusinessAccountMessageTemplate as MessageTemplateService;
use stdClass;

/**
 * Controller for WhatsAppBusinessAccountMessageTemplate virtual entity.
 *
 * Read-only controller — no create, update, or delete actions.
 */
class WhatsAppBusinessAccountMessageTemplate
{
    private InjectableFactory $injectableFactory;

    public function __construct(InjectableFactory $injectableFactory)
    {
        $this->injectableFactory = $injectableFactory;
    }

    /**
     * GET WhatsAppBusinessAccountMessageTemplate - List templates.
     * Route: GET api/v1/WhatsAppBusinessAccountMessageTemplate?credentialId=xxx
     *
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     */
    public function getActionIndex(Request $request, Response $response): stdClass
    {
        $credentialId = $request->getQueryParam('credentialId');

        if (!$credentialId) {
            throw new BadRequest("credentialId query parameter is required.");
        }

        $service = $this->getService();
        $result = $service->find($credentialId);

        return (object) [
            'total' => $result->getTotal(),
            'list' => $result->getValueMapList(),
        ];
    }

    /**
     * GET WhatsAppBusinessAccountMessageTemplate/:id - Get a single template.
     * ID format: credentialId_templateId
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

        [$credentialId, $templateId] = $this->parseId($id);

        $service = $this->getService();

        return $service->read($credentialId, $templateId);
    }

    /**
     * GET WhatsAppBusinessAccountMessageTemplate/:id/:link - Get linked records.
     * Virtual entity — returns empty list for all links.
     */
    public function getActionListLinked(Request $request, Response $response): stdClass
    {
        return (object) [
            'total' => 0,
            'list' => [],
        ];
    }

    /**
     * POST WhatsAppBusinessAccountMessageTemplate/:id/createLink - Stub.
     */
    public function postActionCreateLink(Request $request, Response $response): bool
    {
        return true;
    }

    /**
     * DELETE WhatsAppBusinessAccountMessageTemplate/:id/removeLink - Stub.
     */
    public function deleteActionRemoveLink(Request $request, Response $response): bool
    {
        return true;
    }

    /**
     * Parse composite ID (credentialId_templateId).
     *
     * @param string $id
     * @return array{0: string, 1: string}
     * @throws BadRequest
     */
    private function parseId(string $id): array
    {
        $parts = explode('_', $id, 2);

        if (count($parts) !== 2) {
            throw new BadRequest("Invalid ID format. Expected: credentialId_templateId");
        }

        return [$parts[0], $parts[1]];
    }

    private function getService(): MessageTemplateService
    {
        return $this->injectableFactory->create(MessageTemplateService::class);
    }
}
