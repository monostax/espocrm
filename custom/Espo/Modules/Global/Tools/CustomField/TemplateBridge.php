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

use Espo\ORM\Entity;
use Stringable;

/**
 * Applies custom-field bags to Email / WhatsApp template rendering.
 *
 * Storage stays flat (`address.city` keys). At render time dotted keys are
 * nested for Handlebars and also offered as classic `{Type.customFields.k}` leaves.
 */
class TemplateBridge
{
    /** @var array<int, array{entity: Entity, attribute: string, original: mixed}> */
    private array $expandedStack = [];

    public function __construct(
        private MetaProvider $metaProvider,
    ) {}

    public function getAttributeName(): string
    {
        return $this->metaProvider->getAttributeName();
    }

    public function isEntityEnabled(string $entityType): bool
    {
        return $this->metaProvider->isEntityEnabled($entityType);
    }

    /**
     * Nested bag for Handlebars (`{{customFields.address.city}}`).
     *
     * @return array<string, mixed>
     */
    public function getNestedBag(Entity $entity): array
    {
        if (!$this->isEntityEnabled($entity->getEntityType())) {
            return [];
        }

        $attr = $this->getAttributeName();
        $bag = $entity->get($attr);

        return $this->metaProvider->expandForTemplate(
            is_array($bag) || is_object($bag) ? $bag : null
        );
    }

    /**
     * Expand customFields in-place on the entity for Htmlizer.
     * Call {@see restoreExpanded()} after render (stack-safe).
     */
    public function expandInPlace(Entity $entity): void
    {
        if (!$this->isEntityEnabled($entity->getEntityType())) {
            return;
        }

        $attr = $this->getAttributeName();

        foreach ($this->expandedStack as $item) {
            if ($item['entity'] === $entity && $item['attribute'] === $attr) {
                return;
            }
        }

        $original = $entity->get($attr);

        $nested = $this->metaProvider->expandForTemplate(
            is_array($original) || is_object($original) ? $original : null
        );

        $this->expandedStack[] = [
            'entity' => $entity,
            'attribute' => $attr,
            'original' => $original,
        ];

        $entity->set($attr, $nested);
    }

    /**
     * Restore bags previously expanded via {@see expandInPlace()}.
     */
    public function restoreExpanded(): void
    {
        while ($this->expandedStack !== []) {
            $item = array_pop($this->expandedStack);

            $item['entity']->set($item['attribute'], $item['original']);
        }
    }

    /**
     * Classic placeholders `{Type.customFields.address.city}` → string values.
     *
     * Uses the storage bag, not an expanded nested copy.
     *
     * @return array<string, string> placeholder => value
     */
    public function getClassicReplacements(Entity $entity, string $typeKey): array
    {
        if (!$this->isEntityEnabled($entity->getEntityType())) {
            return [];
        }

        $attr = $this->getAttributeName();
        $bag = $this->resolveFlatBag($entity, $attr);

        if ($bag === []) {
            return [];
        }

        $out = [];
        $prefix = '{' . $typeKey . '.' . $attr . '.';

        foreach ($bag as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            $formatted = $this->formatLeaf($value);

            if ($formatted === null) {
                continue;
            }

            $out[$prefix . $key . '}'] = $formatted;
        }

        return $out;
    }

    /**
     * Replace classic custom-field placeholders in template text.
     */
    public function applyClassic(string $text, Entity $entity, string $typeKey): string
    {
        $map = $this->getClassicReplacements($entity, $typeKey);

        if ($map === []) {
            return $text;
        }

        return str_replace(array_keys($map), array_values($map), $text);
    }

    /**
     * Expand bag then restore — for TemplateRenderer paths that take Entity.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function withExpanded(Entity $entity, callable $fn): mixed
    {
        $this->expandInPlace($entity);

        try {
            return $fn();
        } finally {
            $this->restoreExpanded();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveFlatBag(Entity $entity, string $attr): array
    {
        // Prefer stack original if this entity was expanded in-place.
        foreach (array_reverse($this->expandedStack) as $item) {
            if ($item['entity'] === $entity && $item['attribute'] === $attr) {
                return $this->toFlatArray($item['original']);
            }
        }

        return $this->toFlatArray($entity->get($attr));
    }

    /**
     * @return array<string, mixed>
     */
    private function toFlatArray(mixed $bag): array
    {
        if ($bag === null) {
            return [];
        }

        if (is_object($bag)) {
            $bag = get_object_vars($bag);
        }

        if (!is_array($bag)) {
            return [];
        }

        /** @var array<string, mixed> $bag */
        return $bag;
    }

    private function formatLeaf(mixed $value): ?string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            return $value;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if (is_array($value)) {
            $parts = [];

            foreach ($value as $item) {
                if (is_string($item) || is_int($item) || is_float($item)) {
                    $parts[] = (string) $item;
                } elseif (is_bool($item)) {
                    $parts[] = $item ? '1' : '0';
                }
            }

            return implode(', ', $parts);
        }

        if (is_object($value)) {
            return null;
        }

        return null;
    }
}
