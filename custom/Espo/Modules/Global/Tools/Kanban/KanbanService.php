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

namespace Espo\Modules\Global\Tools\Kanban;

use Espo\Core\Acl\Table;
use Espo\Core\AclManager;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\ForbiddenSilent;
use Espo\Core\InjectableFactory;
use Espo\Core\Select\SearchParams;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Tools\Kanban\KanbanService as BaseKanbanService;
use Espo\Tools\Kanban\Orderer;
use Espo\Tools\Kanban\Result;

/**
 * Custom KanbanService that supports entity-specific Kanban classes
 * configured via metadata recordDefs.{EntityType}.kanbanClassName.
 *
 * Extends the base KanbanService to be compatible with type hints.
 */
class KanbanService extends BaseKanbanService
{
    public function __construct(
        private User $user,
        private AclManager $aclManager,
        private InjectableFactory $injectableFactory,
        private Config $config,
        private Metadata $metadata,
        private Orderer $orderer
    ) {
        parent::__construct($user, $aclManager, $injectableFactory, $config, $metadata, $orderer);
    }

    /**
     * @throws Error
     * @throws Forbidden
     * @throws BadRequest
     */
    public function getData(string $entityType, SearchParams $searchParams): Result
    {
        // Check if there's a custom Kanban class configured for this entity
        $customClassName = $this->metadata->get(['recordDefs', $entityType, 'kanbanClassName']);

        if ($customClassName && class_exists($customClassName)) {
            $this->processAccessCheck($entityType);

            $disableCount = $this->metadata
                ->get(['entityDefs', $entityType, 'collection', 'countDisabled']) ?? false;

            $orderDisabled = $this->metadata
                ->get(['scopes', $entityType, 'kanbanOrderDisabled']) ?? false;

            $maxOrderNumber = $this->config->get('kanbanMaxOrderNumber');

            /** @var OpportunityKanban $kanban */
            $kanban = $this->injectableFactory->create($customClassName);

            // Extract funnelId from search params (passed via where clause or custom param)
            $funnelId = $this->extractFunnelId($searchParams);

            // Debug logging - using warning level to ensure visibility
            $whereRaw = $searchParams->getWhere()?->getRaw();
            $GLOBALS['log']->warning(
                "[KanbanService] getData() entityType={$entityType} extractedFunnelId=" . ($funnelId ?? 'NULL') .
                " whereClause=" . json_encode($whereRaw)
            );

            $kanban
                ->setEntityType($entityType)
                ->setSearchParams($searchParams)
                ->setCountDisabled($disableCount)
                ->setOrderDisabled($orderDisabled)
                ->setUserId($this->user->getId())
                ->setMaxOrderNumber($maxOrderNumber);

            if ($funnelId) {
                $kanban->setFunnelId($funnelId);
            }

            $GLOBALS['log']->warning(
                "[KanbanService] getData() funnelIdExtracted=" . ($funnelId ?? 'NULL') .
                " funnelIdOnKanban=" . ($kanban->getFunnelId() ?? 'NULL')
            );

            return $kanban->getResult();
        }

        // Fall back to parent implementation for standard entities
        return parent::getData($entityType, $searchParams);
    }

    /**
     * Extract funnelId from search params.
     * Looks for funnelId in:
     * 1. Where clause with attribute 'funnelId' or 'funnel'
     * 2. Custom 'funnelId' parameter in search params
     */
    private function extractFunnelId(SearchParams $searchParams): ?string
    {
        // Check where clause for funnelId filter
        $whereClause = $searchParams->getWhere();

        if ($whereClause) {
            $funnelId = $this->findFunnelIdInWhere($whereClause->getRaw());

            if ($funnelId) {
                return $funnelId;
            }
        }

        return null;
    }

    /**
     * Recursively search for funnelId in where clause.
     *
     * @param array<string, mixed> $whereRaw
     */
    private function findFunnelIdInWhere(array $whereRaw): ?string
    {
        // Handle root-level where clause structure: {"type": "and", "value": [...]}
        if (isset($whereRaw['type']) && isset($whereRaw['value']) && is_array($whereRaw['value'])) {
            // Check if this IS the funnelId condition itself
            $attribute = $whereRaw['attribute'] ?? null;
            $field = $whereRaw['field'] ?? null;

            if ($attribute === 'funnelId' || $attribute === 'funnel' || $field === 'funnelId' || $field === 'funnel') {
                $value = $whereRaw['value'] ?? null;

                if ($value && is_string($value)) {
                    return $value;
                }
            }

            // Otherwise, recurse into the value array (for AND/OR wrappers)
            return $this->findFunnelIdInWhere($whereRaw['value']);
        }

        // Handle array of conditions
        foreach ($whereRaw as $item) {
            if (!is_array($item)) {
                continue;
            }

            // Check for direct funnelId condition
            $attribute = $item['attribute'] ?? null;
            $field = $item['field'] ?? null;

            if ($attribute === 'funnelId' || $attribute === 'funnel' || $field === 'funnelId' || $field === 'funnel') {
                $value = $item['value'] ?? null;

                if ($value && is_string($value)) {
                    return $value;
                }
            }

            // Check nested 'value' array (for OR/AND conditions)
            if (isset($item['value']) && is_array($item['value'])) {
                $nested = $this->findFunnelIdInWhere($item['value']);

                if ($nested) {
                    return $nested;
                }
            }
        }

        return null;
    }

    /**
     * @throws ForbiddenSilent
     */
    private function processAccessCheck(string $entityType): void
    {
        if (!$this->metadata->get(['scopes', $entityType, 'object'])) {
            throw new ForbiddenSilent("Non-object entities are not supported.");
        }

        if ($this->metadata->get(['recordDefs', $entityType, 'kanbanDisabled'])) {
            throw new ForbiddenSilent("Kanban is disabled for '$entityType'.");
        }

        if (!$this->aclManager->check($this->user, $entityType, Table::ACTION_READ)) {
            throw new ForbiddenSilent();
        }
    }
}
