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

            /** @var object $kanban */
            $kanban = $this->injectableFactory->create($customClassName);

            // Extract funnelId / journeyId from search where (entity-specific boards)
            $funnelId = $this->extractFunnelId($searchParams);
            $journeyId = $this->extractAttributeId($searchParams, ['journeyId', 'journey']);

            $kanban
                ->setEntityType($entityType)
                ->setSearchParams($searchParams)
                ->setCountDisabled($disableCount)
                ->setOrderDisabled($orderDisabled)
                ->setUserId($this->user->getId())
                ->setMaxOrderNumber($maxOrderNumber);

            if ($funnelId && is_callable([$kanban, 'setFunnelId'])) {
                $kanban->{'setFunnelId'}($funnelId);
            }

            if ($journeyId && is_callable([$kanban, 'setJourneyId'])) {
                $kanban->{'setJourneyId'}($journeyId);
            }

            return $kanban->getResult();
        }

        // Fall back to parent implementation for standard entities
        return parent::getData($entityType, $searchParams);
    }

    /**
     * Extract funnelId from search params where clause.
     */
    private function extractFunnelId(SearchParams $searchParams): ?string
    {
        return $this->extractAttributeId($searchParams, ['funnelId', 'funnel']);
    }

    /**
     * @param list<string> $names attribute/field names to match
     */
    private function extractAttributeId(SearchParams $searchParams, array $names): ?string
    {
        $whereClause = $searchParams->getWhere();

        if (!$whereClause) {
            return null;
        }

        return $this->findAttributeIdInWhere($whereClause->getRaw(), $names);
    }

    /**
     * @param array<string, mixed> $whereRaw
     * @param list<string> $names
     */
    private function findAttributeIdInWhere(array $whereRaw, array $names): ?string
    {
        if (isset($whereRaw['type']) && isset($whereRaw['value']) && is_array($whereRaw['value'])) {
            $attribute = $whereRaw['attribute'] ?? null;
            $field = $whereRaw['field'] ?? null;

            if (
                (is_string($attribute) && in_array($attribute, $names, true)) ||
                (is_string($field) && in_array($field, $names, true))
            ) {
                $value = $whereRaw['value'] ?? null;

                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }

            return $this->findAttributeIdInWhere($whereRaw['value'], $names);
        }

        foreach ($whereRaw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $attribute = $item['attribute'] ?? null;
            $field = $item['field'] ?? null;

            if (
                (is_string($attribute) && in_array($attribute, $names, true)) ||
                (is_string($field) && in_array($field, $names, true))
            ) {
                $value = $item['value'] ?? null;

                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }

            if (isset($item['value']) && is_array($item['value'])) {
                $nested = $this->findAttributeIdInWhere($item['value'], $names);

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
