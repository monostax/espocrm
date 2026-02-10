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
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\WhatsAppBusinessAccount as WhatsAppBusinessAccountService;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\WhatsAppBusinessAccountPhoneNumber as PhoneNumberService;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\WhatsAppBusinessAccountMessageTemplate as MessageTemplateService;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\WhatsAppOAuthHelper;
use stdClass;

/**
 * Controller for WhatsAppBusinessAccount virtual entity.
 *
 * Read-only controller — no create, update, or delete actions.
 * Uses OAuthAccount for authentication and dynamic WABA discovery.
 */
class WhatsAppBusinessAccount
{
    private InjectableFactory $injectableFactory;

    public function __construct(InjectableFactory $injectableFactory)
    {
        $this->injectableFactory = $injectableFactory;
    }

    /**
     * GET WhatsAppBusinessAccount - List all business accounts.
     * Route: GET api/v1/WhatsAppBusinessAccount?oAuthAccountId=xxx (optional)
     *
     * Supports filtering via:
     *   - Direct `oAuthAccountId` query param (single OAuthAccount).
     *   - Native EspoCRM `where` clauses for `oAuthAccountId` attribute
     *     (equals for single, in for multiple).
     *
     * If no OAuthAccount filter is provided, fetches from all accessible OAuthAccounts.
     *
     * @throws Error
     * @throws Forbidden
     */
    public function getActionIndex(Request $request, Response $response): stdClass
    {
        $oAuthAccountId = $request->getQueryParam('oAuthAccountId');
        $oAuthAccountIds = null;

        // Parse native EspoCRM where clauses for OAuthAccount filter.
        // EspoCRM sends `whereGroup` (with fallback `where`).
        $where = $request->getQueryParams()['whereGroup']
            ?? $request->getQueryParams()['where']
            ?? null;

        if (is_array($where)) {
            foreach ($where as $item) {
                $attribute = $item['attribute'] ?? null;

                if ($attribute !== 'oAuthAccountId') {
                    continue;
                }

                $type = $item['type'] ?? null;

                if ($type === 'equals' && isset($item['value'])) {
                    $oAuthAccountId = $item['value'];
                } elseif ($type === 'in' && is_array($item['value'] ?? null)) {
                    $oAuthAccountIds = $item['value'];
                }
            }
        }

        $service = $this->getService();
        $result = $service->find($oAuthAccountId, $oAuthAccountIds);

        return (object) [
            'total' => $result->getTotal(),
            'list' => $result->getValueMapList(),
        ];
    }

    /**
     * GET WhatsAppBusinessAccount/:id - Get a single business account.
     * ID format: oAuthAccountId_wabaId
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

        [$oAuthAccountId, $wabaId] = $this->parseId($id);

        $service = $this->getService();

        return $service->read($oAuthAccountId, $wabaId);
    }

    /**
     * GET WhatsAppBusinessAccount/:id/:link - Get linked records.
     * Dispatches to child services for phoneNumbers and messageTemplates links.
     *
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function getActionListLinked(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');
        $link = $request->getRouteParam('link');

        if (!$id) {
            throw new BadRequest("ID is required.");
        }

        if (!$link) {
            throw new BadRequest("Link name is required.");
        }

        [$oAuthAccountId, $wabaId] = $this->parseId($id);

        return match ($link) {
            'phoneNumbers' => $this->listPhoneNumbers($oAuthAccountId, $wabaId),
            'messageTemplates' => $this->listMessageTemplates($oAuthAccountId, $wabaId),
            default => (object) [
                'total' => 0,
                'list' => [],
            ],
        };
    }

    /**
     * List phone numbers for a WABA via the child service.
     *
     * @throws Error
     * @throws Forbidden
     */
    private function listPhoneNumbers(string $oAuthAccountId, string $businessAccountId): stdClass
    {
        $service = $this->injectableFactory->create(PhoneNumberService::class);
        $result = $service->find($oAuthAccountId, $businessAccountId);

        return (object) [
            'total' => $result->getTotal(),
            'list' => $result->getValueMapList(),
        ];
    }

    /**
     * List message templates for a WABA via the child service.
     *
     * @throws Error
     * @throws Forbidden
     */
    private function listMessageTemplates(string $oAuthAccountId, string $businessAccountId): stdClass
    {
        $service = $this->injectableFactory->create(MessageTemplateService::class);
        $result = $service->find($oAuthAccountId, $businessAccountId);

        return (object) [
            'total' => $result->getTotal(),
            'list' => $result->getValueMapList(),
        ];
    }

    /**
     * GET WhatsAppBusinessAccount/action/oAuthAccounts - List accessible OAuthAccounts.
     * Returns all active OAuthAccounts the current user can access.
     *
     * @throws Error
     */
    public function getActionOAuthAccounts(Request $request, Response $response): stdClass
    {
        $oAuthHelper = $this->injectableFactory->create(WhatsAppOAuthHelper::class);
        $oAuthAccounts = $oAuthHelper->getAccessibleOAuthAccounts();

        $list = [];

        foreach ($oAuthAccounts as $oAuthAccount) {
            $list[] = (object) [
                'id' => $oAuthAccount->getId(),
                'name' => $oAuthAccount->get('name'),
            ];
        }

        return (object) [
            'total' => count($list),
            'list' => $list,
        ];
    }

    /**
     * POST WhatsAppBusinessAccount/:id/createLink - Stub for link creation.
     * Virtual entity — links are not managed in the database.
     */
    public function postActionCreateLink(Request $request, Response $response): bool
    {
        return true;
    }

    /**
     * DELETE WhatsAppBusinessAccount/:id/removeLink - Stub for link removal.
     * Virtual entity — links are not managed in the database.
     */
    public function deleteActionRemoveLink(Request $request, Response $response): bool
    {
        return true;
    }

    /**
     * Parse composite ID (oAuthAccountId_wabaId).
     *
     * @param string $id
     * @return array{0: string, 1: string}
     * @throws BadRequest
     */
    private function parseId(string $id): array
    {
        $parts = explode('_', $id, 2);

        if (count($parts) !== 2) {
            throw new BadRequest("Invalid ID format. Expected: oAuthAccountId_wabaId");
        }

        return [$parts[0], $parts[1]];
    }

    private function getService(): WhatsAppBusinessAccountService
    {
        return $this->injectableFactory->create(WhatsAppBusinessAccountService::class);
    }
}
