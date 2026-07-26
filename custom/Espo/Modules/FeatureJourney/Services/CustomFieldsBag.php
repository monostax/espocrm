<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\Core\Utils\Metadata;
use Espo\ORM\Entity;

/**
 * Monostax CustomField JSON bag helpers for Journey filters / updateTarget.
 *
 * Distinct from Espo Entity Manager `cFoo` columns (journeyUpdateTarget.allowCustomFieldPrefix).
 * Values live on host attribute `customFields` as a flat map keyed by CustomFieldDef.valueKey
 * (e.g. "plan", "billing.plan") — dots are part of the key, not nested objects.
 */
class CustomFieldsBag
{
    public function __construct(
        private Metadata $metadata,
    ) {}

    public function getAttributeName(): string
    {
        $name = $this->metadata->get(['app', 'customFields', 'attributeName']);

        return is_string($name) && $name !== '' ? $name : 'customFields';
    }

    /**
     * @return list<string>
     */
    public function getEnabledEntityTypes(): array
    {
        $list = $this->metadata->get(['app', 'customFields', 'entityTypeList']);

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    public function isEntityEnabled(string $entityType): bool
    {
        return in_array($entityType, $this->getEnabledEntityTypes(), true);
    }

    public function isBagAttribute(string $attribute): bool
    {
        $prefix = $this->getAttributeName();

        return $attribute === $prefix || str_starts_with($attribute, $prefix . '.');
    }

    /**
     * valueKey for a bag leaf attribute, or null when attribute is the whole bag /
     * not a bag attribute.
     */
    public function valueKeyFromAttribute(string $attribute): ?string
    {
        $prefix = $this->getAttributeName();

        if ($attribute === $prefix) {
            return null;
        }

        if (str_starts_with($attribute, $prefix . '.')) {
            $key = substr($attribute, strlen($prefix) + 1);

            return $key !== '' ? $key : null;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function normalizeBag(mixed $raw): array
    {
        if ($raw instanceof \stdClass) {
            $raw = get_object_vars($raw);
        }

        if (!is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * Read a bag leaf from target. Null when missing.
     */
    public function getBagValue(Entity $entity, string $valueKey): mixed
    {
        $bag = $this->normalizeBag($entity->get($this->getAttributeName()));

        return array_key_exists($valueKey, $bag) ? $bag[$valueKey] : null;
    }

    public function hasBagKey(Entity $entity, string $valueKey): bool
    {
        $bag = $this->normalizeBag($entity->get($this->getAttributeName()));

        return array_key_exists($valueKey, $bag);
    }

    /**
     * Merge patch into a bag; null values remove keys.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function mergeBag(array $existing, array $patch): array
    {
        $out = $existing;

        foreach ($patch as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            if ($value === null) {
                unset($out[$key]);
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * Expand flat update maps into a structure ready for Entity::set after bag merge:
     * - native fields stay top-level
     * - `customFields` object/array becomes the bag patch
     * - `customFields.<valueKey>` dotted keys fold into the bag patch
     *
     * @param array<string, mixed> $fields
     * @return array{fields: array<string, mixed>, bagPatch: array<string, mixed>}
     */
    public function expandUpdateFields(array $fields): array
    {
        $prefix = $this->getAttributeName();
        $out = [];
        $bagPatch = [];

        foreach ($fields as $name => $value) {
            if (!is_string($name) || $name === '') {
                continue;
            }

            if ($name === $prefix) {
                foreach ($this->normalizeBag($value) as $k => $v) {
                    $bagPatch[$k] = $v;
                }

                continue;
            }

            if (str_starts_with($name, $prefix . '.')) {
                $key = substr($name, strlen($prefix) + 1);

                if ($key !== '') {
                    $bagPatch[$key] = $value;
                }

                continue;
            }

            $out[$name] = $value;
        }

        return [
            'fields' => $out,
            'bagPatch' => $bagPatch,
        ];
    }

    /**
     * Apply bag patch onto entity attribute (mutates entity, does not save).
     *
     * @param array<string, mixed> $bagPatch
     */
    public function applyBagPatch(Entity $entity, array $bagPatch): void
    {
        if ($bagPatch === []) {
            return;
        }

        $attr = $this->getAttributeName();

        if (!$entity->hasAttribute($attr)) {
            return;
        }

        $merged = $this->mergeBag(
            $this->normalizeBag($entity->get($attr)),
            $bagPatch
        );

        $entity->set($attr, (object) $merged);
    }

    /**
     * True when any where leaf attribute targets the CustomField bag.
     *
     * @param list<mixed>|array<string, mixed> $where
     */
    public function whereReferencesBag(array $where): bool
    {
        foreach ($this->walkWhereItems($where) as $item) {
            $attr = $item['attribute'] ?? null;

            if (is_string($attr) && $this->isBagAttribute($attr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Split a top-level AND where-list into native SelectBuilder items vs bag items.
     * Nested and/or groups that contain bag attributes are kept wholly on the bag side
     * (in-memory eval only supports host attribute gets, not link dotted paths).
     *
     * @param list<mixed>|array<string, mixed> $where
     * @return array{native: list<array<string, mixed>>, bag: list<array<string, mixed>>}
     */
    public function partitionWhere(array $where): array
    {
        $items = $this->normalizeWhereList($where);
        $native = [];
        $bag = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            if ($this->itemOrSubtreeReferencesBag($item)) {
                $bag[] = $item;
            } else {
                $native[] = $item;
            }
        }

        return [
            'native' => $native,
            'bag' => $bag,
        ];
    }

    /**
     * Evaluate where items against a loaded target (bag + direct host attributes).
     * Does not resolve link.attribute paths — those stay on the SelectBuilder path.
     *
     * @param list<array<string, mixed>> $items
     */
    public function matchWhereItems(array $items, Entity $entity): bool
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                return false;
            }

            if (!$this->matchWhereItem($item, $entity)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $item
     */
    public function matchWhereItem(array $item, Entity $entity): bool
    {
        $type = $item['type'] ?? null;

        if ($type === 'and' && isset($item['value']) && is_array($item['value'])) {
            foreach ($item['value'] as $child) {
                if (!is_array($child) || !$this->matchWhereItem($child, $entity)) {
                    return false;
                }
            }

            return true;
        }

        if ($type === 'or' && isset($item['value']) && is_array($item['value'])) {
            foreach ($item['value'] as $child) {
                if (is_array($child) && $this->matchWhereItem($child, $entity)) {
                    return true;
                }
            }

            return false;
        }

        if ($type === 'not' && isset($item['value']) && is_array($item['value'])) {
            $inner = $item['value'];

            // Espo not can wrap one item or a list.
            if ($this->isListOfWhereItems($inner)) {
                return !$this->matchWhereItems($inner, $entity);
            }

            return !$this->matchWhereItem($inner, $entity);
        }

        $attribute = $item['attribute'] ?? null;

        if (!is_string($attribute) || $attribute === '') {
            return false;
        }

        $actual = $this->resolveAttributeValue($entity, $attribute);
        $op = $this->normalizeOperator((string) ($type ?? 'equals'));
        $expected = $item['value'] ?? null;

        return $this->compare($actual, $op, $expected, $entity, $attribute);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function normalizeWhereList(mixed $where): array
    {
        if ($where instanceof \stdClass) {
            $where = json_decode(json_encode($where) ?: '[]', true);
        }

        if (!is_array($where)) {
            return [];
        }

        // Single associate leaf {type, attribute, ...}
        if (isset($where['type']) || isset($where['attribute'])) {
            return [$where];
        }

        $out = [];

        foreach ($where as $item) {
            if ($item instanceof \stdClass) {
                $item = json_decode(json_encode($item) ?: '{}', true);
            }

            if (is_array($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function walkWhereItems(array $where): \Generator
    {
        foreach ($this->normalizeWhereList($where) as $item) {
            yield from $this->walkItem($item);
        }
    }

    /**
     * @param array<string, mixed> $item
     * @return \Generator<int, array<string, mixed>>
     */
    private function walkItem(array $item): \Generator
    {
        $type = $item['type'] ?? null;

        if (in_array($type, ['and', 'or'], true) && isset($item['value']) && is_array($item['value'])) {
            foreach ($item['value'] as $child) {
                if (is_array($child)) {
                    yield from $this->walkItem($child);
                }
            }

            return;
        }

        if ($type === 'not' && isset($item['value']) && is_array($item['value'])) {
            $inner = $item['value'];

            if ($this->isListOfWhereItems($inner)) {
                foreach ($inner as $child) {
                    if (is_array($child)) {
                        yield from $this->walkItem($child);
                    }
                }

                return;
            }

            yield from $this->walkItem($inner);

            return;
        }

        yield $item;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function itemOrSubtreeReferencesBag(array $item): bool
    {
        foreach ($this->walkItem($item) as $leaf) {
            $attr = $leaf['attribute'] ?? null;

            if (is_string($attr) && $this->isBagAttribute($attr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $arr
     */
    private function isListOfWhereItems(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }

        if (array_keys($arr) !== range(0, count($arr) - 1)) {
            return false;
        }

        $first = $arr[0] ?? null;

        return is_array($first);
    }

    private function resolveAttributeValue(Entity $entity, string $attribute): mixed
    {
        $prefix = $this->getAttributeName();

        if ($attribute === $prefix) {
            return $this->normalizeBag($entity->get($prefix));
        }

        if (str_starts_with($attribute, $prefix . '.')) {
            $key = substr($attribute, strlen($prefix) + 1);

            return $this->getBagValue($entity, $key);
        }

        // Direct host attribute only (no link.field).
        if (str_contains($attribute, '.')) {
            return null;
        }

        return $entity->get($attribute);
    }

    private function normalizeOperator(string $type): string
    {
        return match ($type) {
            'equals', '==' => 'equals',
            'notEquals', '!=' => 'notEquals',
            'contains' => 'contains',
            'notContains' => 'notContains',
            'startsWith' => 'startsWith',
            'endsWith' => 'endsWith',
            'like' => 'like',
            'in' => 'in',
            'notIn' => 'notIn',
            'greaterThan', '>' => 'greaterThan',
            'greaterThanOrEquals', 'greaterThanOrEqual', '>=' => 'greaterThanOrEqual',
            'lessThan', '<' => 'lessThan',
            'lessThanOrEquals', 'lessThanOrEqual', '<=' => 'lessThanOrEqual',
            'isTrue' => 'isTrue',
            'isFalse' => 'isFalse',
            'isNull' => 'isNull',
            'isNotNull' => 'isNotNull',
            'exists' => 'exists',
            'notExists' => 'notExists',
            default => $type,
        };
    }

    private function compare(
        mixed $actual,
        string $op,
        mixed $expected,
        Entity $entity,
        string $attribute,
    ): bool {
        return match ($op) {
            'equals' => $this->looseEquals($actual, $expected),
            'notEquals' => !$this->looseEquals($actual, $expected),
            'contains' => is_string($actual) && is_string($expected) && str_contains($actual, $expected),
            'notContains' => is_string($actual) && is_string($expected) && !str_contains($actual, $expected),
            'startsWith' => is_string($actual) && is_string($expected) && str_starts_with($actual, $expected),
            'endsWith' => is_string($actual) && is_string($expected) && str_ends_with($actual, $expected),
            'like' => is_string($actual) && is_string($expected) && $this->sqlLike($actual, $expected),
            'in' => is_array($expected) && $this->inList($actual, $expected),
            'notIn' => is_array($expected) && !$this->inList($actual, $expected),
            'greaterThan' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            'greaterThanOrEqual' => is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
            'lessThan' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            'lessThanOrEqual' => is_numeric($actual) && is_numeric($expected) && (float) $actual <= (float) $expected,
            'isTrue' => $actual === true || $actual === 1 || $actual === '1' || $actual === 'true',
            'isFalse' => $actual === false || $actual === 0 || $actual === '0' || $actual === 'false' || $actual === null,
            'isNull' => $actual === null,
            'isNotNull' => $actual !== null,
            'exists' => $this->existsValue($entity, $attribute, $actual),
            'notExists' => !$this->existsValue($entity, $attribute, $actual),
            default => false,
        };
    }

    private function looseEquals(mixed $a, mixed $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a == (float) $b;
        }

        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b
                || $a === $b
                || ((string) $a === (string) $b);
        }

        return $a == $b;
    }

    /**
     * @param list<mixed> $list
     */
    private function inList(mixed $actual, array $list): bool
    {
        foreach ($list as $item) {
            if ($this->looseEquals($actual, $item)) {
                return true;
            }
        }

        return false;
    }

    private function existsValue(Entity $entity, string $attribute, mixed $actual): bool
    {
        $key = $this->valueKeyFromAttribute($attribute);

        if ($key !== null) {
            return $this->hasBagKey($entity, $key)
                && $actual !== null
                && $actual !== '';
        }

        return $actual !== null && $actual !== '';
    }

    private function sqlLike(string $actual, string $pattern): bool
    {
        $regex = '/^' . str_replace(
            ['%', '_'],
            ['.*', '.'],
            preg_quote($pattern, '/')
        ) . '$/ui';

        return (bool) preg_match($regex, $actual);
    }
}
