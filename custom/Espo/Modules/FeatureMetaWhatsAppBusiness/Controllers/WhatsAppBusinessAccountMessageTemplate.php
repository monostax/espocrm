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
use Espo\ORM\EntityManager;
use Espo\Modules\Global\Tools\Credential\CredentialResolver;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\WhatsAppBusinessAccountMessageTemplate as MessageTemplateService;
use stdClass;

/**
 * Controller for WhatsAppBusinessAccountMessageTemplate virtual entity.
 *
 * Read-only controller — no create, update, or delete actions.
 *
 * Supports two auth paths:
 *   - oAuthAccountId + businessAccountId (preferred, used by parent WABA controller)
 *   - credentialId + wabaId (used by WhatsAppCampaign template picker)
 */
class WhatsAppBusinessAccountMessageTemplate
{
    public function __construct(
        private InjectableFactory $injectableFactory,
        private EntityManager $entityManager,
        private CredentialResolver $credentialResolver,
    ) {}

    /**
     * GET WhatsAppBusinessAccountMessageTemplate - List templates.
     *
     * Accepts either:
     *   ?oAuthAccountId=xxx&businessAccountId=yyy  (direct)
     *   ?credentialId=xxx&wabaId=yyy               (resolved from credential)
     *
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     */
    public function getActionIndex(Request $request, Response $response): stdClass
    {
        $oAuthAccountId = $request->getQueryParam('oAuthAccountId');
        $businessAccountId = $request->getQueryParam('businessAccountId');

        if (!$oAuthAccountId) {
            $credentialId = $request->getQueryParam('credentialId');
            $wabaId = $request->getQueryParam('wabaId');

            if ($credentialId) {
                [$oAuthAccountId, $resolvedWabaId] = $this->resolveFromCredential($credentialId);
                $businessAccountId = $wabaId ?: $resolvedWabaId;
            }
        }

        if (!$oAuthAccountId) {
            throw new BadRequest("oAuthAccountId or credentialId query parameter is required.");
        }

        if (!$businessAccountId) {
            throw new BadRequest("businessAccountId or wabaId query parameter is required.");
        }

        $service = $this->getService();
        $result = $service->find($oAuthAccountId, $businessAccountId);

        return (object) [
            'total' => $result->getTotal(),
            'list' => $result->getValueMapList(),
        ];
    }

    /**
     * GET WhatsAppBusinessAccountMessageTemplate/:id - Get a single template.
     * ID format: oAuthAccountId_templateId
     *
     * Requires businessAccountId as a query parameter.
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

        $businessAccountId = $request->getQueryParam('businessAccountId');

        if (!$businessAccountId) {
            throw new BadRequest("businessAccountId query parameter is required.");
        }

        [$oAuthAccountId, $templateId] = $this->parseId($id);

        $service = $this->getService();

        return $service->read($oAuthAccountId, $businessAccountId, $templateId);
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
     * Resolve oAuthAccountId and businessAccountId from a credential ID.
     *
     * @param string $credentialId
     * @return array{0: string, 1: string} [oAuthAccountId, businessAccountId]
     * @throws BadRequest
     */
    private function resolveFromCredential(string $credentialId): array
    {
        try {
            $resolvedConfig = $this->credentialResolver->resolve($credentialId);
        } catch (\Throwable $e) {
            throw new BadRequest("Failed to resolve credential: " . $e->getMessage());
        }

        $businessAccountId = $resolvedConfig->businessAccountId ?? null;

        if (!$businessAccountId) {
            throw new BadRequest("Credential is missing businessAccountId.");
        }

        $credential = $this->entityManager->getEntityById('Credential', $credentialId);

        if (!$credential) {
            throw new BadRequest("Credential not found.");
        }

        $oAuthAccountId = $credential->get('oAuthAccountId');

        if (!$oAuthAccountId) {
            throw new BadRequest("Credential has no linked OAuth Account.");
        }

        return [$oAuthAccountId, $businessAccountId];
    }

    /**
     * Parse composite ID (oAuthAccountId_templateId).
     *
     * @param string $id
     * @return array{0: string, 1: string}
     * @throws BadRequest
     */
    private function parseId(string $id): array
    {
        $parts = explode('_', $id, 2);

        if (count($parts) !== 2) {
            throw new BadRequest("Invalid ID format. Expected: oAuthAccountId_templateId");
        }

        return [$parts[0], $parts[1]];
    }

    private function getService(): MessageTemplateService
    {
        return $this->injectableFactory->create(MessageTemplateService::class);
    }
}
