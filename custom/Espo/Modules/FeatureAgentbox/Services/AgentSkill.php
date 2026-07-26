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
     * List skills for one tenant, or (when $tenantId is null) every Tenant the
     * logged-in user is a member of (User.tenants — same membership as backend
     * listAccessibleCrmTenants).
     *
     * @throws Forbidden
     * @throws BadRequest
     * @throws Error
     */
    public function find(
        ?string $tenantId,
        string $authToken,
        string $authTokenSecret
    ): RecordCollection {
        $this->assertScope('read');

        $tenants = $tenantId !== null && $tenantId !== ''
            ? [$this->requireTenant($tenantId)]
            : $this->resolveAccessibleTenants();

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
                    '/agentbox/skills',
                    $authToken,
                    $authTokenSecret,
                    ['crmTenantId' => $tid]
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

            foreach ($list as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $collection->append($this->mapToEntity($item, $tenant));
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
        string $authTokenSecret
    ): stdClass {
        $this->assertScope('read');
        $tenant = $this->requireTenant($tenantId);
        $skillName = $this->assertSkillName($skillName);

        try {
            $response = $this->backendApiClient->request(
                'GET',
                '/agentbox/skills/' . rawurlencode($skillName),
                $authToken,
                $authTokenSecret,
                ['crmTenantId' => $tenantId]
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

        return $this->mapToEntity($body, $tenant)->getValueMap();
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

        $tenantId = $this->extractTenantId($data);
        $tenant = $this->requireTenant($tenantId);

        $name = isset($data->name) ? (string) $data->name : '';
        $name = $this->assertSkillName($name);
        $description = isset($data->description) ? trim((string) $data->description) : '';
        $body = isset($data->body) ? (string) $data->body : '';

        if ($description === '') {
            throw new BadRequest('description is required.');
        }

        try {
            $response = $this->backendApiClient->request(
                'POST',
                '/agentbox/skills',
                $authToken,
                $authTokenSecret,
                ['crmTenantId' => $tenantId],
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

        return $this->mapToEntity($payload, $tenant)->getValueMap();
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
        string $authTokenSecret
    ): stdClass {
        $this->assertScope('edit');
        $tenant = $this->requireTenant($tenantId);
        $skillName = $this->assertSkillName($skillName);

        $description = isset($data->description) ? trim((string) $data->description) : '';
        $body = isset($data->body) ? (string) $data->body : '';

        if ($description === '') {
            throw new BadRequest('description is required.');
        }

        try {
            $response = $this->backendApiClient->request(
                'PUT',
                '/agentbox/skills/' . rawurlencode($skillName),
                $authToken,
                $authTokenSecret,
                ['crmTenantId' => $tenantId],
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

        return $this->mapToEntity($payload, $tenant)->getValueMap();
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
        string $authTokenSecret
    ): void {
        $this->assertScope('delete');
        $this->requireTenant($tenantId);
        $skillName = $this->assertSkillName($skillName);

        try {
            $this->backendApiClient->request(
                'DELETE',
                '/agentbox/skills/' . rawurlencode($skillName),
                $authToken,
                $authTokenSecret,
                ['crmTenantId' => $tenantId]
            );
        } catch (Error $e) {
            if ($e->getCode() === 404) {
                throw new NotFound("Skill '{$skillName}' not found.");
            }
            throw $e;
        }
    }

    /**
     * Composite id: {tenantId}_{skillName}
     *
     * @return array{0: string, 1: string}
     * @throws BadRequest
     */
    public function parseId(string $id): array
    {
        $pos = strpos($id, '_');
        if ($pos === false || $pos === 0 || $pos === strlen($id) - 1) {
            throw new BadRequest("Invalid AgentSkill id. Expected '{tenantId}_{skillName}'.");
        }

        $tenantId = substr($id, 0, $pos);
        $skillName = substr($id, $pos + 1);

        if ($tenantId === '' || $skillName === '') {
            throw new BadRequest("Invalid AgentSkill id. Expected '{tenantId}_{skillName}'.");
        }

        $this->assertSkillName($skillName);

        return [$tenantId, $skillName];
    }

    public function buildId(string $tenantId, string $skillName): string
    {
        return $tenantId . '_' . $skillName;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mapToEntity(array $data, Entity $tenant): Entity
    {
        $name = isset($data['name']) ? (string) $data['name'] : '';
        $entity = $this->entityManager->getNewEntity(self::ENTITY_TYPE);

        $entity->set('id', $this->buildId($tenant->getId(), $name));
        $entity->set('name', $name);
        $entity->set('description', (string) ($data['description'] ?? ''));
        $entity->set('body', (string) ($data['body'] ?? ''));
        $entity->set('content', (string) ($data['content'] ?? ''));
        $entity->set('path', (string) ($data['path'] ?? ''));
        $entity->set('modifiedAt', $data['modifiedAt'] ?? null);
        $entity->set('tenantId', $tenant->getId());
        $entity->set('tenantName', (string) $tenant->get('name'));

        $entity->setAsFetched();

        return $entity;
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
