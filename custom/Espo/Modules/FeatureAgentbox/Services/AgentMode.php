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

namespace Espo\Modules\FeatureAgentbox\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\Collection as RecordCollection;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;
use Throwable;

/**
 * Virtual Record service: AgentMode ↔ agentbox workspace OpenCode modes FS.
 *
 * SoT: /workspace/.config/opencode/modes/{name}.md
 * @see https://open-code.ai/en/docs/modes
 */
class AgentMode
{
    public const ENTITY_TYPE = 'AgentMode';

    private const MODE_NAME_RE = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    public function __construct(
        private EntityManager $entityManager,
        private BackendApiClient $backendApiClient,
        private Acl $acl,
        private User $user
    ) {}

    /**
     * @throws Forbidden
     * @throws BadRequest
     * @throws Error
     */
    public function find(
        ?string $tenantId,
        string $authToken,
        string $authTokenSecret,
        array $scopeOpts = []
    ): RecordCollection {
        $this->assertScope('read');
        $kind = CatalogScope::normalizeKind($scopeOpts['workspaceKind'] ?? CatalogScope::KIND_USER);

        $tenants = $kind === CatalogScope::KIND_CRM_GLOBAL
            ? [$this->syntheticGlobalTenant()]
            : ($tenantId !== null && $tenantId !== ''
                ? [$this->requireTenant($tenantId)]
                : $this->resolveAccessibleTenants());

        $collection = $this->entityManager->getCollectionFactory()->create(self::ENTITY_TYPE);
        $total = 0;

        foreach ($tenants as $tenant) {
            $tid = $tenant->getId();
            if (!is_string($tid) || $tid === '') {
                continue;
            }

            try {
                $response = $this->backendApiClient->request(
                    'GET',
                    '/agentbox/modes',
                    $authToken,
                    $authTokenSecret,
                    CatalogScope::toBackendQuery($tid, array_merge($scopeOpts ?? [], [
                    'workspaceKind' => $kind ?? CatalogScope::KIND_USER,
                ]), $this->user)
                );
            } catch (Throwable $e) {
                if ($tenantId !== null && $tenantId !== '') {
                    throw $e instanceof Error
                        ? $e
                        : new Error($e->getMessage(), 0, $e);
                }

                continue;
            }

            $list = $response['body']['list'] ?? [];
            if (!is_array($list)) {
                if ($tenantId !== null && $tenantId !== '') {
                    throw new Error('Invalid modes list from backend.');
                }

                continue;
            }

            foreach ($list as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $collection->append($this->mapToEntity(
                    $item,
                    $tenant,
                    $kind,
                    $this->entityRefFromOpts($kind, $scopeOpts)
                ));
                $total++;
            }
        }

        return RecordCollection::create($collection, $total);
    }

    /**
     * @return list<Entity>
     */
    private function resolveAccessibleTenants(): array
    {
        $tenants = [];
        $seen = [];

        try {
            $related = $this->entityManager
                ->getRDBRepository('User')
                ->getRelation($this->user, 'tenants')
                ->find();

            foreach ($related as $tenant) {
                $id = $tenant->getId();
                if (!is_string($id) || $id === '' || isset($seen[$id])) {
                    continue;
                }

                if (!$this->acl->check($tenant, 'read')) {
                    continue;
                }

                $seen[$id] = true;
                $tenants[] = $tenant;
            }
        } catch (Throwable) {
            // fall through
        }

        if ($tenants !== []) {
            return $tenants;
        }

        if (!$this->user->isAdmin()) {
            return [];
        }

        $all = $this->entityManager
            ->getRDBRepository('Tenant')
            ->order('name')
            ->find();

        foreach ($all as $tenant) {
            $id = $tenant->getId();
            if (!is_string($id) || $id === '' || isset($seen[$id])) {
                continue;
            }

            if (!$this->acl->check($tenant, 'read')) {
                continue;
            }

            $seen[$id] = true;
            $tenants[] = $tenant;
        }

        return $tenants;
    }

    /**
     * @throws Forbidden
     * @throws BadRequest
     * @throws NotFound
     * @throws Error
     */
    public function read(
        string $tenantId,
        string $modeName,
        string $authToken,
        string $authTokenSecret,
        array $scopeOpts = []
    ): stdClass {
        $this->assertScope('read');
        $kind = CatalogScope::normalizeKind($scopeOpts['workspaceKind'] ?? CatalogScope::KIND_USER);
        $tenant = $kind === CatalogScope::KIND_CRM_GLOBAL
            ? $this->syntheticGlobalTenant()
            : $this->requireTenant($tenantId);
        $modeName = $this->assertModeName($modeName);

        try {
            $response = $this->backendApiClient->request(
                'GET',
                '/agentbox/modes/' . rawurlencode($modeName),
                $authToken,
                $authTokenSecret,
                CatalogScope::toBackendQuery($tenantId, array_merge($scopeOpts ?? [], [
                    'workspaceKind' => $kind ?? CatalogScope::KIND_USER,
                ]), $this->user)
            );
        } catch (Error $e) {
            if ($e->getCode() === 404) {
                throw new NotFound("Mode '{$modeName}' not found.");
            }
            throw $e;
        }

        $body = $response['body'] ?? null;
        if (!is_array($body)) {
            throw new Error('Invalid mode payload from backend.');
        }

        return $this->mapToEntity(
            $body,
            $tenant,
            $kind,
            $this->entityRefFromOpts($kind, $scopeOpts)
        )->getValueMap();
    }

    /**
     * @throws Forbidden
     * @throws BadRequest
     * @throws Error
     */
    public function create(
        stdClass $data,
        string $authToken,
        string $authTokenSecret
    ): stdClass {
        $this->assertScope('create');

        $scope = CatalogScope::fromRequestData($data, $this->user);
        $kind = $scope['workspaceKind'];
        CatalogAuth::assertCanWriteCatalog(
            $this->user,
            $this->entityManager,
            $kind,
            $scope['targetUserId']
        );
        $scopeOpts = [
            'workspaceKind' => $kind,
            'targetUserId' => $scope['targetUserId'],
            'membershipId' => $scope['membershipId'],
            'contactId' => $scope['contactId'],
            'chatwootAccountCrmId' => $scope['chatwootAccountCrmId'],
        ];
        $tenantId = $scope['tenantId'] ?? 'crm-global';
        $tenant = $kind === CatalogScope::KIND_CRM_GLOBAL
            ? $this->syntheticGlobalTenant()
            : $this->requireTenant((string) $scope['tenantId']);

        $name = isset($data->name) ? (string) $data->name : '';
        $name = $this->assertModeName($name);
        $payload = $this->buildWritePayload($data);

        try {
            $response = $this->backendApiClient->request(
                'POST',
                '/agentbox/modes',
                $authToken,
                $authTokenSecret,
                CatalogScope::toBackendQuery($tenantId, $scopeOpts, $this->user),
                array_merge(['name' => $name], $payload)
            );
        } catch (Error $e) {
            if ($e->getCode() === 409) {
                throw new BadRequest("Mode '{$name}' already exists.");
            }
            throw $e;
        }

        $result = $response['body'] ?? null;
        if (!is_array($result)) {
            throw new Error('Invalid mode payload from backend.');
        }

        return $this->mapToEntity($result, $tenant, $kind, $scope['entityRef'])->getValueMap();
    }

    /**
     * @throws Forbidden
     * @throws BadRequest
     * @throws NotFound
     * @throws Error
     */
    public function update(
        string $tenantId,
        string $modeName,
        stdClass $data,
        string $authToken,
        string $authTokenSecret,
        array $scopeOpts = []
    ): stdClass {
        $this->assertScope('edit');
        $kind = CatalogScope::normalizeKind($scopeOpts['workspaceKind'] ?? CatalogScope::KIND_USER);
        CatalogAuth::assertCanWriteCatalog(
            $this->user,
            $this->entityManager,
            $kind,
            is_string($scopeOpts['targetUserId'] ?? null) ? (string) $scopeOpts['targetUserId'] : null
        );
        $tenant = $kind === CatalogScope::KIND_CRM_GLOBAL
            ? $this->syntheticGlobalTenant()
            : $this->requireTenant($tenantId);
        $modeName = $this->assertModeName($modeName);
        $payload = $this->buildWritePayload($data);

        try {
            $response = $this->backendApiClient->request(
                'PUT',
                '/agentbox/modes/' . rawurlencode($modeName),
                $authToken,
                $authTokenSecret,
                CatalogScope::toBackendQuery($tenantId, array_merge($scopeOpts ?? [], [
                    'workspaceKind' => $kind ?? CatalogScope::KIND_USER,
                ]), $this->user),
                $payload
            );
        } catch (Error $e) {
            if ($e->getCode() === 404) {
                throw new NotFound("Mode '{$modeName}' not found.");
            }
            throw $e;
        }

        $result = $response['body'] ?? null;
        if (!is_array($result)) {
            throw new Error('Invalid mode payload from backend.');
        }

        return $this->mapToEntity(
            $result,
            $tenant,
            $kind,
            $this->entityRefFromOpts($kind, $scopeOpts)
        )->getValueMap();
    }

    /**
     * @throws Forbidden
     * @throws BadRequest
     * @throws NotFound
     * @throws Error
     */
    public function delete(
        string $tenantId,
        string $modeName,
        string $authToken,
        string $authTokenSecret,
        array $scopeOpts = []
    ): void {
        $this->assertScope('delete');
        $kind = CatalogScope::normalizeKind($scopeOpts['workspaceKind'] ?? CatalogScope::KIND_USER);
        CatalogAuth::assertCanWriteCatalog(
            $this->user,
            $this->entityManager,
            $kind,
            is_string($scopeOpts['targetUserId'] ?? null) ? (string) $scopeOpts['targetUserId'] : null
        );
        if ($kind !== CatalogScope::KIND_CRM_GLOBAL) {
            $this->requireTenant($tenantId);
        }
        $modeName = $this->assertModeName($modeName);

        try {
            $this->backendApiClient->request(
                'DELETE',
                '/agentbox/modes/' . rawurlencode($modeName),
                $authToken,
                $authTokenSecret,
                CatalogScope::toBackendQuery($tenantId, array_merge($scopeOpts ?? [], [
                    'workspaceKind' => $kind ?? CatalogScope::KIND_USER,
                ]), $this->user)
            );
        } catch (Error $e) {
            if ($e->getCode() === 404) {
                throw new NotFound("Mode '{$modeName}' not found.");
            }
            throw $e;
        }
    }

    /**
     * Composite id: {tenantId}_{modeName}
     *
     * @return array{0: string, 1: string}
     * @throws BadRequest
     */
    public function parseId(string $id): array
    {
        $parsed = CatalogScope::parseCompositeId($id);
        $this->assertModeName($parsed['name']);

        return [
            $parsed['tenantId'],
            $parsed['name'],
            [
                'workspaceKind' => $parsed['workspaceKind'],
                'targetUserId' => $parsed['targetUserId'],
                'membershipId' => $parsed['membershipId'],
                'contactId' => $parsed['contactId'],
                'chatwootAccountCrmId' => $parsed['chatwootAccountCrmId'],
            ],
        ];
    }

    public function buildId(
        string $tenantId,
        string $modeName,
        string $workspaceKind = CatalogScope::KIND_USER,
        string $entityRef = '-'
    ): string {
        return CatalogScope::buildCompositeId($tenantId, $workspaceKind, $entityRef, $modeName);
    }

    /**
     * @return array{model: ?string, temperature: ?float, tools: array<string, bool>, body: string}
     * @throws BadRequest
     */
    private function buildWritePayload(stdClass $data): array
    {
        $model = null;
        if (isset($data->model) && is_string($data->model) && trim($data->model) !== '') {
            $model = trim($data->model);
            if (strlen($model) > 256) {
                throw new BadRequest('model must be at most 256 characters.');
            }
        }

        $temperature = null;
        if (isset($data->temperature) && $data->temperature !== '' && $data->temperature !== null) {
            if (!is_numeric($data->temperature)) {
                throw new BadRequest('temperature must be a number between 0 and 2.');
            }
            $temperature = (float) $data->temperature;
            if ($temperature < 0 || $temperature > 2) {
                throw new BadRequest('temperature must be a number between 0 and 2.');
            }
        }

        $tools = $this->normalizeTools($data->tools ?? null);
        $body = isset($data->body) ? (string) $data->body : '';

        return [
            'model' => $model,
            'temperature' => $temperature,
            'tools' => $tools,
            'body' => $body,
        ];
    }

    /**
     * @return array<string, bool>
     * @throws BadRequest
     */
    private function normalizeTools(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed === '') {
                return [];
            }
            $decoded = json_decode($trimmed, true);
            if (!is_array($decoded)) {
                throw new BadRequest('tools must be a JSON object of boolean flags.');
            }
            $raw = $decoded;
        }

        if ($raw instanceof stdClass) {
            $raw = (array) $raw;
        }

        if (!is_array($raw)) {
            throw new BadRequest('tools must be a JSON object of boolean flags.');
        }

        $out = [];
        foreach ($raw as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (!is_bool($value)) {
                throw new BadRequest("tools.{$key} must be a boolean.");
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */

    private function mapToEntity(
        array $data,
        Entity $tenant,
        string $workspaceKind = CatalogScope::KIND_USER,
        string $entityRef = '-'
    ): Entity {
        $name = isset($data['name']) ? (string) $data['name'] : '';
        $entity = $this->entityManager->getNewEntity(self::ENTITY_TYPE);

        $entity->set('id', $this->buildId((string) $tenant->getId(), $name, $workspaceKind, $entityRef));
        $entity->set('name', $name);
        $entity->set('model', $data['model'] ?? null);
        $entity->set('temperature', $data['temperature'] ?? null);
        $tools = $data['tools'] ?? [];
        if (is_array($tools)) {
            $entity->set('tools', json_encode($tools, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $entity->set('tools', is_string($tools) ? $tools : '{}');
        }
        $entity->set('body', (string) ($data['body'] ?? ''));
        $entity->set('content', (string) ($data['content'] ?? ''));
        $entity->set('path', (string) ($data['path'] ?? ''));
        $entity->set('modifiedAt', $data['modifiedAt'] ?? null);
        $entity->set('tenantId', $tenant->getId());
        $entity->set('tenantName', (string) $tenant->get('name'));
        $entity->set('workspaceKind', $workspaceKind);
        $entity->set('entityRef', $entityRef);

        $entity->setAsFetched();

        return $entity;
    }

    /**
     * @param array<string, mixed> $scopeOpts
     */
    private function entityRefFromOpts(string $kind, array $scopeOpts): string
    {
        if ($kind === CatalogScope::KIND_USER) {
            $uid = $scopeOpts['targetUserId'] ?? null;
            if (!is_string($uid) || $uid === '') {
                $uid = $this->user->getId();
            }

            return $uid;
        }

        if ($kind === CatalogScope::KIND_MEMBERSHIP) {
            return (string) ($scopeOpts['membershipId'] ?? '-');
        }

        if ($kind === CatalogScope::KIND_CONTACT) {
            $aid = (string) ($scopeOpts['chatwootAccountCrmId'] ?? '');
            $cid = (string) ($scopeOpts['contactId'] ?? '');

            return $aid . '~' . $cid;
        }

        return '-';
    }

    private function syntheticGlobalTenant(): Entity
    {
        $tenant = $this->entityManager->getNewEntity('Tenant');
        $tenant->set('id', 'crm-global');
        $tenant->set('name', 'CRM Global');

        return $tenant;
    }

    private function extractTenantId(stdClass $data): string
    {
        if (isset($data->tenantId) && is_string($data->tenantId) && $data->tenantId !== '') {
            return $data->tenantId;
        }

        if (isset($data->tenant) && is_string($data->tenant) && $data->tenant !== '') {
            return $data->tenant;
        }

        throw new BadRequest('tenant (or tenantId) is required.');
    }

    /**
     * @throws Forbidden
     * @throws NotFound
     * @throws BadRequest
     */
    private function requireTenant(string $tenantId): Entity
    {
        if ($tenantId === '') {
            throw new BadRequest('tenantId is required.');
        }

        $tenant = $this->entityManager->getEntityById('Tenant', $tenantId);
        if (!$tenant) {
            throw new NotFound("Tenant '{$tenantId}' not found.");
        }

        if (!$this->acl->check($tenant, 'read')) {
            throw new Forbidden("No access to Tenant '{$tenantId}'.");
        }

        return $tenant;
    }

    /**
     * @throws BadRequest
     */
    private function assertModeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 64 || !preg_match(self::MODE_NAME_RE, $name)) {
            throw new BadRequest(
                'Mode name must be 1–64 chars, lowercase alphanumeric with single hyphens.'
            );
        }

        return $name;
    }

    /**
     * @throws Forbidden
     */
    private function assertScope(string $action): void
    {
        if (!$this->acl->checkScope(self::ENTITY_TYPE, $action)) {
            throw new Forbidden("No {$action} access to AgentMode.");
        }
    }
}
