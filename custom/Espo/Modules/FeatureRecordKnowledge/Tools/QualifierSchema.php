<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Tools;

use Espo\Core\Exceptions\BadRequest;
use stdClass;

/** Deliberately bounded declarative JSON Schema subset; no evaluation or external refs. */
class QualifierSchema
{
    public static function definition(mixed $input): stdClass
    {
        if (!$input instanceof stdClass) throw new BadRequest('Qualifier schema must be a JSON object.');
        if (array_diff(array_keys(get_object_vars($input)), ['type', 'properties', 'required', 'additionalProperties']) ||
            ($input->type ?? null) !== 'object' || ($input->additionalProperties ?? null) !== false ||
            !($input->properties ?? null) instanceof stdClass) throw new BadRequest('Unsupported qualifier schema features.');
        $required = $input->required ?? [];
        if (!is_array($required) || !array_is_list($required)) throw new BadRequest('Invalid required qualifiers.');
        foreach ($required as $key) if (!is_string($key)) throw new BadRequest('Invalid required qualifier name.');
        if (count($required) !== count(array_unique($required))) throw new BadRequest('Duplicate required qualifier.');
        $properties = get_object_vars($input->properties);
        if (count($properties) > 20) throw new BadRequest('At most 20 qualifier properties.');
        foreach ($properties as $key => $rule) {
            if (!preg_match('/^[a-z][a-zA-Z0-9_]{0,47}$/D', $key) || !$rule instanceof stdClass ||
                array_diff(array_keys(get_object_vars($rule)), ['type', 'enum', 'format', 'maxLength', 'minimum', 'maximum']) ||
                !in_array($rule->type ?? null, ['string', 'integer', 'number', 'boolean'], true)) throw new BadRequest('Unsupported qualifier property.');
            if (isset($rule->format) && ($rule->type !== 'string' || $rule->format !== 'date')) throw new BadRequest('Only string date format is supported.');
            if (isset($rule->maxLength) && ($rule->type !== 'string' || !is_int($rule->maxLength) || $rule->maxLength < 1 || $rule->maxLength > 4000)) throw new BadRequest('Invalid qualifier maxLength.');
            foreach (['minimum', 'maximum'] as $bound) {
                if (isset($rule->$bound) && (!in_array($rule->type, ['integer', 'number'], true) || !is_numeric($rule->$bound) || is_string($rule->$bound))) throw new BadRequest('Invalid numeric bound.');
            }
            if (isset($rule->minimum, $rule->maximum) && $rule->minimum > $rule->maximum) throw new BadRequest('Invalid numeric interval.');
            if (isset($rule->enum)) {
                if (!is_array($rule->enum) || !array_is_list($rule->enum) || !$rule->enum || count($rule->enum) > 50) throw new BadRequest('Invalid qualifier enum.');
                foreach ($rule->enum as $value) self::value($value, (object) array_diff_key(get_object_vars($rule), ['enum' => true]));
            }
        }
        foreach ($required as $key) if (!is_string($key) || !array_key_exists($key, $properties)) throw new BadRequest('Unknown required qualifier.');
        return $input;
    }

    public static function fingerprint(mixed $value): string
    {
        if ($value instanceof stdClass) {
            $data = get_object_vars($value); ksort($data);
            return '{' . implode(',', array_map(fn ($key, $v) => json_encode($key) . ':' . self::fingerprint($v), array_keys($data), $data)) . '}';
        }
        if (is_array($value)) return '[' . implode(',', array_map(self::fingerprint(...), $value)) . ']';
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    public static function validate(mixed $values, stdClass $schema): stdClass
    {
        self::definition($schema);
        if (!$values instanceof stdClass && (!is_array($values) || ($values && array_is_list($values)))) throw new BadRequest('Qualifiers must be an object.');
        $values = (array) $values;
        foreach ($schema->required ?? [] as $key) if (!array_key_exists($key, $values)) throw new BadRequest('Missing required qualifier.');
        foreach ($values as $key => $value) {
            if (!isset($schema->properties->$key)) throw new BadRequest('Unknown qualifier.');
            self::value($value, $schema->properties->$key);
        }
        if (isset($values['since'], $values['until']) && $values['since'] > $values['until']) throw new BadRequest('Invalid date interval.');
        ksort($values);
        return (object) $values;
    }

    private static function value(mixed $value, stdClass $rule): void
    {
        $valid = match ($rule->type) {
            'string' => is_string($value) && mb_strlen($value) <= ($rule->maxLength ?? 4000),
            'integer' => is_int($value), 'number' => (is_int($value) || is_float($value)) && is_finite((float) $value),
            'boolean' => is_bool($value),
        };
        if (!$valid || (isset($rule->enum) && !in_array($value, $rule->enum, true)) ||
            (isset($rule->minimum) && $value < $rule->minimum) || (isset($rule->maximum) && $value > $rule->maximum)) throw new BadRequest('Invalid qualifier value.');
        if (($rule->format ?? null) === 'date') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (!$date || $date->format('Y-m-d') !== $value) throw new BadRequest('Invalid qualifier date.');
        }
    }

    public static function shorthand(array $properties): stdClass
    {
        $rules = new stdClass();
        foreach ($properties as $key => $kind) $rules->$key = $kind === 'date'
            ? (object) ['type' => 'string', 'format' => 'date'] : (object) ['type' => $kind, 'maxLength' => 255];
        return (object) ['type' => 'object', 'properties' => $rules, 'required' => [], 'additionalProperties' => false];
    }
}
