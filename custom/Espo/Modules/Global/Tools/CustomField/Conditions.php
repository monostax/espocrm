<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Tools\CustomField;

use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\Entity;

/** Small, shared PHP/JS condition contract. Missing conditions are unconditional. */
class Conditions
{
    public static function validate(mixed $condition, int $depth = 0): void
    {
        if ($condition === null) {
            return;
        }
        if ($depth > 4 || !(is_object($condition) || is_array($condition))) {
            throw new BadRequest('Invalid custom field condition.');
        }
        $node = (array) $condition;
        foreach (['all', 'any'] as $group) {
            if (array_key_exists($group, $node)) {
                if (count($node) !== 1 || !is_array($node[$group]) || !array_is_list($node[$group]) ||
                    count($node[$group]) < 1 || count($node[$group]) > 20) {
                    throw new BadRequest('Condition groups require 1–20 conditions.');
                }
                foreach ($node[$group] as $child) {
                    if ($child === null) {
                        throw new BadRequest('Empty condition in group.');
                    }
                    self::validate($child, $depth + 1);
                }
                return;
            }
        }
        if (!is_string($node['attribute'] ?? null) || !preg_match('/^[a-z][a-zA-Z0-9_]*$/', $node['attribute'])) {
            throw new BadRequest('Conditions must reference a direct host attribute.');
        }
        if (in_array($node['operator'] ?? null, ['isEmpty', 'isFilled', 'isTrue', 'isFalse'], true)) {
            if (count($node) !== 2) {
                throw new BadRequest('This condition operator does not take a value.');
            }
            return;
        }
        if (count($node) !== 3 || !in_array($node['operator'] ?? null, ['equals', 'in', 'containsAny', 'containsAll'], true) ||
            !array_key_exists('value', $node)) {
            throw new BadRequest('Unsupported condition operator or value.');
        }
        $values = $node['operator'] === 'equals' ? [$node['value']] : $node['value'];
        if (!is_array($values) || !array_is_list($values) || count($values) < 1 || count($values) > 100) {
            throw new BadRequest('Condition values must be a non-empty list.');
        }
        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new BadRequest('Condition values must be non-empty strings.');
            }
        }
    }

    /** Unknown attributes remain unknown for stage configuration compatibility checks. */
    public static function evaluate(mixed $condition, array $context, bool $partial = false): ?bool
    {
        if ($condition === null) {
            return true;
        }
        $node = (array) $condition;
        foreach (['all', 'any'] as $group) {
            if (isset($node[$group])) {
                $results = array_map(fn ($child) => self::evaluate($child, $context, $partial), $node[$group]);
                $decisive = $group === 'any';
                if (in_array($decisive, $results, true)) {
                    return $decisive;
                }
                return in_array(null, $results, true) ? null : !$decisive;
            }
        }
        $attribute = $node['attribute'] ?? '';
        if (!array_key_exists($attribute, $context) && $partial) {
            return null;
        }
        $value = $context[$attribute] ?? null;
        $empty = $value === null || (is_string($value) && trim($value) === '') || $value === [];
        return match ($node['operator'] ?? '') {
            'equals' => $value === $node['value'],
            'in' => in_array($value, $node['value'], true),
            'containsAny' => is_array($value) && count(array_filter($node['value'], fn ($item) => in_array($item, $value, true))) > 0,
            'containsAll' => is_array($value) && count(array_filter($node['value'], fn ($item) => in_array($item, $value, true))) === count($node['value']),
            'isEmpty' => $empty,
            'isFilled' => !$empty,
            'isTrue' => $value === true,
            'isFalse' => $value === false,
            default => false,
        };
    }

    public static function leaves(mixed $condition): array
    {
        if ($condition === null) {
            return [];
        }
        $node = (array) $condition;
        if (isset($node['attribute'])) {
            return [$node];
        }
        $out = [];
        foreach ($node['all'] ?? $node['any'] ?? [] as $child) {
            array_push($out, ...self::leaves($child));
        }
        return $out;
    }

    public static function required(array $field, array $context): bool
    {
        return self::evaluate($field['appliesWhen'] ?? null, $context) === true &&
            (($field['isRequired'] ?? false) ||
                (isset($field['requiredWhen']) && self::evaluate($field['requiredWhen'], $context) === true));
    }

    /** Read the effective host values, including incoming changes and deferred link IDs. */
    public static function context(Entity $entity, array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            foreach (['appliesWhen', 'requiredWhen'] as $effect) {
                foreach (self::leaves($field[$effect] ?? null) as $leaf) {
                    $attribute = $leaf['attribute'];
                    $out[$attribute] = $entity->get($attribute);
                }
            }
        }
        return $out;
    }
}
