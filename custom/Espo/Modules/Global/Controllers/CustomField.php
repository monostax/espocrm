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
use Espo\Core\Record\ServiceContainer;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\CustomField\ConditionSchema;
use Espo\Modules\Global\Tools\CustomField\MetaProvider;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
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
 *   GET CustomField/action/conditionAttributes?entityType=Account
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
        private UserTenantResolver $userTenantResolver,
        private EntityManager $entityManager,
        private Acl $acl,
        private User $user,
        private ConditionSchema $conditionSchema,
        private ServiceContainer $serviceContainer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getActionMeta(Request $request, Response $response): array
    {
        $entityType = $this->requireEnabledEntityType($request);

        $tenantId = $this->resolveTenantId($request, $entityType);

        $meta = $this->metaProvider->getGroupedMeta($entityType, $tenantId);
        $meta['canCreate'] = $this->canCreateDef($tenantId);

        return $meta;
    }

    /**
     * @return array<string, mixed>
     */
    public function postActionCreate(Request $request, Response $response): array
    {
        $data = $request->getParsedBody();
        $bodyEntityType = is_string($data->entityType ?? null) ? $data->entityType : null;
        $entityType = $this->requireEnabledEntityType($request, $bodyEntityType);

        $tenantId = $this->resolveTenantId($request, $entityType, $data);

        if ($tenantId === null || $tenantId === '' || !$this->canCreateDef($tenantId)) {
            throw new Forbidden('No permission to create custom fields.');
        }

        $label = is_string($data->label ?? null) ? trim($data->label) : '';

        if ($label === '') {
            throw new BadRequest('label is required.');
        }

        $label = mb_substr($label, 0, 100);

        $type = is_string($data->type ?? null) ? trim($data->type) : '';

        if ($type === '' || !in_array($type, $this->metaProvider->getAllowedTypes(), true)) {
            throw new BadRequest("Invalid custom field type '{$type}'.");
        }

        $options = [];

        if (in_array($type, ['enum', 'multiEnum'], true)) {
            $rawOptions = $data->options ?? null;

            if (is_object($rawOptions)) {
                $rawOptions = array_values((array) $rawOptions);
            }

            if (is_array($rawOptions)) {
                foreach ($rawOptions as $opt) {
                    if (is_string($opt) || is_int($opt) || is_float($opt)) {
                        $trimmed = trim((string) $opt);

                        if ($trimmed !== '' && !in_array($trimmed, $options, true)) {
                            $options[] = $trimmed;
                        }
                    }
                }
            }

            if ($options === []) {
                throw new BadRequest('options must not be empty for enum/multiEnum fields.');
            }
        }

        [$name, $sortOrder] = $this->generateUniqueFieldNameAndSortOrder($tenantId, $entityType, $label);
        $teamIds = $this->resolveTenantTeamIds($tenantId);

        $payload = (object) [
            'entityType' => $entityType,
            'name' => $name,
            'valueKey' => $name,
            'label' => $label,
            'type' => $type,
            'teamsIds' => $teamIds,
            'tenantId' => $tenantId,
            'sortOrder' => $sortOrder,
            'isActive' => true,
            'isRequired' => false,
            'isTemplateEnabled' => true,
        ];

        if (in_array($type, ['enum', 'multiEnum'], true)) {
            $payload->options = $options;
        }

        $entity = $this->serviceContainer->get('CustomFieldDef')->create($payload)->getEntity();

        $meta = $this->metaProvider->getGroupedMeta($entityType, $tenantId);
        $meta['canCreate'] = $this->canCreateDef($tenantId);

        return [
            'field' => [
                'id' => (string) $entity->getId(),
                'name' => (string) $entity->get('name'),
                'label' => (string) $entity->get('label'),
                'type' => (string) $entity->get('type'),
                'valueKey' => (string) $entity->get('valueKey'),
                'options' => $options,
                'isRequired' => (bool) $entity->get('isRequired'),
                'sortOrder' => (int) ($entity->get('sortOrder') ?? $sortOrder),
            ],
            'meta' => $meta,
        ];
    }

    public function getActionConditionAttributes(Request $request, Response $response): array
    {
        $entityType = $this->requireEnabledEntityType($request);
        $attributes = $this->conditionSchema->getAttributes($entityType);
        $attributes = array_filter($attributes, fn ($item) => $this->acl->checkField($entityType, $item['field'], 'read'));
        return ['attributes' => (object) $attributes];
    }

    /**
     * Flat template-enabled fields for Email / WhatsApp pickers.
     *
     * When a single tenant resolves (explicit tenantId / host teams / user team),
     * returns that tenant's fields. When none resolves, unions fields across all
     * mon tenants the current user can see (admin = every tenant; others = tenants
     * reachable from their teams). Email Template builder has no host record, so
     * this is what populates Insert Field for every enabled entity.
     *
     * @return array{
     *     entityType: string,
     *     tenantId: ?string,
     *     tenantIds: list<string>,
     *     list: list<array<string, mixed>>
     * }
     */
    public function getActionTemplateVariables(Request $request, Response $response): array
    {
        $entityType = $this->requireEnabledEntityType($request);

        $tenantId = $this->resolveTenantId($request, $entityType);

        if ($tenantId !== null && $tenantId !== '') {
            return [
                'entityType' => $entityType,
                'tenantId' => $tenantId,
                'tenantIds' => [$tenantId],
                'list' => $this->metaProvider->getTemplateVariables($entityType, $tenantId),
            ];
        }

        $tenantIds = $this->resolveAccessibleTenantIds();

        return [
            'entityType' => $entityType,
            'tenantId' => null,
            'tenantIds' => $tenantIds,
            'list' => $this->metaProvider->getTemplateVariablesForTenants($entityType, $tenantIds),
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

    private function requireEnabledEntityType(Request $request, ?string $fallback = null): string
    {
        $entityType = $fallback ?? $request->getQueryParam('entityType');

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

    private function resolveTenantId(Request $request, string $entityType, ?object $data = null): ?string
    {
        $explicit = $data->tenantId ?? $request->getQueryParam('tenantId');

        if (is_string($explicit) && trim($explicit) !== '') {
            $tenantId = trim($explicit);

            // An explicitly-supplied tenantId is caller-controlled input and
            // MUST be authorized. Without this, any user able to read the host
            // entity could pass an arbitrary tenantId and receive another
            // tenant's entire custom-field schema — bypassing CustomFieldDef
            // ACL by design (meta is gated on host-entity read, not schema
            // admin). The team-derived branch below was already constrained to
            // the user's own teams; this branch was not.
            if (!$this->user->isAdmin()) {
                $allowed = array_flip($this->resolveAccessibleTenantIds());

                if (!isset($allowed[$tenantId])) {
                    throw new Forbidden('No access to the requested tenant.');
                }
            }

            return $tenantId;
        }

        $rawAccountId = $data->accountId ?? $request->getQueryParam('accountId');
        $accountId = filter_var($rawAccountId, FILTER_VALIDATE_INT);

        if ($accountId && $accountId > 0) {
            $accounts = $this->entityManager
                ->getRDBRepository('ChatwootAccount')
                ->where(['chatwootAccountId' => $accountId])
                ->limit(0, 2)
                ->find();

            if (count($accounts) !== 1) {
                throw new BadRequest('Workspace not found.');
            }

            $account = iterator_to_array($accounts, false)[0];
            $accountTenantId = $account->get('tenantId');

            if (
                !is_string($accountTenantId) ||
                trim($accountTenantId) === '' ||
                !$account->get('platformId') ||
                (!$this->user->isAdmin() && (
                    !$this->acl->checkEntityRead($account) ||
                    !$this->userTenantResolver->canActForTenant($this->user, trim($accountTenantId))
                ))
            ) {
                throw new Forbidden('No access to the requested workspace.');
            }

            return trim($accountTenantId);
        }

        $teamIds = $this->parseTeamIds($data->teamIds ?? $request->getQueryParam('teamIds'));

        // Prefer client-supplied record tenant or record teams.
        if ($teamIds === []) {
            $recordId = $data->recordId ?? $request->getQueryParam('recordId');

            if (is_string($recordId) && $recordId !== '') {
                $recordTenantId = $this->loadRecordTenantId($entityType, $recordId);

                if ($recordTenantId !== null) {
                    return $recordTenantId;
                }

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

    private function loadRecordTenantId(string $entityType, string $recordId): ?string
    {
        $entity = $this->entityManager->getEntityById($entityType, $recordId);

        if (!$entity) {
            return null;
        }

        if (!$this->user->isAdmin() && !$this->acl->check($entity, 'read')) {
            return null;
        }

        $tenantId = $entity->get('tenantId');

        if (!is_string($tenantId) || trim($tenantId) === '') {
            return null;
        }

        return trim($tenantId);
    }

    private function canCreateDef(?string $tenantId): bool
    {
        if ($tenantId === null || $tenantId === '') {
            return false;
        }

        if (!$this->user->isAdmin() && !$this->acl->checkScope('CustomFieldDef', 'create')) {
            return false;
        }

        return $this->resolveTenantTeamIds($tenantId) !== [];
    }

    /**
     * @return list<string>
     */
    private function resolveTenantTeamIds(string $tenantId): array
    {
        if ($tenantId === '') {
            return [];
        }

        if (!$this->user->isAdmin() && !$this->userTenantResolver->canActForTenant($this->user, $tenantId)) {
            return [];
        }

        $tenant = $this->entityManager->getEntityById('Tenant', $tenantId);

        if (!$tenant) {
            return [];
        }

        $baseTeamId = $tenant->get('baseUserTeamId');
        $baseTeamId = is_string($baseTeamId) && trim($baseTeamId) !== '' ? trim($baseTeamId) : null;

        if ($this->user->isAdmin()) {
            if ($baseTeamId !== null) {
                return [$baseTeamId];
            }

            if ($tenant instanceof CoreEntity) {
                try {
                    $otherIds = $tenant->getLinkMultipleIdList('otherUserTeams');

                    if (is_array($otherIds) && $otherIds !== []) {
                        return [(string) $otherIds[0]];
                    }
                } catch (\Throwable) {
                }
            }

            return [];
        }

        $userTeams = array_flip($this->getUserTeamIds());

        if ($baseTeamId !== null && isset($userTeams[$baseTeamId])) {
            return [$baseTeamId];
        }

        if ($tenant instanceof CoreEntity) {
            try {
                $otherIds = $tenant->getLinkMultipleIdList('otherUserTeams');

                if (is_array($otherIds)) {
                    foreach ($otherIds as $id) {
                        if (is_string($id) && $id !== '' && isset($userTeams[$id])) {
                            return [$id];
                        }
                    }
                }
            } catch (\Throwable) {
            }
        }

        return $baseTeamId !== null ? [$baseTeamId] : [];
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function generateUniqueFieldNameAndSortOrder(
        string $tenantId,
        string $entityType,
        string $label,
    ): array {
        $base = $this->slugifyLabel($label);

        $existing = $this->entityManager
            ->getRDBRepository('CustomFieldDef')
            ->where([
                'tenantId' => $tenantId,
                'entityType' => $entityType,
            ])
            ->find();

        $taken = [];
        $maxSortOrder = 0;

        foreach ($existing as $record) {
            $existingName = $record->get('name');
            $existingKey = $record->get('valueKey');
            $sortOrder = (int) ($record->get('sortOrder') ?? 0);

            if ($sortOrder > $maxSortOrder) {
                $maxSortOrder = $sortOrder;
            }

            if (is_string($existingName) && $existingName !== '') {
                $taken[$existingName] = true;
            }

            if (is_string($existingKey) && $existingKey !== '') {
                $taken[$existingKey] = true;
            }
        }

        $candidate = $base;
        $suffix = 2;

        while (isset($taken[$candidate])) {
            $candidate = $base . $suffix;
            $suffix++;
        }

        return [$candidate, $maxSortOrder + 10];
    }

    private function slugifyLabel(string $label): string
    {
        $translit = strtr($label, [
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ª' => 'a',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'º' => 'o',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'Ç' => 'C', 'ç' => 'c',
            'Ñ' => 'N', 'ñ' => 'n',
            'Ý' => 'Y', 'ý' => 'y', 'ÿ' => 'y',
            'ß' => 'ss',
        ]);

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $translit);

            if (is_string($converted) && $converted !== '') {
                $translit = $converted;
            }
        }

        $tokens = preg_split('/[^a-zA-Z0-9]+/', $translit, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $camel = '';

        foreach ($tokens as $token) {
            if ($camel === '') {
                $cleaned = preg_replace('/^[^a-zA-Z]+/', '', $token) ?? '';

                if ($cleaned === '') {
                    continue;
                }

                $camel = strtolower($cleaned);
            } else {
                $camel .= ucfirst(strtolower($token));
            }
        }

        $base = substr($camel, 0, 50);

        if ($base === '' || !preg_match('/^[a-z][a-zA-Z0-9_]*$/', $base)) {
            return 'field';
        }

        return $base;
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

    /**
     * Tenants whose CF schema the current user may surface in template pickers,
     * and the authorisation set for an explicitly-supplied `tenantId`.
     *
     * - Admin / system: every active Tenant.
     * - Others: every tenant the user can act for, via UserTenantResolver.
     *
     * This used to derive tenants from teams ONLY, which disagreed with
     * RunAsUserAccess::getUserTenantIds() — a user explicitly linked to a tenant
     * through `tenantUser` but not a member of any of its teams was refused
     * there yet allowed here. Both now resolve the same union.
     *
     * @return list<string>
     */
    private function resolveAccessibleTenantIds(): array
    {
        if ($this->user->isAdmin()) {
            $collection = $this->entityManager
                ->getRDBRepository('Tenant')
                ->select(['id'])
                ->find();

            $ids = [];

            foreach ($collection as $tenant) {
                $id = $tenant->getId();

                if (is_string($id) && $id !== '') {
                    $ids[] = $id;
                }
            }

            return array_values(array_unique($ids));
        }

        return $this->userTenantResolver->resolveTenantIds($this->user);
    }
}
