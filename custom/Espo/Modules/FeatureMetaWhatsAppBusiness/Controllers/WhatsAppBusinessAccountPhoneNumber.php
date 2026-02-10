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
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\WhatsAppBusinessAccountPhoneNumber as PhoneNumberService;
use stdClass;

/**
 * Controller for WhatsAppBusinessAccountPhoneNumber virtual entity.
 *
 * Read-only controller — no create, update, or delete actions.
 * Uses OAuthAccount + businessAccountId for authentication and data fetching.
 */
class WhatsAppBusinessAccountPhoneNumber
{
    private InjectableFactory $injectableFactory;

    public function __construct(InjectableFactory $injectableFactory)
    {
        $this->injectableFactory = $injectableFactory;
    }

    /**
     * GET WhatsAppBusinessAccountPhoneNumber - List phone numbers.
     * Route: GET api/v1/WhatsAppBusinessAccountPhoneNumber?oAuthAccountId=xxx&businessAccountId=yyy
     *
     * Also supports legacy `credentialId` parameter for backward compatibility
     * during migration. When credentialId is provided, it resolves the
     * oAuthAccountId and businessAccountId from the credential.
     *
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     */
    public function getActionIndex(Request $request, Response $response): stdClass
    {
        $oAuthAccountId = $request->getQueryParam('oAuthAccountId');
        $businessAccountId = $request->getQueryParam('businessAccountId');

        // Legacy support: resolve from credentialId if new params not provided.
        $credentialId = $request->getQueryParam('credentialId');

        if (!$oAuthAccountId && $credentialId) {
            [$oAuthAccountId, $businessAccountId] = $this->resolveFromCredential($credentialId);
        }

        if (!$oAuthAccountId) {
            throw new BadRequest("oAuthAccountId query parameter is required.");
        }

        if (!$businessAccountId) {
            throw new BadRequest("businessAccountId query parameter is required.");
        }

        $service = $this->getService();
        $result = $service->find($oAuthAccountId, $businessAccountId);

        return (object) [
            'total' => $result->getTotal(),
            'list' => $result->getValueMapList(),
        ];
    }

    /**
     * GET WhatsAppBusinessAccountPhoneNumber/:id - Get a single phone number.
     * ID format: oAuthAccountId_phoneNumberId
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

        [$oAuthAccountId, $phoneNumberId] = $this->parseId($id);

        $service = $this->getService();

        return $service->read($oAuthAccountId, $businessAccountId, $phoneNumberId);
    }

    /**
     * GET WhatsAppBusinessAccountPhoneNumber/:id/:link - Get linked records.
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
     * POST WhatsAppBusinessAccountPhoneNumber/:id/createLink - Stub.
     */
    public function postActionCreateLink(Request $request, Response $response): bool
    {
        return true;
    }

    /**
     * DELETE WhatsAppBusinessAccountPhoneNumber/:id/removeLink - Stub.
     */
    public function deleteActionRemoveLink(Request $request, Response $response): bool
    {
        return true;
    }

    /**
     * Resolve oAuthAccountId and businessAccountId from a credential ID.
     * Used for backward compatibility during migration.
     *
     * @param string $credentialId
     * @return array{0: string, 1: string} [oAuthAccountId, businessAccountId]
     * @throws BadRequest
     */
    private function resolveFromCredential(string $credentialId): array
    {
        $resolver = $this->injectableFactory->create(
            \Espo\Modules\Global\Tools\Credential\CredentialResolver::class
        );

        try {
            $resolvedConfig = $resolver->resolve($credentialId);
        } catch (\Throwable $e) {
            throw new BadRequest("Failed to resolve credential: " . $e->getMessage());
        }

        $businessAccountId = $resolvedConfig->businessAccountId ?? null;

        if (!$businessAccountId) {
            throw new BadRequest("Credential is missing businessAccountId.");
        }

        // Get oAuthAccountId from the credential entity.
        $entityManager = $this->injectableFactory->create(\Espo\ORM\EntityManager::class);
        $credential = $entityManager->getEntityById('Credential', $credentialId);

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
     * Parse composite ID (oAuthAccountId_phoneNumberId).
     *
     * @param string $id
     * @return array{0: string, 1: string}
     * @throws BadRequest
     */
    private function parseId(string $id): array
    {
        $parts = explode('_', $id, 2);

        if (count($parts) !== 2) {
            throw new BadRequest("Invalid ID format. Expected: oAuthAccountId_phoneNumberId");
        }

        return [$parts[0], $parts[1]];
    }

    private function getService(): PhoneNumberService
    {
        return $this->injectableFactory->create(PhoneNumberService::class);
    }
}
