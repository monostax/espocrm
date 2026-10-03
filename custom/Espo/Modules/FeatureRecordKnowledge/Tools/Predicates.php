<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Tools;

use Espo\Core\Exceptions\BadRequest;

/** Platform defaults live in module metadata; custom definitions use the same validator. */
class Predicates
{
    public static function schema(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/../Resources/metadata/app/recordKnowledgePredicates.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function validate(string $predicate, string $subjectType, string $objectType, mixed $qualifiers): array
    {
        $name = str_starts_with($predicate, 'builtin:') ? substr($predicate, 8) : $predicate;
        foreach (self::schema() as $code => $def) if (in_array($name, $def['aliases'], true) || $name === $def['label']) $name = $code;
        $definition = self::schema()[$name] ?? null;
        if (!$definition) throw new BadRequest('Unknown predicate.');
        return ['builtin:' . $name, self::values($definition, $subjectType, $objectType, $qualifiers)];
    }

    public static function values(array $definition, string $subjectType, string $objectType, mixed $qualifiers): \stdClass
    {
        foreach (['subjects' => $subjectType, 'objects' => $objectType] as $endpoint => $type) {
            if ($definition[$endpoint] !== '*' && !in_array($type, $definition[$endpoint], true)) throw new BadRequest('Invalid predicate endpoint type.');
        }
        $schema = $definition['qualifierSchema'] ?? QualifierSchema::shorthand($definition['qualifiers'] ?? []);
        if (!$schema instanceof \stdClass) $schema = json_decode(json_encode($schema));
        return QualifierSchema::validate($qualifiers, $schema);
    }
}
