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

namespace Espo\Modules\Global\Tools\CustomField;

use Espo\Core\Utils\Metadata;
use Espo\ORM\EntityManager;

/**
 * Reads tenant-scoped custom field schema for a host entity type.
 *
 * Values live on the host record (`customFields` jsonObject) and inherit
 * that record's ACL — this provider never gates values, only returns defs.
 */
class MetaProvider
{
    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
    ) {}

    /**
     * @return list<string>
     */
    public function getEnabledEntityTypes(): array
    {
        /** @var list<string>|null $list */
        $list = $this->metadata->get(['app', 'customFields', 'entityTypeList']);

        return is_array($list) ? array_values($list) : [];
    }

    public function isEntityEnabled(string $entityType): bool
    {
        return in_array($entityType, $this->getEnabledEntityTypes(), true);
    }

    public function getAttributeName(): string
    {
        $name = $this->metadata->get(['app', 'customFields', 'attributeName']);

        return is_string($name) && $name !== '' ? $name : 'customFields';
    }

    /**
     * @return list<string>
     */
    public function getAllowedTypes(): array
    {
        /** @var list<string>|null $list */
        $list = $this->metadata->get(['app', 'customFields', 'allowedTypes']);

        return is_array($list) ? array_values($list) : [
            'varchar', 'text', 'int', 'float', 'bool',
            'date', 'datetime', 'enum', 'multiEnum',
        ];
    }

    /**
     * Grouped field definitions for UI / template pickers.
     *
     * @return array{
     *     entityType: string,
     *     tenantId: ?string,
     *     attributeName: string,
     *     groups: list<array{
     *         id: ?string,
     *         name: string,
     *         label: string,
     *         iconClass: ?string,
     *         sortOrder: int,
     *         fields: list<array<string, mixed>>
     *     }>
     * }
     */
    public function getGroupedMeta(string $entityType, ?string $tenantId): array
    {
        // Require a tenant — never leak cross-tenant defs by listing everything.
        if ($tenantId === null || $tenantId === '') {
            return [
                'entityType' => $entityType,
                'tenantId' => null,
                'attributeName' => $this->getAttributeName(),
                'groups' => [],
            ];
        }

        $groups = $this->loadGroups($entityType, $tenantId);
        $defs = $this->loadDefs($entityType, $tenantId);

        $grouped = [];

        foreach ($groups as $group) {
            $grouped[$group['id']] = [
                'id' => $group['id'],
                'name' => $group['name'],
                'label' => $group['label'],
                'iconClass' => $group['iconClass'],
                'sortOrder' => $group['sortOrder'],
                'fields' => [],
            ];
        }

        $ungrouped = [
            'id' => null,
            'name' => '_general',
            'label' => 'General',
            'iconClass' => null,
            'sortOrder' => 100000,
            'fields' => [],
        ];

        foreach ($defs as $def) {
            $payload = $this->defToArray($def);
            $groupId = $def['groupId'] ?? null;

            if ($groupId && isset($grouped[$groupId])) {
                $grouped[$groupId]['fields'][] = $payload;
            } else {
                $ungrouped['fields'][] = $payload;
            }
        }

        $result = array_values($grouped);

        if ($ungrouped['fields'] !== []) {
            $result[] = $ungrouped;
        }

        // Drop empty groups (active group with no active fields).
        $result = array_values(array_filter(
            $result,
            static fn(array $g): bool => $g['fields'] !== []
        ));

        usort(
            $result,
            static fn(array $a, array $b): int => $a['sortOrder'] <=> $b['sortOrder']
        );

        return [
            'entityType' => $entityType,
            'tenantId' => $tenantId,
            'attributeName' => $this->getAttributeName(),
            'groups' => $result,
        ];
    }

    /**
     * Flat list of template-enabled fields (for Email / WhatsApp pickers).
     *
     * @return list<array{
     *     valueKey: string,
     *     label: string,
     *     type: string,
     *     groupLabel: ?string,
     *     expression: string,
     *     classicExpression: string
     * }>
     */
    public function getTemplateVariables(string $entityType, ?string $tenantId): array
    {
        if ($tenantId === null || $tenantId === '') {
            return [];
        }

        return $this->buildTemplateVariables($entityType, $tenantId);
    }

    /**
     * Union of template-enabled fields across many tenants (Email Template builder).
     *
     * Dedupes by valueKey (first wins). Use when the composer has no single host
     * record tenant — e.g. admin writing a campaign template usable for any of
     * their accessible tenants.
     *
     * @param list<string> $tenantIds
     * @return list<array{
     *     valueKey: string,
     *     label: string,
     *     type: string,
     *     groupLabel: ?string,
     *     expression: string,
     *     classicExpression: string
     * }>
     */
    public function getTemplateVariablesForTenants(string $entityType, array $tenantIds): array
    {
        $byKey = [];

        foreach ($tenantIds as $tenantId) {
            if (!is_string($tenantId) || trim($tenantId) === '') {
                continue;
            }

            foreach ($this->buildTemplateVariables($entityType, trim($tenantId)) as $item) {
                $key = $item['valueKey'];

                if ($key === '' || isset($byKey[$key])) {
                    continue;
                }

                $byKey[$key] = $item;
            }
        }

        return array_values($byKey);
    }

    /**
     * @return list<array{
     *     valueKey: string,
     *     label: string,
     *     type: string,
     *     groupLabel: ?string,
     *     expression: string,
     *     classicExpression: string
     * }>
     */
    private function buildTemplateVariables(string $entityType, string $tenantId): array
    {
        $meta = $this->getGroupedMeta($entityType, $tenantId);
        $out = [];

        foreach ($meta['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                if (!($field['isTemplateEnabled'] ?? true)) {
                    continue;
                }

                $attr = $this->getAttributeName();
                $path = $this->toHandlebarsPath((string) $field['valueKey']);

                $out[] = [
                    'valueKey' => (string) $field['valueKey'],
                    'label' => (string) $field['label'],
                    'type' => (string) $field['type'],
                    'groupLabel' => $group['name'] === '_general' ? null : $group['label'],
                    'expression' => '{{' . $attr . '.' . $path . '}}',
                    'classicExpression' => '{' . $entityType . '.' . $attr . '.' . $path . '}',
                ];
            }
        }

        return $out;
    }

    /**
     * Flat list of importable bag leaves (all active defs).
     *
     * @return list<array{
     *     attribute: string,
     *     valueKey: string,
     *     label: string,
     *     type: string,
     *     groupLabel: ?string
     * }>
     */
    public function getImportVariables(string $entityType, ?string $tenantId): array
    {
        $meta = $this->getGroupedMeta($entityType, $tenantId);
        $attr = $this->getAttributeName();
        $out = [];

        foreach ($meta['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                $valueKey = (string) ($field['valueKey'] ?? '');

                if ($valueKey === '') {
                    continue;
                }

                $out[] = [
                    'attribute' => $attr . '.' . $valueKey,
                    'valueKey' => $valueKey,
                    'label' => (string) ($field['label'] ?? $valueKey),
                    'type' => (string) ($field['type'] ?? 'varchar'),
                    'groupLabel' => $group['name'] === '_general' ? null : $group['label'],
                ];
            }
        }

        return $out;
    }

    /**
     * Expand dotted flat keys into a nested array for Handlebars/Htmlizer.
     *
     * @param array<string, mixed>|object|null $bag
     * @return array<string, mixed>
     */
    public function expandForTemplate(array|object|null $bag): array
    {
        if ($bag === null) {
            return [];
        }

        $flat = is_object($bag) ? get_object_vars($bag) : $bag;
        $nested = [];

        foreach ($flat as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            if (!str_contains($key, '.')) {
                $nested[$key] = $value;
                continue;
            }

            $parts = explode('.', $key);
            $ref = &$nested;

            foreach ($parts as $i => $part) {
                if ($i === count($parts) - 1) {
                    $ref[$part] = $value;
                    break;
                }

                if (!isset($ref[$part]) || !is_array($ref[$part])) {
                    $ref[$part] = [];
                }

                $ref = &$ref[$part];
            }

            unset($ref);
        }

        return $nested;
    }

    private function toHandlebarsPath(string $valueKey): string
    {
        return str_replace('.', '.', $valueKey);
    }

    /**
     * @return list<array{id: string, name: string, label: string, iconClass: ?string, sortOrder: int}>
     */
    private function loadGroups(string $entityType, ?string $tenantId): array
    {
        $where = [
            'entityType' => $entityType,
            'isActive' => true,
        ];

        if ($tenantId) {
            $where['tenantId'] = $tenantId;
        }

        $collection = $this->entityManager
            ->getRDBRepository('CustomFieldGroup')
            ->where($where)
            ->order('sortOrder', 'ASC')
            ->order('name', 'ASC')
            ->find();

        $out = [];

        foreach ($collection as $entity) {
            $out[] = [
                'id' => (string) $entity->getId(),
                'name' => (string) $entity->get('name'),
                'label' => (string) ($entity->get('label') ?: $entity->get('name')),
                'iconClass' => $entity->get('iconClass') ? (string) $entity->get('iconClass') : null,
                'sortOrder' => (int) ($entity->get('sortOrder') ?? 10),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadDefs(string $entityType, ?string $tenantId): array
    {
        $where = [
            'entityType' => $entityType,
            'isActive' => true,
        ];

        if ($tenantId) {
            $where['tenantId'] = $tenantId;
        }

        $collection = $this->entityManager
            ->getRDBRepository('CustomFieldDef')
            ->where($where)
            ->order('sortOrder', 'ASC')
            ->order('name', 'ASC')
            ->find();

        $out = [];

        foreach ($collection as $entity) {
            $out[] = [
                'id' => (string) $entity->getId(),
                'name' => (string) $entity->get('name'),
                'label' => (string) ($entity->get('label') ?: $entity->get('name')),
                'type' => (string) $entity->get('type'),
                'valueKey' => (string) $entity->get('valueKey'),
                'options' => $this->normalizeOptions($entity->get('options')),
                'isRequired' => (bool) $entity->get('isRequired'),
                'defaultValue' => $entity->get('defaultValue'),
                'maxLength' => $entity->get('maxLength'),
                'min' => $entity->get('min'),
                'max' => $entity->get('max'),
                'sortOrder' => (int) ($entity->get('sortOrder') ?? 10),
                'isTemplateEnabled' => $entity->get('isTemplateEnabled') !== false,
                'groupId' => $entity->get('groupId') ? (string) $entity->get('groupId') : null,
                'description' => $entity->get('description'),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $def
     * @return array<string, mixed>
     */
    private function defToArray(array $def): array
    {
        return $def;
    }

    /**
     * @return list<string>
     */
    private function normalizeOptions(mixed $options): array
    {
        if ($options === null) {
            return [];
        }

        if (is_object($options)) {
            $options = get_object_vars($options);
        }

        if (!is_array($options)) {
            return [];
        }

        $out = [];

        foreach ($options as $item) {
            if (is_string($item) || is_int($item) || is_float($item)) {
                $out[] = (string) $item;
            }
        }

        return $out;
    }
}
