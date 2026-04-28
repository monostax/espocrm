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

namespace Espo\Modules\Global\Classes\RecordHooks\SidenavConfig;

use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Record\Hook\SaveHook;
use Espo\Core\Utils\Metadata;
use Espo\ORM\Entity;

/**
 * Validates virtual-folder filter data before it is persisted.
 *
 * Runtime record access is still enforced by the normal list API ACL. This hook
 * only prevents storing raw where clauses or filters for inaccessible fields.
 *
 * @implements SaveHook<Entity>
 * @noinspection PhpUnused
 */
class BeforeSave implements SaveHook
{
    public function __construct(
        private Metadata $metadata,
        private Acl $acl,
    ) {}

    public function process(Entity $entity): void
    {
        $tabList = $this->normalizeValue($entity->get('tabList') ?? []);

        if (!is_array($tabList)) {
            throw new BadRequest('Invalid sidenav tabList.');
        }

        foreach ($tabList as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (($item['type'] ?? null) !== 'virtualFolder') {
                continue;
            }

            $this->validateVirtualFolder($item);
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function validateVirtualFolder(array $item): void
    {
        $entityType = $item['entityType'] ?? null;

        if (!is_string($entityType) || $entityType === '') {
            throw new BadRequest('Virtual folder entityType is required.');
        }

        if (!$this->metadata->get(['entityDefs', $entityType, 'fields'])) {
            throw new BadRequest('Invalid virtual folder entityType.');
        }

        if (!$this->acl->checkScope($entityType, Table::ACTION_READ)) {
            throw new BadRequest('No read access to virtual folder entityType.');
        }

        if (!empty($item['filterData'])) {
            if (!is_array($item['filterData'])) {
                throw new BadRequest('Invalid virtual folder filterData.');
            }

            $this->validateFilterData($entityType, $item['filterData']);
        }

        if (isset($item['filterName']) && !is_string($item['filterName']) && $item['filterName'] !== null) {
            throw new BadRequest('Invalid virtual folder filterName.');
        }
    }

    /**
     * @param array<string, mixed> $filterData
     */
    private function validateFilterData(string $entityType, array $filterData): void
    {
        $allowedKeys = ['textFilter' => true, 'bool' => true, 'advanced' => true, 'primary' => true, 'presetName' => true];

        foreach (array_keys($filterData) as $key) {
            if (!isset($allowedKeys[$key])) {
                throw new BadRequest('Invalid virtual folder filterData key.');
            }
        }

        if (isset($filterData['textFilter']) && !is_string($filterData['textFilter'])) {
            throw new BadRequest('Invalid virtual folder text filter.');
        }

        if (isset($filterData['textFilter']) && mb_strlen($filterData['textFilter']) > 255) {
            throw new BadRequest('Virtual folder text filter is too long.');
        }

        if (!empty($filterData['primary'])) {
            if (!is_string($filterData['primary']) || !$this->isAllowedPrimaryFilter($entityType, $filterData['primary'])) {
                throw new BadRequest('Invalid virtual folder primary filter.');
            }
        }

        if (!empty($filterData['presetName']) && !is_string($filterData['presetName'])) {
            throw new BadRequest('Invalid virtual folder preset filter.');
        }

        if (!empty($filterData['bool'])) {
            if (!is_array($filterData['bool'])) {
                throw new BadRequest('Invalid virtual folder bool filters.');
            }

            $this->validateBoolFilters($entityType, $filterData['bool']);
        }

        if (!empty($filterData['advanced'])) {
            if (!is_array($filterData['advanced'])) {
                throw new BadRequest('Invalid virtual folder advanced filters.');
            }

            $this->validateAdvancedFilters($entityType, $filterData['advanced']);
        }
    }

    /**
     * @param array<string, mixed> $bool
     */
    private function validateBoolFilters(string $entityType, array $bool): void
    {
        $allowed = $this->getAllowedBoolFilterMap($entityType);

        foreach ($bool as $name => $value) {
            if (!$value) {
                continue;
            }

            if (!isset($allowed[$name])) {
                throw new BadRequest('Invalid virtual folder bool filter.');
            }

            if (!is_bool($value)) {
                throw new BadRequest('Invalid virtual folder bool filter value.');
            }
        }
    }

    /**
     * @param array<string, mixed> $advanced
     */
    private function validateAdvancedFilters(string $entityType, array $advanced): void
    {
        if (count($advanced) > 50) {
            throw new BadRequest('Too many virtual folder advanced filters.');
        }

        $forbiddenFieldList = $this->acl->getScopeForbiddenFieldList($entityType, Table::ACTION_READ);

        foreach ($advanced as $field => $defs) {
            if (!is_string($field) || !$this->metadata->get(['entityDefs', $entityType, 'fields', $field])) {
                throw new BadRequest('Invalid virtual folder filter field.');
            }

            if (in_array($field, $forbiddenFieldList)) {
                throw new BadRequest('No read access to virtual folder filter field.');
            }

            if (!is_array($defs)) {
                throw new BadRequest('Invalid virtual folder filter definition.');
            }

            $this->validateAdvancedFilterDefs($entityType, $field, $defs);
        }
    }

    /**
     * @param array<string, mixed> $defs
     */
    private function validateAdvancedFilterDefs(string $entityType, string $field, array $defs, int $depth = 0): void
    {
        if ($depth > 5) {
            throw new BadRequest('Virtual folder filter nesting is too deep.');
        }

        if (isset($defs['where'])) {
            throw new BadRequest('Raw where clauses are not allowed in virtual folder filters.');
        }

        $type = $defs['type'] ?? null;

        if (!is_string($type) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $type)) {
            throw new BadRequest('Invalid virtual folder filter type.');
        }

        foreach (['attribute', 'field'] as $key) {
            if (isset($defs[$key]) && !$this->isAllowedAttribute($entityType, $field, $defs[$key])) {
                throw new BadRequest('Invalid virtual folder filter attribute.');
            }
        }

        if (($type === 'or' || $type === 'and') && isset($defs['value'])) {
            if (!is_array($defs['value'])) {
                throw new BadRequest('Invalid virtual folder grouped filter value.');
            }

            foreach ($defs['value'] as $subField => $subDefs) {
                if (!is_string($subField) || !is_array($subDefs)) {
                    throw new BadRequest('Invalid virtual folder grouped filter definition.');
                }

                if (!$this->metadata->get(['entityDefs', $entityType, 'fields', $subField])) {
                    throw new BadRequest('Invalid virtual folder grouped filter field.');
                }

                $forbiddenFieldList = $this->acl->getScopeForbiddenFieldList($entityType, Table::ACTION_READ);

                if (in_array($subField, $forbiddenFieldList)) {
                    throw new BadRequest('No read access to virtual folder grouped filter field.');
                }

                $this->validateAdvancedFilterDefs($entityType, $subField, $subDefs, $depth + 1);
            }
        }
    }

    private function isAllowedAttribute(string $entityType, string $field, mixed $attribute): bool
    {
        if (!is_string($attribute)) {
            return false;
        }

        if ($attribute === 'id' || $attribute === $field || $attribute === $field . 'Id') {
            return true;
        }

        if (!$this->metadata->get(['entityDefs', $entityType, 'fields', $attribute])) {
            return false;
        }

        $forbiddenFieldList = $this->acl->getScopeForbiddenFieldList($entityType, Table::ACTION_READ);

        return !in_array($attribute, $forbiddenFieldList);
    }

    private function isAllowedPrimaryFilter(string $entityType, string $name): bool
    {
        $filterList = $this->metadata->get(['clientDefs', $entityType, 'filterList']) ?? [];

        foreach ($filterList as $item) {
            $normalized = $this->normalizeValue($item);
            $filterName = is_string($item) ? $item : (is_array($normalized) ? ($normalized['name'] ?? null) : null);

            if ($filterName === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    private function getAllowedBoolFilterMap(string $entityType): array
    {
        $filterList = $this->metadata->get(['clientDefs', $entityType, 'boolFilterList']) ?? [];
        $map = [];

        foreach ($filterList as $item) {
            $normalized = $this->normalizeValue($item);
            $name = is_string($item) ? $item : (is_array($normalized) ? ($normalized['name'] ?? null) : null);

            if (is_string($name) && $name !== '') {
                $map[$name] = true;
            }
        }

        if ($this->metadata->get(['scopes', $entityType, 'stream'])) {
            $map['followed'] = true;
        }

        if ($this->metadata->get(['scopes', $entityType, 'collaborators'])) {
            $map['shared'] = true;
        }

        return $map;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeValue($item);
        }

        return $value;
    }
}
