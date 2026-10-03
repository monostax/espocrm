<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Tools;

use Espo\Core\Exceptions\BadRequest;

/** Closed vocabulary. Aliases normalize to the same stored direction. */
class Predicates
{
    public static function schema(): array
    {
        $people = ['User', 'Contact', 'Lead'];
        $content = ['Document', 'KnowledgeBaseArticle'];
        $temporal = ['since' => 'date', 'until' => 'date'];
        $definitions = [
            'works_at' => [$people, ['Account'], 'employs', ['employed_by'], [...$temporal, 'role' => 'string']],
            'reports_to' => [$people, $people, 'manages', [], $temporal],
            'decides_for' => [$people, ['Account', 'Opportunity'], 'decision maker', [], [...$temporal, 'role' => 'string']],
            'deal_for' => [['Opportunity'], ['Account'], 'has deal', [], []],
            'deal_with' => [['Opportunity'], $people, 'involved in deal', [], ['role' => 'string']],
            'introduced_by' => ['*', $people, 'introduced', [], ['on' => 'date']],
            'attended' => [$people, ['Meeting', 'Call', 'Event'], 'attendees', [], ['on' => 'date']],
            'part_of' => ['*', '*', 'contains', [], []],
            'about' => [$content, '*', 'subject of', [], []],
            'contradicts' => [$content, $content, 'contradicted by', [], []],
            'supersedes' => [$content, $content, 'superseded by', ['replaces'], []],
        ];
        $schema = [];
        foreach ($definitions as $predicate => [$subjects, $objects, $inverse, $aliases, $qualifiers]) {
            $schema[$predicate] = compact('subjects', 'objects', 'inverse', 'aliases', 'qualifiers');
        }
        return $schema;
    }

    public static function validate(string $predicate, string $subjectType, string $objectType, mixed $qualifiers): array
    {
        $schema = self::schema();
        foreach ($schema as $name => $definition) {
            if (in_array($predicate, $definition['aliases'], true)) $predicate = $name;
        }
        $def = $schema[$predicate] ?? null;
        if (!$def) throw new BadRequest('Unknown predicate.');
        foreach (['subjects' => $subjectType, 'objects' => $objectType] as $endpoint => $type) {
            if ($def[$endpoint] !== '*' && !in_array($type, $def[$endpoint], true)) throw new BadRequest('Invalid predicate endpoint type.');
        }
        if (!$qualifiers instanceof \stdClass && (!is_array($qualifiers) || ($qualifiers && array_is_list($qualifiers)))) {
            throw new BadRequest('Qualifiers must be an object.');
        }
        $qualifiers = (array) $qualifiers;
        foreach ($qualifiers as $key => $value) {
            $kind = $def['qualifiers'][$key] ?? null;
            if (!$kind || !is_string($value) || strlen($value) > 255) throw new BadRequest('Invalid qualifier.');
            if ($kind === 'date') {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                if (!$date || $date->format('Y-m-d') !== $value) throw new BadRequest('Invalid qualifier date.');
            }
        }
        if (isset($qualifiers['since'], $qualifiers['until']) && $qualifiers['since'] > $qualifiers['until']) throw new BadRequest('Invalid date interval.');
        ksort($qualifiers);
        return [$predicate, (object) $qualifiers];
    }
}
