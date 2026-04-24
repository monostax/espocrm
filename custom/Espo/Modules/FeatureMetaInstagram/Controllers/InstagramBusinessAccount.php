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

namespace Espo\Modules\FeatureMetaInstagram\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\InjectableFactory;
use Espo\Modules\FeatureMetaInstagram\Services\InstagramBusinessAccount as InstagramBusinessAccountService;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\WhatsAppOAuthHelper;
use stdClass;

/**
 * Controller for InstagramBusinessAccount virtual entity.
 *
 * Read-only controller — no create, update, or delete actions.
 */
class InstagramBusinessAccount
{
    public function __construct(
        private InjectableFactory $injectableFactory,
    ) {}

    /**
     * GET InstagramBusinessAccount - List all accessible Instagram Business Accounts.
     * Route: GET api/v1/InstagramBusinessAccount?oAuthAccountId=xxx (optional)
     *
     * @throws Error
     * @throws Forbidden
     */
    public function getActionIndex(Request $request, Response $response): stdClass
    {
        $oAuthAccountId = $request->getQueryParam('oAuthAccountId');
        $oAuthAccountIds = null;

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
     * GET InstagramBusinessAccount/:id - Get a single business account.
     * ID format: oAuthAccountId_instagramId
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

        [$oAuthAccountId, $instagramId] = $this->parseId($id);

        $service = $this->getService();

        return $service->read($oAuthAccountId, $instagramId);
    }

    /**
     * GET InstagramBusinessAccount/action/oAuthAccounts - List accessible meta-instagram OAuthAccounts.
     */
    public function getActionOAuthAccounts(Request $request, Response $response): stdClass
    {
        $oAuthHelper = $this->injectableFactory->create(WhatsAppOAuthHelper::class);
        $oAuthAccounts = $oAuthHelper->getAccessibleOAuthAccounts('meta-instagram');

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
     * POST InstagramBusinessAccount/:id/createLink - Stub for link creation.
     */
    public function postActionCreateLink(Request $request, Response $response): bool
    {
        return true;
    }

    /**
     * DELETE InstagramBusinessAccount/:id/removeLink - Stub for link removal.
     */
    public function deleteActionRemoveLink(Request $request, Response $response): bool
    {
        return true;
    }

    /**
     * @return array{0: string, 1: string}
     * @throws BadRequest
     */
    private function parseId(string $id): array
    {
        $parts = explode('_', $id, 2);

        if (count($parts) !== 2) {
            throw new BadRequest("Invalid ID format. Expected: oAuthAccountId_instagramId");
        }

        return [$parts[0], $parts[1]];
    }

    private function getService(): InstagramBusinessAccountService
    {
        return $this->injectableFactory->create(InstagramBusinessAccountService::class);
    }
}
