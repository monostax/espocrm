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

namespace Espo\Modules\FeatureMcp\Tools\SavedFilters;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\OpportunityBulkPostAccess;
use Espo\Modules\FeatureMcp\Tools\Query\QueryableTypes;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Saved filters (RecordFilter) over MCP. In Monostax a filter lives in one workspace and shows to its owner, or to
 * everyone there when shared (Chatwoot RecordFilters::visible/find). An MCP call has no workspace, so a filter shows
 * when that same rule holds in a workspace the user may act in (OpportunityBulkPostAccess::workspace, the gate the
 * filter panel uses) and the user may read its record type. Anything else does not exist for them.
 */
class SavedFilterAccess
{
    private const int LIMIT = 500;

    /** @var array<string, ?Entity> workspace by ChatwootAccount id, null when refused */
    private array $workspaces = [];

    public function __construct(
        private EntityManager $entityManager,
        private OpportunityBulkPostAccess $workspaceAccess,
        private User $user,
        private QueryableTypes $types,
        private ConditionWhere $conditionWhere,
    ) {}

    /**
     * @return list<object{id: string, name: string, entityType: string, workspace: string, shared: bool, mine: bool}>
     */
    public function list(?string $entityType = null): array
    {
        $types = $this->types();

        if ($entityType !== null) {
            $types = array_values(array_intersect($types, [$entityType]));
        }

        if (!$types) {
            return [];
        }

        $filters = $this->entityManager
            ->getRDBRepository('RecordFilter')
            ->where([
                'entityType' => $types,
                'OR' => [['userId' => $this->user->getId()], ['shared' => true]],
            ])
            ->order('name')
            ->limit(0, self::LIMIT)
            ->find();

        $list = [];

        foreach ($filters as $filter) {
            $workspace = $this->workspaceOf($filter);

            if (!$workspace) {
                continue;
            }

            $list[] = (object) [
                'id' => $filter->getId(),
                'name' => (string) $filter->get('name'),
                'entityType' => (string) $filter->get('entityType'),
                'workspace' => (string) $workspace->get('name'),
                'shared' => (bool) $filter->get('shared'),
                'mine' => $filter->get('userId') === $this->user->getId(),
            ];
        }

        return $list;
    }

    /**
     * The filter's conditions as where items for a list of $entityType.
     *
     * @return list<array<string, mixed>>
     * @throws NotFound
     */
    public function where(string $entityType, string $id, ?string $timeZone): array
    {
        $filter = preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $id)
            ? $this->entityManager->getEntityById('RecordFilter', $id)
            : null;

        if (
            !$filter ||
            $filter->get('entityType') !== $entityType ||
            !in_array($entityType, $this->types(), true) ||
            ($filter->get('userId') !== $this->user->getId() && !$filter->get('shared')) ||
            !$this->workspaceOf($filter)
        ) {
            throw new NotFound("Saved filter not found.");
        }

        $definition = $filter->get('definition');
        $conditions = is_object($definition) ? ($definition->conditions ?? []) : [];

        return $this->conditionWhere->build($entityType, $conditions, $timeZone);
    }

    /** @return string[] */
    private function types(): array
    {
        return array_values(array_filter(
            $this->types->readable(),
            fn (string $type) => ConditionWhere::supports($type)
        ));
    }

    private function workspaceOf(Entity $filter): ?Entity
    {
        $accountId = (string) $filter->get('accountId');

        if (!array_key_exists($accountId, $this->workspaces)) {
            $this->workspaces[$accountId] = $this->resolveWorkspace($accountId);
        }

        $workspace = $this->workspaces[$accountId];

        return $workspace && $workspace->get('tenantId') === $filter->get('tenantId') ? $workspace : null;
    }

    private function resolveWorkspace(string $accountId): ?Entity
    {
        $account = $accountId !== '' ? $this->entityManager->getEntityById('ChatwootAccount', $accountId) : null;
        $chatwootId = $account ? (int) $account->get('chatwootAccountId') : 0;

        if ($chatwootId <= 0) {
            return null;
        }

        try {
            $workspace = $this->workspaceAccess->workspace($chatwootId);
        } catch (Forbidden|NotFound) {
            return null;
        }

        return $workspace->getId() === $account->getId() ? $workspace : null;
    }
}
