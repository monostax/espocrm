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

namespace Espo\Modules\FeatureAgentbox\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\InjectableFactory;
use Espo\Modules\FeatureAgentbox\Services\AgentSkill as AgentSkillService;
use Espo\Modules\FeatureAgentbox\Services\CatalogScope;
use stdClass;

/**
 * Virtual entity controller for AgentSkill (OpenCode skills FS).
 */
class AgentSkill
{
    public function __construct(
        private InjectableFactory $injectableFactory
    ) {}

    /**
     * GET AgentSkill[?tenantId=&workspaceKind=&targetUserId=&membershipId=…]
     *
     * @throws BadRequest
     * @throws Forbidden
     * @throws Error
     */
    public function getActionIndex(Request $request, Response $response): stdClass
    {
        [$authToken, $authTokenSecret] = $this->requireAuthCookies($request);
        $tenantId = $this->optionalTenantId($request);
        $scopeOpts = $this->scopeOptsFromRequest($request);

        $result = $this->getService()->find($tenantId, $authToken, $authTokenSecret, $scopeOpts);

        return (object) [
            'total' => $result->getTotal(),
            'list' => $result->getValueMapList(),
        ];
    }

    /**
     * GET AgentSkill/:id
     *
     * @throws BadRequest
     * @throws Forbidden
     * @throws NotFound
     * @throws Error
     */
    public function getActionRead(Request $request, Response $response): stdClass
    {
        [$authToken, $authTokenSecret] = $this->requireAuthCookies($request);
        $id = $request->getRouteParam('id');
        if (!$id) {
            throw new BadRequest('ID is required.');
        }

        [$tenantId, $skillName, $scopeOpts] = $this->getService()->parseId($id);

        return $this->getService()->read(
            $tenantId,
            $skillName,
            $authToken,
            $authTokenSecret,
            $scopeOpts
        );
    }

    /**
     * POST AgentSkill
     *
     * @throws BadRequest
     * @throws Forbidden
     * @throws Error
     */
    public function postActionCreate(Request $request, Response $response): stdClass
    {
        [$authToken, $authTokenSecret] = $this->requireAuthCookies($request);
        $data = $request->getParsedBody();

        return $this->getService()->create($data, $authToken, $authTokenSecret);
    }

    /**
     * PUT AgentSkill/:id
     *
     * @throws BadRequest
     * @throws Forbidden
     * @throws NotFound
     * @throws Error
     */
    public function putActionUpdate(Request $request, Response $response): stdClass
    {
        [$authToken, $authTokenSecret] = $this->requireAuthCookies($request);
        $id = $request->getRouteParam('id');
        if (!$id) {
            throw new BadRequest('ID is required.');
        }

        [$tenantId, $skillName, $scopeOpts] = $this->getService()->parseId($id);
        $data = $request->getParsedBody();

        return $this->getService()->update(
            $tenantId,
            $skillName,
            $data,
            $authToken,
            $authTokenSecret,
            $scopeOpts
        );
    }

    /**
     * DELETE AgentSkill/:id
     *
     * @throws BadRequest
     * @throws Forbidden
     * @throws NotFound
     * @throws Error
     */
    public function deleteActionDelete(Request $request, Response $response): bool
    {
        [$authToken, $authTokenSecret] = $this->requireAuthCookies($request);
        $id = $request->getRouteParam('id');
        if (!$id) {
            throw new BadRequest('ID is required.');
        }

        [$tenantId, $skillName, $scopeOpts] = $this->getService()->parseId($id);
        $this->getService()->delete(
            $tenantId,
            $skillName,
            $authToken,
            $authTokenSecret,
            $scopeOpts
        );

        return true;
    }

    /**
     * No-op link stubs so the main UI does not 500 on virtual relations.
     */
    public function postActionCreateLink(Request $request, Response $response): bool
    {
        return true;
    }

    public function deleteActionRemoveLink(Request $request, Response $response): bool
    {
        return true;
    }

    private function getService(): AgentSkillService
    {
        return $this->injectableFactory->create(AgentSkillService::class);
    }

    /**
     * @return array{0: string, 1: string}
     * @throws BadRequest
     */
    private function requireAuthCookies(Request $request): array
    {
        $authToken = $request->getCookieParam('auth-token');
        $authTokenSecret = $request->getCookieParam('auth-token-secret');

        if (!$authToken || !$authTokenSecret) {
            throw new BadRequest(
                'Missing auth-token cookies. AgentSkill requires a browser session.'
            );
        }

        return [$authToken, $authTokenSecret];
    }

    /**
     * Optional list filter. Empty / missing → all accessible tenants.
     */
    private function optionalTenantId(Request $request): ?string
    {
        $tenantId = $request->getQueryParam('tenantId')
            ?? $request->getQueryParam('tenant')
            ?? null;

        if (is_string($tenantId) && trim($tenantId) !== '') {
            return trim($tenantId);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function scopeOptsFromRequest(Request $request): array
    {
        $kind = $request->getQueryParam('workspaceKind')
            ?? $request->getQueryParam('scope')
            ?? CatalogScope::KIND_USER;

        $opts = [
            'workspaceKind' => is_string($kind) ? $kind : CatalogScope::KIND_USER,
        ];

        foreach (['targetUserId', 'userId', 'membershipId', 'contactId', 'chatwootAccountCrmId'] as $key) {
            $v = $request->getQueryParam($key);
            if (is_string($v) && trim($v) !== '') {
                if ($key === 'userId') {
                    $opts['targetUserId'] = trim($v);
                } else {
                    $opts[$key] = trim($v);
                }
            }
        }

        return $opts;
    }
}
