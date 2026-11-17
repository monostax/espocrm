<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools\RecordQuery;

use DateTime;
use DateTimeZone;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Select\Where\CheckerFactory;
use Espo\Core\Select\Where\ConverterFactory;
use Espo\Core\Select\Where\Item;
use Espo\Core\Select\Where\Params;
use Espo\Core\Utils\Config\ApplicationConfig;
use Espo\Entities\User;
use Espo\ORM\Query\Select;
use Espo\ORM\Query\SelectBuilder;
use Exception;

/**
 * Record query text to an ORM where clause.
 *
 * Access: every condition goes through the stock where checker and converter of the entity it names, so field-level
 * access and custom filters behave exactly as in a regular list. Every related entity is read through its own strict
 * access control, so a query can only match on related records the user can open, and a related record must belong to
 * the tenant of the record it hangs from. The result only narrows the list it is applied to.
 */
class Compiler
{
    private const MAX_LENGTH = 2000;
    /** Relationship hops, e.g. opportunities.account.assignedUser. */
    private const MAX_DEPTH = 3;
    private const TYPES = [
        'text' => ['=' => 'equals', '!=' => 'notEquals', '~' => 'like', '!~' => 'notLike', 'in' => 'in', 'notIn' => 'notIn'],
        'array' => ['=' => 'arrayAnyOf', '!=' => 'arrayNoneOf', 'in' => 'arrayAnyOf', 'notIn' => 'arrayNoneOf'],
        'bool' => ['=' => 'isTrue', '!=' => 'isFalse'],
        'number' => [
            '=' => 'equals', '!=' => 'notEquals', '>' => 'greaterThan', '>=' => 'greaterThanOrEquals',
            '<' => 'lessThan', '<=' => 'lessThanOrEquals', 'in' => 'in', 'notIn' => 'notIn',
        ],
        'datetime' => ['=' => 'on', '>' => 'after', '>=' => 'after', '<' => 'before', '<=' => 'before'],
    ];

    private string $timeZone = 'UTC';

    public function __construct(
        private Schema $schema,
        private User $user,
        private ConverterFactory $converters,
        private CheckerFactory $checkers,
        private SelectBuilderFactory $selects,
        private ApplicationConfig $config,
    ) {}

    /**
     * @return array<string, Select> A where clause for a query on $entityType.
     * @throws QueryError
     */
    public function compile(string $entityType, string $query, ?string $timeZone = null): array
    {
        if (strlen($query) > self::MAX_LENGTH) throw QueryError::at('too_long', self::MAX_LENGTH);
        $this->timeZone = $timeZone ?: $this->config->getTimeZone();
        try {
            new DateTimeZone($this->timeZone);
        } catch (Exception) {
            $this->timeZone = 'UTC';
        }
        return ['id=s' => $this->select($entityType, (new Parser())->parse($query), 0, false)];
    }

    /**
     * IDs of $entityType matching the node. Joins a condition needs stay inside this sub-select.
     *
     * @param ?array<int, mixed> $node
     */
    private function select(string $entityType, ?array $node, int $depth, bool $accessControl): Select
    {
        $builder = $accessControl
            ? $this->selects->create()->from($entityType)->withStrictAccessControl()->buildQueryBuilder()->order([])
            : SelectBuilder::create()->from($entityType);
        $builder->select(['id']);
        if ($node) $builder->where($this->clause($entityType, $node, $builder, $depth));
        return $builder->build();
    }

    /**
     * @param array<int, mixed> $node
     * @return array<int|string, mixed>
     */
    private function clause(string $entityType, array $node, SelectBuilder $builder, int $depth): array
    {
        return match ($node[0]) {
            'and', 'or' => [strtoupper($node[0]) => array_map(
                fn (array $child) => $this->clause($entityType, $child, $builder, $depth), $node[1],
            )],
            'not' => ['id!=s' => $this->select($entityType, $node[1], $depth, false)],
            'rel' => $this->related($entityType, $node[1], $node[2], $depth, $node[3]),
            default => $this->compare($entityType, $node, $builder, $depth),
        };
    }

    /**
     * Has a related record (one the user can read) matching $inner; all of $inner applies to the same record.
     *
     * @param list<string> $path
     * @param ?array<int, mixed> $inner
     * @return array<string, Select>
     */
    private function related(string $entityType, array $path, ?array $inner, int $depth, int $position): array
    {
        $name = array_shift($path);
        if ($depth >= self::MAX_DEPTH) throw QueryError::at('too_deep', $position, $name);
        if ($name === 'related') return $this->relationRecords($entityType, $path, $inner, $depth, $position);
        $link = $this->schema->link($entityType, $name) ?? throw QueryError::at('unknown_link', $position, $name);
        $matching = $this->select($link['entity'], $path ? ['rel', $path, $inner, $position] : $inner, $depth + 1, true);
        $where = ['recordQueryLink.id=s' => $matching];
        // Never match across tenants, whatever the links in the data say.
        if ($link['tenant']) $where['recordQueryLink.tenantId=:'] = 'tenantId';
        return ['id=s' => SelectBuilder::create()->from($entityType)->select(['id'])
            ->join($name, 'recordQueryLink')->where($where)->build()];
    }

    /**
     * related.<Type>(...): connected to a matching <Type> record by a confirmed relation record, in either direction.
     * Both ends must belong to the relation's tenant, and a relation backed by a document only counts when the user
     * can read that document.
     *
     * @param list<string> $path
     * @param ?array<int, mixed> $inner
     * @return array<string, mixed>
     */
    private function relationRecords(string $entityType, array $path, ?array $inner, int $depth, int $position): array
    {
        // related.Account IS EMPTY, related.Account = "Acme": the type arrives as the compared name.
        if (!$path && $inner && $inner[0] === 'cmp' && count($inner[1]) === 1 && in_array($inner[1][0], $this->schema->relatedTypes($entityType), true)) {
            return $this->compareLink($entityType, ['cmp', ['related', $inner[1][0]], $inner[2], $inner[3], $inner[4]], $depth);
        }
        $type = array_shift($path);
        if (!$type || !in_array($type, $this->schema->relatedTypes($entityType), true)) {
            throw QueryError::at('unknown_link', $position, $type ? "related.$type" : 'related');
        }
        $matching = $this->select($type, $path ? ['rel', $path, $inner, $position] : $inner, $depth + 1, true);
        $evidence = [['sourceDocumentId' => null]];
        if ($this->schema->documentsReadable()) $evidence[] = ['sourceDocumentId=s' => $this->select('Document', null, $depth, true)];
        $sides = [];
        foreach ([['subject', 'object'], ['object', 'subject']] as [$own, $other]) {
            $builder = SelectBuilder::create()->from('RecordRelation')->select([$own . 'Id'])
                ->join($entityType, 'recordQueryOwner', ['recordQueryOwner.id=:' => $own . 'Id', 'recordQueryOwner.tenantId=:' => 'tenantId'])
                ->where([
                    $own . 'Type' => $entityType, $other . 'Type' => $type, 'status' => 'confirmed',
                    $other . 'Id=s' => $matching, 'OR' => $evidence,
                ]);
            if ($this->schema->tenantOwned($type)) {
                $builder->join($type, 'recordQueryOther', ['recordQueryOther.id=:' => $other . 'Id', 'recordQueryOther.tenantId=:' => 'tenantId']);
            }
            $sides[] = ['id=s' => $builder->build()];
        }
        return ['OR' => $sides];
    }

    /**
     * @param array<int, mixed> $node
     * @return array<int|string, mixed>
     */
    private function compare(string $entityType, array $node, SelectBuilder $builder, int $depth): array
    {
        [, $path, $operator, $value, $position] = $node;
        if (count($path) > 1) {
            $field = array_pop($path);
            return $this->related($entityType, $path, ['cmp', [$field], $operator, $value, $position], $depth, $position);
        }
        $name = $path[0];
        $kind = $this->schema->kind($entityType, $name);
        if (!$kind) {
            if (!$this->schema->link($entityType, $name)) throw QueryError::at('unknown_field', $position, $name);
            return $this->compareLink($entityType, $node, $depth);
        }
        // The datetime transformer has no "not on".
        if ($kind === 'datetime' && $operator === '!=') {
            return $this->clause($entityType, ['not', ['cmp', $path, '=', $value, $position]], $builder, $depth);
        }
        $item = Item::fromRaw($this->item($kind, $name, $operator, $value, $position));
        $this->checkers->create($entityType, $this->user)
            ->check($item, Params::fromAssoc(['applyPermissionCheck' => true, 'forbidComplexExpressions' => true]));
        return $this->converters->create($entityType, $this->user)->convert($builder, $item)->getRaw();
    }

    /**
     * A relationship compared as a value: by the related record's name, by `me`, or by having any at all.
     *
     * @param array<int, mixed> $node
     * @return array<int|string, mixed>
     */
    private function compareLink(string $entityType, array $node, int $depth): array
    {
        [, $path, $operator, $value, $position] = $node;
        $positive = ['!=' => '=', 'notIn' => 'in', '!~' => '~', 'empty' => 'notEmpty'][$operator] ?? null;
        if ($positive) return ['id!=s' => $this->select($entityType, ['cmp', $path, $positive, $value, $position], $depth, false)];
        if ($operator === 'notEmpty') return $this->related($entityType, $path, null, $depth, $position);
        if (!in_array($operator, ['=', 'in', '~'], true)) throw QueryError::at('bad_operator', $position, $path[0]);
        $me = $value === ['kw', 'me'];
        return $this->related(
            $entityType, $path,
            ['cmp', [$me ? 'id' : 'name'], $operator, $me ? ['str', $this->user->getId()] : $value, $position],
            $depth, $position,
        );
    }

    /**
     * @param mixed $value A parsed value, or a list of them for in / notIn.
     * @return array<string, mixed> A raw where item.
     */
    private function item(string $kind, string $name, string $operator, mixed $value, int $position): array
    {
        if ($operator === 'empty' || $operator === 'notEmpty') {
            $empty = $operator === 'empty';
            return match ($kind) {
                'array' => ['type' => $empty ? 'arrayIsEmpty' : 'arrayIsNotEmpty', 'attribute' => $name],
                // Text is empty when null or blank, as in the stock varchar filter.
                'text', 'enum' => ['type' => $empty ? 'or' : 'and', 'value' => [
                    ['type' => $empty ? 'isNull' : 'isNotNull', 'attribute' => $name],
                    ['type' => $empty ? 'equals' : 'notEquals', 'attribute' => $name, 'value' => ''],
                ]],
                default => ['type' => $empty ? 'isNull' : 'isNotNull', 'attribute' => $name],
            };
        }
        $family = ['enum' => 'text', 'date' => 'number'][$kind] ?? $kind;
        $type = self::TYPES[$family][$operator] ?? throw QueryError::at('bad_operator', $position, $name);
        $many = in_array($operator, ['in', 'notIn'], true);
        $values = array_map(fn (array $one) => $this->value($kind, $one, $position, $name), $many ? $value : [$value]);
        $item = ['type' => $type, 'attribute' => $name, 'value' => $many || $kind === 'array' ? $values : $values[0]];
        if ($kind === 'bool') {
            if (!$values[0]) $item['type'] = $type === 'isTrue' ? 'isFalse' : 'isTrue';
            unset($item['value']);
        }
        if ($type === 'like' || $type === 'notLike') $item['value'] = '%' . $values[0] . '%';
        if ($kind !== 'datetime') return $item;
        $item += ['dateTime' => true, 'timeZone' => $this->timeZone];
        if ($operator !== '>=' && $operator !== '<=') return $item;
        return ['type' => 'or', 'value' => [['type' => 'on'] + $item, $item]];
    }

    /** @param array<int, mixed> $value */
    private function value(string $kind, array $value, int $position, string $name): string|int|float|bool
    {
        $bad = fn () => throw QueryError::at('bad_value', $position, $name);
        return match ($kind) {
            'text', 'enum', 'array' => match (true) {
                $value[0] === 'str', $value[0] === 'num' => (string) $value[1],
                $value === ['kw', 'me'] => $this->user->getId(),
                default => $bad(),
            },
            'number' => $value[0] === 'num' ? $value[1] + 0 : $bad(),
            'bool' => $value[0] === 'bool' ? $value[1] : $bad(),
            default => $this->date($value) ?? $bad(),
        };
    }

    /** @param array<int, mixed> $value */
    private function date(array $value): ?string
    {
        if ($value[0] === 'str') {
            $date = DateTime::createFromFormat('!Y-m-d', $value[1]);
            return $date && $date->format('Y-m-d') === $value[1] ? $value[1] : null;
        }
        $day = new DateTime('now', new DateTimeZone($this->timeZone));
        if ($value === ['kw', 'today']) return $day->format('Y-m-d');
        if ($value[0] !== 'ago') return null;
        $unit = ['d' => 'day', 'w' => 'week', 'm' => 'month', 'y' => 'year'][$value[2]];
        return $day->modify(sprintf('%+d %s', $value[1], $unit))->format('Y-m-d');
    }
}
