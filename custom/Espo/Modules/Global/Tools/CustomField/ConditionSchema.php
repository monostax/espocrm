<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Tools\CustomField;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Utils\Metadata;

/** The editor and definition validator consume the same direct-field capabilities. */
class ConditionSchema
{
    public function __construct(private Metadata $metadata) {}

    public function getAttributes(string $entityType): array
    {
        $defs = $this->metadata->get(['entityDefs', $entityType]) ?? [];
        $out = [];
        foreach ($defs['fields'] ?? [] as $name => $field) {
            if (!preg_match('/^[a-z][a-zA-Z0-9_]*$/', $name) ||
                ($field['notStorable'] ?? false) || ($field['disabled'] ?? false) ||
                ($field['utility'] ?? false) || ($field['readOnly'] ?? false)) {
                continue;
            }
            $type = $field['type'] ?? '';
            $operators = match ($type) {
                'enum', 'link' => ['in', 'equals', 'isEmpty', 'isFilled'],
                'multiEnum' => ['containsAny', 'containsAll', 'isEmpty', 'isFilled'],
                'bool' => ['isTrue', 'isFalse'],
                'varchar', 'text', 'url', 'email', 'phone', 'date', 'datetime', 'int', 'float', 'currency' => ['isEmpty', 'isFilled'],
                default => [],
            };
            if (!$operators) {
                continue;
            }
            $attribute = $type === 'link' ? $name . 'Id' : $name;
            $item = ['field' => $name, 'type' => $type, 'operators' => $operators];
            if ($type === 'link') {
                $link = $defs['links'][$name] ?? [];
                if (($link['type'] ?? '') !== 'belongsTo' || !isset($link['entity'])) {
                    continue;
                }
                $item['entity'] = $link['entity'];
                $item['tenantScoped'] = $this->isTenantScoped($link['entity']);
            }
            if (in_array($type, ['enum', 'multiEnum'], true)) {
                $item['options'] = $this->options($field);
                $item['allowCustomOptions'] = (bool) ($field['allowCustomOptions'] ?? false);
            }
            $out[$attribute] = $item;
        }
        return $out;
    }

    public function isTenantScoped(string $entityType): bool
    {
        return $entityType === 'OpportunityStage' ||
            $this->metadata->get(['entityDefs', $entityType, 'fields', 'tenant']) !== null;
    }

    public function validateLeaf(array $leaf, array $attributes): array
    {
        $descriptor = $attributes[$leaf['attribute']] ?? null;
        if (!$descriptor || !in_array($leaf['operator'], $descriptor['operators'], true)) {
            throw new BadRequest('Unsupported custom field condition attribute or operator: ' . $leaf['attribute']);
        }
        if (isset($leaf['value']) && in_array($descriptor['type'], ['enum', 'multiEnum'], true) &&
            !$descriptor['allowCustomOptions']) {
            $values = is_array($leaf['value']) ? $leaf['value'] : [$leaf['value']];
            if (array_diff($values, $descriptor['options'])) {
                throw new BadRequest('Condition contains an unsupported option for ' . $descriptor['field'] . '.');
            }
        }
        return $descriptor;
    }

    private function options(array $field): array
    {
        $visited = [];
        while (!isset($field['options']) && isset($field['optionsReference'])) {
            $reference = $field['optionsReference'];
            if (!is_string($reference) || isset($visited[$reference]) || count($visited) > 5) {
                return [];
            }
            $visited[$reference] = true;
            $parts = explode('.', $reference);
            if (count($parts) !== 2) {
                return [];
            }
            $field = $this->metadata->get(['entityDefs', $parts[0], 'fields', $parts[1]]) ?? [];
        }
        return array_values(array_filter($field['options'] ?? [], fn ($value) => is_string($value) && $value !== ''));
    }
}
