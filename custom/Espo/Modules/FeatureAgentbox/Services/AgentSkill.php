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
 * Virtual Record service: AgentSkill ↔ agentbox workspace OpenCode skills FS.
 */
class AgentSkill
{
    public const ENTITY_TYPE = 'AgentSkill';

    private const SKILL_NAME_RE = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    public function __construct(
        private EntityManager $entityManager,
        private BackendApiClient $backendApiClient,
        private Acl $acl,
        private User $user
    ) {}

    /**
     * List skills for one catalog scope.
     *
     * Default workspaceKind=user (caller's user workspace). Pass workspaceKind +
     * entity selectors to list tenant-shared / membership / contact / crm-global.
     *
     * @param array{
     *   workspaceKind?: string|null,
     *   targetUserId?: string|null,
     *   membershipId?: string|null,
     *   contactId?: string|null,
     *   chatwootAccountCrmId?: string|null
     * } $scopeOpts
     *
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

        if ($kind === CatalogScope::KIND_CRM_GLOBAL) {
            $tenantPlaceholder = $this->entityManager->getNewEntity('Tenant');
            $tenantPlaceholder->set('id', 'crm-global');
            $tenantPlaceholder->set('name', 'CRM Global');
            $tenants = [$tenantPlaceholder];
        } else {
            $tenants = $tenantId !== null && $tenantId !== ''
                ? [$this->requireTenant($tenantId)]
                : $this->resolveAccessibleTenants();
        }

        $collection = $this->entityManager->getCollectionFactory()->create(self::ENTITY_TYPE);
        $total = 0;

        foreach ($tenants as $tenant) {
            $tid = $tenant->getId();
            if (!is_string($tid) || $tid === '') {
                continue;
            }

            try {
                $query = CatalogScope::toBackendQuery($tid, array_merge($scopeOpts, [
                    'workspaceKind' => $kind,
                ]), $this->user);

                $response = $this->backendApiClient->request(
                    'GET',
                    '/agentbox/skills',
                    $authToken,
                    $authTokenSecret,
                    $query
                );
            } catch (Throwable $e) {
                // One workspace offline must not blank the whole multi-tenant list.
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
                    throw new Error('Invalid skills list from backend.');
                }

                continue;
            }

            $entityRef = $this->entityRefFromOpts($kind, $scopeOpts);

            foreach ($list as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $collection->append($this->mapToEntity($item, $tenant, $kind, $entityRef));
                $total++;
            }
        }

        return RecordCollection::create($collection, $total);
    }

    /**
     * Tenants the current user may list skills for.
     * Membership = User.tenants relation (tenantUser), ACL-filtered.
     * Admins with no membership rows still see every Tenant they can read.
     *
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
            // fall through to admin / empty
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
        string $skillName,
        string $authToken,
        string $authTokenSecret,
        array $scopeOpts = []
    ): stdClass {
        $this->assertScope('read');
        $kind = CatalogScope::normalizeKind($scopeOpts['workspaceKind'] ?? CatalogScope::KIND_USER);
        $tenant = $kind === CatalogScope::KIND_CRM_GLOBAL
            ? $this->syntheticGlobalTenant()
            : $this->requireTenant($tenantId);
        $skillName = $this->assertSkillName($skillName);

        $query = CatalogScope::toBackendQuery($tenantId, array_merge($scopeOpts, [
            'workspaceKind' => $kind,
        ]), $this->user);

        try {
            $response = $this->backendApiClient->request(
                'GET',
                '/agentbox/skills/' . rawurlencode($skillName),
                $authToken,
                $authTokenSecret,
                $query
            );
        } catch (Error $e) {
            if ($e->getCode() === 404) {
                throw new NotFound("Skill '{$skillName}' not found.");
            }
            throw $e;
        }

        $body = $response['body'] ?? null;
        if (!is_array($body)) {
            throw new Error('Invalid skill payload from backend.');
        }

        $entityRef = $this->entityRefFromOpts($kind, $scopeOpts);

        return $this->mapToEntity($body, $tenant, $kind, $entityRef)->getValueMap();
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
        $tenantId = $scope['tenantId'] ?? 'crm-global';
        $tenant = $kind === CatalogScope::KIND_CRM_GLOBAL
            ? $this->syntheticGlobalTenant()
            : $this->requireTenant((string) $scope['tenantId']);

        $name = isset($data->name) ? (string) $data->name : '';
        $name = $this->assertSkillName($name);
        $description = isset($data->description) ? trim((string) $data->description) : '';
        $body = isset($data->body) ? (string) $data->body : '';

        if ($description === '') {
            throw new BadRequest('description is required.');
        }

        $query = CatalogScope::toBackendQuery($tenantId, [
            'workspaceKind' => $kind,
            'targetUserId' => $scope['targetUserId'],
            'membershipId' => $scope['membershipId'],
            'contactId' => $scope['contactId'],
            'chatwootAccountCrmId' => $scope['chatwootAccountCrmId'],
        ], $this->user);

        try {
            $response = $this->backendApiClient->request(
                'POST',
                '/agentbox/skills',
                $authToken,
                $authTokenSecret,
                $query,
                [
                    'name' => $name,
                    'description' => $description,
                    'body' => $body,
                ]
            );
        } catch (Error $e) {
            if ($e->getCode() === 409) {
                throw new BadRequest("Skill '{$name}' already exists.");
            }
            throw $e;
        }

        $payload = $response['body'] ?? null;
        if (!is_array($payload)) {
            throw new Error('Invalid skill payload from backend.');
        }

        return $this->mapToEntity(
            $payload,
            $tenant,
            $kind,
            $scope['entityRef']
        )->getValueMap();
    }

    /**
     * @throws Forbidden
     * @throws BadRequest
     * @throws NotFound
     * @throws Error
     */
    public function update(
        string $tenantId,
        string $skillName,
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
        $skillName = $this->assertSkillName($skillName);

        $description = isset($data->description) ? trim((string) $data->description) : '';
        $body = isset($data->body) ? (string) $data->body : '';

        if ($description === '') {
            throw new BadRequest('description is required.');
        }

        $query = CatalogScope::toBackendQuery($tenantId, array_merge($scopeOpts, [
            'workspaceKind' => $kind,
        ]), $this->user);

        try {
            $response = $this->backendApiClient->request(
                'PUT',
                '/agentbox/skills/' . rawurlencode($skillName),
                $authToken,
                $authTokenSecret,
                $query,
                [
                    'description' => $description,
                    'body' => $body,
                ]
            );
        } catch (Error $e) {
            if ($e->getCode() === 404) {
                throw new NotFound("Skill '{$skillName}' not found.");
            }
            throw $e;
        }

        $payload = $response['body'] ?? null;
        if (!is_array($payload)) {
            throw new Error('Invalid skill payload from backend.');
        }

        $entityRef = $this->entityRefFromOpts($kind, $scopeOpts);

        return $this->mapToEntity($payload, $tenant, $kind, $entityRef)->getValueMap();
    }

    /**
     * @throws Forbidden
     * @throws BadRequest
     * @throws NotFound
     * @throws Error
     */
    public function delete(
        string $tenantId,
        string $skillName,
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
        $skillName = $this->assertSkillName($skillName);

        $query = CatalogScope::toBackendQuery($tenantId, array_merge($scopeOpts, [
            'workspaceKind' => $kind,
        ]), $this->user);

        try {
            $this->backendApiClient->request(
                'DELETE',
                '/agentbox/skills/' . rawurlencode($skillName),
                $authToken,
                $authTokenSecret,
                $query
            );
        } catch (Error $e) {
            if ($e->getCode() === 404) {
                throw new NotFound("Skill '{$skillName}' not found.");
            }
            throw $e;
        }
    }

    /**
     * Parse composite id → [tenantId, skillName, scopeOpts]
     *
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     * @throws BadRequest
     */
    public function parseId(string $id): array
    {
        $parsed = CatalogScope::parseCompositeId($id);
        $this->assertSkillName($parsed['name']);

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
        string $skillName,
        string $workspaceKind = CatalogScope::KIND_USER,
        string $entityRef = '-'
    ): string {
        return CatalogScope::buildCompositeId($tenantId, $workspaceKind, $entityRef, $skillName);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $scopeOpts
     */
    private function mapToEntity(
        array $data,
        Entity $tenant,
        string $workspaceKind = CatalogScope::KIND_USER,
        string $entityRef = '-'
    ): Entity {
        $name = isset($data['name']) ? (string) $data['name'] : '';
        $entity = $this->entityManager->getNewEntity(self::ENTITY_TYPE);

        $entity->set(
            'id',
            $this->buildId((string) $tenant->getId(), $name, $workspaceKind, $entityRef)
        );
        $entity->set('name', $name);
        $entity->set('description', (string) ($data['description'] ?? ''));
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
    private function assertSkillName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 64 || !preg_match(self::SKILL_NAME_RE, $name)) {
            throw new BadRequest(
                'Skill name must be 1–64 chars, lowercase alphanumeric with single hyphens.'
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
            throw new Forbidden("No {$action} access to AgentSkill.");
        }
    }
}
