<?php

declare(strict_types=1);

/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Global\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Acl;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\CustomField\MetaProvider;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\ORM\EntityManager;

/**
 * Runtime custom-field APIs.
 *
 * Meta is gated by HOST entity read access, not CustomFieldDef ACL —
 * agents who can open a Contact must be able to render its custom fields
 * without holding schema-admin permissions.
 *
 *   GET CustomField/action/meta?entityType=Contact&tenantId=...
 *   GET CustomField/action/meta?entityType=Opportunity&teamIds=t1,t2
 *   GET CustomField/action/templateVariables?entityType=Contact
 *   GET CustomField/action/importVariables?entityType=Contact
 *
 * Tenant resolution when tenantId is omitted:
 *   1. teamIds query (only teams the user belongs to, unless admin)
 *   2. host record’s teams (if recordId given)
 *   3. current user’s defaultTeam / teams
 */
class CustomField
{
    public function __construct(
        private MetaProvider $metaProvider,
        private TenantResolver $tenantResolver,
        private EntityManager $entityManager,
        private Acl $acl,
        private User $user,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getActionMeta(Request $request, Response $response): array
    {
        $entityType = $this->requireEnabledEntityType($request);

        $tenantId = $this->resolveTenantId($request, $entityType);

        return $this->metaProvider->getGroupedMeta($entityType, $tenantId);
    }

    /**
     * Flat template-enabled fields for Email / WhatsApp pickers.
     *
     * @return array{entityType: string, tenantId: ?string, list: list<array<string, mixed>>}
     */
    public function getActionTemplateVariables(Request $request, Response $response): array
    {
        $entityType = $this->requireEnabledEntityType($request);

        $tenantId = $this->resolveTenantId($request, $entityType);

        return [
            'entityType' => $entityType,
            'tenantId' => $tenantId,
            'list' => $this->metaProvider->getTemplateVariables($entityType, $tenantId),
        ];
    }

    /**
     * Flat import-map attributes for CSV Import step 2 (`customFields.<valueKey>`).
     *
     * @return array{entityType: string, tenantId: ?string, list: list<array<string, mixed>>}
     */
    public function getActionImportVariables(Request $request, Response $response): array
    {
        $entityType = $this->requireEnabledEntityType($request);

        $tenantId = $this->resolveTenantId($request, $entityType);

        return [
            'entityType' => $entityType,
            'tenantId' => $tenantId,
            'list' => $this->metaProvider->getImportVariables($entityType, $tenantId),
        ];
    }

    private function requireEnabledEntityType(Request $request): string
    {
        $entityType = $request->getQueryParam('entityType');

        if (!$entityType || !is_string($entityType)) {
            throw new BadRequest('entityType is required.');
        }

        if (!$this->metaProvider->isEntityEnabled($entityType)) {
            throw new BadRequest("Entity type '{$entityType}' is not enabled for custom fields.");
        }

        if (!$this->user->isAdmin() && !$this->acl->checkScope($entityType, 'read')) {
            throw new Forbidden("No read access to {$entityType}.");
        }

        return $entityType;
    }

    /**
     * Required by Espo front controller action dispatcher.
     */
    public function checkAccess(): bool
    {
        return true;
    }

    private function resolveTenantId(Request $request, string $entityType): ?string
    {
        $explicit = $request->getQueryParam('tenantId');

        if (is_string($explicit) && trim($explicit) !== '') {
            return trim($explicit);
        }

        $teamIds = $this->parseTeamIds($request->getQueryParam('teamIds'));

        // Prefer client-supplied record teams (host model.teamsIds).
        if ($teamIds === []) {
            $recordId = $request->getQueryParam('recordId');

            if (is_string($recordId) && $recordId !== '') {
                $teamIds = $this->loadRecordTeamIds($entityType, $recordId);
            }
        }

        // Fall back to the current user's teams (default first).
        if ($teamIds === []) {
            $teamIds = $this->getUserTeamIds();
        }

        // Non-admins may only resolve via their own teams.
        if (!$this->user->isAdmin()) {
            $allowed = array_flip($this->getUserTeamIds());
            $teamIds = array_values(array_filter(
                $teamIds,
                static fn(string $id): bool => isset($allowed[$id])
            ));
        }

        if ($teamIds === []) {
            return null;
        }

        return $this->tenantResolver->resolveFromTeamIds($teamIds);
    }

    /**
     * @return list<string>
     */
    private function parseTeamIds(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/[,\s]+/', trim($raw)) ?: [];
        $out = [];

        foreach ($parts as $part) {
            if (!is_string($part)) {
                continue;
            }

            $id = trim($part);

            if ($id !== '') {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    private function loadRecordTeamIds(string $entityType, string $recordId): array
    {
        $entity = $this->entityManager->getEntityById($entityType, $recordId);

        if (!$entity) {
            return [];
        }

        // Host record read ACL — do not leak teams of inaccessible records.
        if (!$this->user->isAdmin() && !$this->acl->check($entity, 'read')) {
            return [];
        }

        if ($entity instanceof CoreEntity) {
            try {
                $ids = $entity->getLinkMultipleIdList('teams');

                if (is_array($ids) && $ids !== []) {
                    return array_values(array_unique($ids));
                }
            } catch (\Throwable) {
                // fall through
            }
        }

        $teamsIds = $entity->get('teamsIds');

        if (is_array($teamsIds) && $teamsIds !== []) {
            /** @var list<string> $clean */
            $clean = array_values(array_unique(array_filter(
                $teamsIds,
                static fn($id): bool => is_string($id) && $id !== ''
            )));

            return $clean;
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function getUserTeamIds(): array
    {
        $ids = [];

        $defaultTeamId = $this->user->get('defaultTeamId');

        if (is_string($defaultTeamId) && $defaultTeamId !== '') {
            $ids[] = $defaultTeamId;
        }

        try {
            $teamIds = $this->user->getLinkMultipleIdList('teams');

            if (is_array($teamIds)) {
                foreach ($teamIds as $id) {
                    if (is_string($id) && $id !== '') {
                        $ids[] = $id;
                    }
                }
            }
        } catch (\Throwable) {
            $teamsIds = $this->user->get('teamsIds');

            if (is_array($teamsIds)) {
                foreach ($teamsIds as $id) {
                    if (is_string($id) && $id !== '') {
                        $ids[] = $id;
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
