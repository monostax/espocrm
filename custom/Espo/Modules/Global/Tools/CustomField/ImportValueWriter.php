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

use Espo\Core\FieldValidation\Exceptions\ValidationError;
use Espo\Core\FieldValidation\Failure;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Utils\Json;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Tools\Import\Params;
use Throwable;

/**
 * Writes tenant custom-field bag values during CSV import.
 *
 * Map tokens:
 *   - customFields              → whole JSON object (merge into bag)
 *   - customFields.<valueKey>   → flat leaf (e.g. customFields.address.city)
 */
class ImportValueWriter
{
    public function __construct(
        private MetaProvider $metaProvider,
        private TenantResolver $tenantResolver,
        private User $user,
    ) {}

    public function isLeafAttribute(string $attribute): bool
    {
        $attr = $this->metaProvider->getAttributeName();

        return str_starts_with($attribute, $attr . '.') && strlen($attribute) > strlen($attr) + 1;
    }

    public function isBagAttribute(string $attribute): bool
    {
        return $attribute === $this->metaProvider->getAttributeName();
    }

    public function supports(string $entityType, string $attribute): bool
    {
        if (!$this->metaProvider->isEntityEnabled($entityType)) {
            return false;
        }

        return $this->isBagAttribute($attribute) || $this->isLeafAttribute($attribute);
    }

    /**
     * @throws ValidationError
     */
    public function apply(
        CoreEntity $entity,
        string $attribute,
        string $value,
        Params $params,
    ): void {
        $entityType = $entity->getEntityType();

        if (!$this->metaProvider->isEntityEnabled($entityType)) {
            return;
        }

        $bagAttr = $this->metaProvider->getAttributeName();

        if ($this->isBagAttribute($attribute)) {
            $this->applyWholeBag($entity, $bagAttr, $value);

            return;
        }

        if (!$this->isLeafAttribute($attribute)) {
            return;
        }

        $valueKey = substr($attribute, strlen($bagAttr) + 1);

        if ($valueKey === '') {
            return;
        }

        if ($value === '' && $entity->isNew()) {
            return;
        }

        $bag = $this->readBag($entity, $bagAttr);

        if ($value === '') {
            unset($bag[$valueKey]);
            $this->writeBag($entity, $bagAttr, $bag);

            return;
        }

        $def = $this->resolveDef($entity, $entityType, $valueKey);
        $bag[$valueKey] = $this->coerce($entityType, $valueKey, $value, $def, $params);

        $this->writeBag($entity, $bagAttr, $bag);
    }

    /**
     * Strip virtual leaf attrs when the bag field is edit-forbidden.
     *
     * @param string[] $attributeList
     * @param string[] $forbiddenAttributeList
     */
    public function applyAclFilter(array &$attributeList, array $forbiddenAttributeList): void
    {
        $bagAttr = $this->metaProvider->getAttributeName();
        $bagForbidden = in_array($bagAttr, $forbiddenAttributeList, true);

        foreach ($attributeList as $k => $attribute) {
            if (!is_string($attribute) || $attribute === '') {
                continue;
            }

            if (!$this->isLeafAttribute($attribute) && !$this->isBagAttribute($attribute)) {
                continue;
            }

            if ($bagForbidden || in_array($attribute, $forbiddenAttributeList, true)) {
                unset($attributeList[$k]);
            }
        }
    }

    /**
     * @throws ValidationError
     */
    private function applyWholeBag(CoreEntity $entity, string $bagAttr, string $value): void
    {
        if ($value === '') {
            if ($entity->isNew()) {
                return;
            }

            $this->writeBag($entity, $bagAttr, []);

            return;
        }

        try {
            $decoded = Json::decode($value);
        } catch (Throwable) {
            throw ValidationError::create(
                new Failure($entity->getEntityType(), $bagAttr, 'valid')
            );
        }

        if (!is_object($decoded) && !is_array($decoded)) {
            throw ValidationError::create(
                new Failure($entity->getEntityType(), $bagAttr, 'valid')
            );
        }

        $incoming = is_object($decoded) ? get_object_vars($decoded) : $decoded;
        $bag = $this->readBag($entity, $bagAttr);

        foreach ($incoming as $key => $item) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            $bag[$key] = $item;
        }

        $this->writeBag($entity, $bagAttr, $bag);
    }

    /**
     * @return array<string, mixed>
     */
    private function readBag(CoreEntity $entity, string $bagAttr): array
    {
        $raw = $entity->get($bagAttr);

        if ($raw === null) {
            return [];
        }

        if (is_object($raw)) {
            return get_object_vars($raw);
        }

        if (is_array($raw)) {
            /** @var array<string, mixed> $raw */
            return $raw;
        }

        return [];
    }

    /**
     * @param array<string, mixed> $bag
     */
    private function writeBag(CoreEntity $entity, string $bagAttr, array $bag): void
    {
        $entity->set($bagAttr, (object) $bag);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveDef(CoreEntity $entity, string $entityType, string $valueKey): ?array
    {
        $tenantId = $this->resolveTenantId($entity);

        if ($tenantId === null) {
            return null;
        }

        foreach ($this->metaProvider->getGroupedMeta($entityType, $tenantId)['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                if (($field['valueKey'] ?? null) === $valueKey) {
                    return $field;
                }
            }
        }

        return null;
    }

    private function resolveTenantId(CoreEntity $entity): ?string
    {
        $tenantId = $entity->get('tenantId');

        if (is_string($tenantId) && $tenantId !== '') {
            return $tenantId;
        }

        $teamIds = $this->collectTeamIds($entity);

        if ($teamIds !== []) {
            $resolved = $this->tenantResolver->resolveFromTeamIds($teamIds);

            if ($resolved) {
                return $resolved;
            }
        }

        return $this->tenantResolver->resolveFromTeamIds($this->getUserTeamIds());
    }

    /**
     * @return list<string>
     */
    private function collectTeamIds(CoreEntity $entity): array
    {
        $ids = [];

        try {
            $list = $entity->getLinkMultipleIdList('teams');

            if (is_array($list)) {
                foreach ($list as $id) {
                    if (is_string($id) && $id !== '') {
                        $ids[] = $id;
                    }
                }
            }
        } catch (Throwable) {
            // fall through
        }

        $teamsIds = $entity->get('teamsIds');

        if (is_array($teamsIds)) {
            foreach ($teamsIds as $id) {
                if (is_string($id) && $id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
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
        } catch (Throwable) {
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
     * @param array<string, mixed>|null $def
     * @throws ValidationError
     */
    private function coerce(
        string $entityType,
        string $valueKey,
        string $value,
        ?array $def,
        Params $params,
    ): mixed {
        $type = is_array($def) ? (string) ($def['type'] ?? 'varchar') : 'varchar';
        $attribute = $this->metaProvider->getAttributeName() . '.' . $valueKey;

        /** @var non-empty-string $decimalMark */
        $decimalMark = $params->getDecimalMark() ?? '.';

        return match ($type) {
            'bool' => $this->coerceBool($value),
            'int' => $this->coerceInt($entityType, $attribute, $value, $decimalMark),
            'float' => $this->coerceFloat($entityType, $attribute, $value, $decimalMark),
            'multiEnum' => $this->coerceMultiEnum($entityType, $attribute, $value),
            'date', 'datetime', 'enum', 'varchar', 'text' => $value,
            default => $value,
        };
    }

    private function coerceBool(string $value): bool
    {
        if ($value === '0' || $value === '' || strtolower($value) === 'false') {
            return false;
        }

        return (bool) $value;
    }

    /**
     * @param non-empty-string $decimalMark
     * @throws ValidationError
     */
    private function coerceInt(
        string $entityType,
        string $attribute,
        string $value,
        string $decimalMark,
    ): int {
        $replaceList = [
            ' ',
            $decimalMark === '.' ? ',' : '.',
        ];

        $normalized = str_replace($replaceList, '', $value);

        if (str_contains($normalized, $decimalMark) || !is_numeric($normalized)) {
            throw ValidationError::create(
                new Failure($entityType, $attribute, 'valid')
            );
        }

        return (int) $normalized;
    }

    /**
     * @param non-empty-string $decimalMark
     * @throws ValidationError
     */
    private function coerceFloat(
        string $entityType,
        string $attribute,
        string $value,
        string $decimalMark,
    ): float {
        $normalized = $this->transformFloatString($decimalMark, $value);

        if ($normalized === null) {
            throw ValidationError::create(
                new Failure($entityType, $attribute, 'valid')
            );
        }

        return (float) $normalized;
    }

    /**
     * @return list<string>
     * @throws ValidationError
     */
    private function coerceMultiEnum(string $entityType, string $attribute, string $value): array
    {
        $trim = trim($value);

        if ($trim === '') {
            return [];
        }

        if ($trim[0] === '[') {
            try {
                $decoded = Json::decode($trim);
            } catch (Throwable) {
                throw ValidationError::create(
                    new Failure($entityType, $attribute, 'valid')
                );
            }

            if (!is_array($decoded)) {
                throw ValidationError::create(
                    new Failure($entityType, $attribute, 'valid')
                );
            }

            $out = [];

            foreach ($decoded as $item) {
                if (is_string($item) || is_int($item) || is_float($item)) {
                    $out[] = (string) $item;
                }
            }

            return $out;
        }

        return array_values(array_filter(
            array_map(static fn(string $it): string => trim($it), explode(',', $value)),
            static fn(string $it): bool => $it !== ''
        ));
    }

    /**
     * @param non-empty-string $decimalMark
     */
    private function transformFloatString(string $decimalMark, string $value): ?string
    {
        $a = $decimalMark === '.' ? ',' : '.';

        $value = str_replace(' ', '', $value);
        $value = str_replace($a, '', $value);

        if ($decimalMark !== '.') {
            $value = str_replace($decimalMark, '.', $value);
        }

        if (!is_numeric($value)) {
            return null;
        }

        return $value;
    }
}
