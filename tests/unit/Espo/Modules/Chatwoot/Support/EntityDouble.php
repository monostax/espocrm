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

namespace tests\unit\Espo\Modules\Chatwoot\Support;

use Espo\ORM\Entity;
use stdClass;

/**
 * Attribute-bag {@see Entity} double with real get/set semantics.
 *
 * A PHPUnit mock cannot express "set() then read it back", which is what
 * save-time hooks do, and a real ORM entity would need a full metadata
 * bootstrap. Nested entities (link values) can be stored as attributes.
 */
class EntityDouble implements Entity
{
    /** @param array<string, mixed> $attributes */
    public function __construct(
        private array $attributes = [],
        private string $entityType = 'TestEntity'
    ) {}

    public function get(string $attribute): mixed
    {
        return $this->attributes[$attribute] ?? null;
    }

    public function set($attribute, $value = null): static
    {
        if (is_array($attribute) || $attribute instanceof stdClass) {
            foreach ((array) $attribute as $k => $v) {
                $this->attributes[$k] = $v;
            }

            return $this;
        }

        $this->attributes[$attribute] = $value;

        return $this;
    }

    public function has(string $attribute): bool
    {
        return array_key_exists($attribute, $this->attributes);
    }

    public function getId(): string
    {
        return (string) ($this->attributes['id'] ?? 'test-id');
    }

    public function hasId(): bool
    {
        return isset($this->attributes['id']);
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    // --- Remainder of the interface, unused by current tests ---

    public function reset(): void {}

    public function setMultiple(array|stdClass $valueMap): static
    {
        return $this->set((array) $valueMap);
    }

    public function clear(string $attribute): void
    {
        unset($this->attributes[$attribute]);
    }

    public function getAttributeList(): array
    {
        return array_keys($this->attributes);
    }

    public function getRelationList(): array
    {
        return [];
    }

    public function hasAttribute(string $attribute): bool
    {
        return $this->has($attribute);
    }

    public function hasRelation(string $relation): bool
    {
        return false;
    }

    public function getAttributeType(string $attribute): ?string
    {
        return null;
    }

    public function getRelationType(string $relation): ?string
    {
        return null;
    }

    public function isNew(): bool
    {
        return false;
    }

    public function setAsFetched(): void {}

    public function isFetched(): bool
    {
        return true;
    }

    public function isAttributeChanged(string $name): bool
    {
        return false;
    }

    public function getFetched(string $attribute): mixed
    {
        return null;
    }

    public function hasFetched(string $attribute): bool
    {
        return false;
    }

    public function setFetched(string $attribute, $value): static
    {
        return $this;
    }

    public function getValueMap(): stdClass
    {
        return (object) $this->attributes;
    }

    public function setAsNotNew(): void {}

    public function updateFetchedValues(): void {}
}
