<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\ORM\Entity;
use InvalidArgumentException;
use stdClass;

class ActionConditionEvaluator
{
    private const MAX_DEPTH = 5;
    private const MAX_LEAVES = 50;

    /** @var list<string> */
    private const OPERATORS = [
        'equals',
        'notEquals',
        'contains',
        'notContains',
        'startsWith',
        'endsWith',
        'in',
        'greaterThan',
        'greaterThanOrEquals',
        'lessThan',
        'lessThanOrEquals',
        'isTrue',
        'isFalse',
        'isNull',
        'isNotNull',
    ];

    /** @var list<string> */
    private const NO_VALUE_OPERATORS = [
        'isTrue',
        'isFalse',
        'isNull',
        'isNotNull',
    ];

    public function __construct(
        private CustomFieldsBag $customFieldsBag,
        private RestrictedFormulaRunner $formulaRunner,
    ) {}

    public function isEmpty(mixed $conditions): bool
    {
        if ($conditions instanceof stdClass) {
            $conditions = (array) $conditions;
        }

        if ($conditions === null || $conditions === '' || $conditions === []) {
            return true;
        }

        return is_array($conditions)
            && ($conditions['type'] ?? null) === 'and'
            && ($conditions['value'] ?? null) === [];
    }

    public function assertValid(mixed $conditions): void
    {
        $conditions = $this->toArray($conditions);

        if (!is_array($conditions) || $conditions === []) {
            throw new InvalidArgumentException('conditionsGroup must be a condition or an AND/OR group.');
        }

        $leafCount = 0;

        if (array_is_list($conditions)) {
            foreach ($conditions as $node) {
                $this->assertNode($node, 1, $leafCount);
            }
        } else {
            $this->assertNode($conditions, 1, $leafCount);
        }
    }

    /** @param array<string, mixed>|stdClass|null $variables */
    public function matches(
        Entity $target,
        mixed $conditions,
        array|stdClass|null $variables = null,
    ): bool
    {
        $this->assertValid($conditions);
        $conditions = $this->toArray($conditions);

        if (!is_array($conditions)) {
            return false;
        }

        if (array_is_list($conditions)) {
            foreach ($conditions as $node) {
                if (!$this->matchesNode($target, $node, $variables)) {
                    return false;
                }
            }

            return $conditions !== [];
        }

        return $this->matchesNode($target, $conditions, $variables);
    }

    private function assertNode(mixed $node, int $depth, int &$leafCount): void
    {
        if ($node instanceof stdClass) {
            $node = $this->toArray($node);
        }

        if (!is_array($node) || array_is_list($node)) {
            throw new InvalidArgumentException('Each condition must be an object.');
        }

        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('conditionsGroup exceeds the maximum nesting depth.');
        }

        $type = $node['type'] ?? 'equals';

        if (in_array($type, ['and', 'or'], true)) {
            $children = $node['value'] ?? null;
            if (!is_array($children) || $children === [] || !array_is_list($children)) {
                throw new InvalidArgumentException("{$type} groups require at least one condition.");
            }

            foreach ($children as $child) {
                $this->assertNode($child, $depth + 1, $leafCount);
            }

            return;
        }

        if (!is_string($type) || !in_array($type, self::OPERATORS, true)) {
            throw new InvalidArgumentException("Unsupported condition operator '{$type}'.");
        }

        $attribute = $node['attribute'] ?? null;
        if (!is_string($attribute) || trim($attribute) === '') {
            throw new InvalidArgumentException('Every condition requires a target field.');
        }

        if (str_contains($attribute, '.') && !$this->customFieldsBag->isBagAttribute($attribute)) {
            throw new InvalidArgumentException('Related-record fields are not supported in action conditions.');
        }

        $valueFormula = $node['valueFormula'] ?? null;
        if ($valueFormula !== null && (!is_string($valueFormula) || trim($valueFormula) === '')) {
            throw new InvalidArgumentException('valueFormula must be a non-empty formula.');
        }

        if (is_string($valueFormula)) {
            if (in_array($type, self::NO_VALUE_OPERATORS, true)) {
                throw new InvalidArgumentException("Operator '{$type}' does not accept a value formula.");
            }

            $this->formulaRunner->assertScriptAllowed(
                $valueFormula,
                RestrictedFormulaRunner::MODE_CONDITION,
            );
        }

        if (
            !in_array($type, self::NO_VALUE_OPERATORS, true) &&
            !array_key_exists('value', $node) &&
            !is_string($valueFormula)
        ) {
            throw new InvalidArgumentException("Operator '{$type}' requires a value.");
        }

        if ($type === 'in' && !is_string($valueFormula) && !is_array($node['value'] ?? null)) {
            throw new InvalidArgumentException("Operator 'in' requires a list value.");
        }

        $leafCount++;
        if ($leafCount > self::MAX_LEAVES) {
            throw new InvalidArgumentException('conditionsGroup has too many conditions.');
        }
    }

    private function toArray(mixed $value): mixed
    {
        if (!$value instanceof stdClass) {
            return $value;
        }

        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed>|stdClass|null $variables
     */
    private function matchesNode(
        Entity $target,
        mixed $node,
        array|stdClass|null $variables,
    ): bool {
        $node = $this->toArray($node);

        if (!is_array($node) || array_is_list($node)) {
            return false;
        }

        $type = $node['type'] ?? 'equals';

        if ($type === 'and') {
            foreach ($node['value'] as $child) {
                if (!$this->matchesNode($target, $child, $variables)) {
                    return false;
                }
            }

            return true;
        }

        if ($type === 'or') {
            foreach ($node['value'] as $child) {
                if ($this->matchesNode($target, $child, $variables)) {
                    return true;
                }
            }

            return false;
        }

        $valueFormula = $node['valueFormula'] ?? null;
        if (is_string($valueFormula) && trim($valueFormula) !== '') {
            $node['value'] = $this->formulaRunner->run(
                $valueFormula,
                $target,
                $variables,
                RestrictedFormulaRunner::MODE_CONDITION,
            );
            unset($node['valueFormula']);
        }

        return $this->customFieldsBag->matchWhereItem($node, $target);
    }
}
