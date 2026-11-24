<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools\RecordQuery;

use DateTime;
use DateTimeZone;
use Espo\Core\InjectableFactory;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Select\Where\CheckerFactory;
use Espo\Core\Select\Where\ConverterFactory;
use Espo\Core\Select\Where\Item;
use Espo\Core\Select\Where\Params;
use Espo\Core\Utils\Config\ApplicationConfig;
use Espo\Entities\User;
use Espo\ORM\Query\Select;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\Query\UnionBuilder;
use Exception;

/**
 * Record query text to an ORM where clause.
 *
 * Safety: the text never reaches SQL. Names must exist in metadata (Schema), operators come from fixed lists and values
 * are bound by the ORM. Every condition on a field goes through the stock where checker and converter of the entity it
 * names, so field-level access behaves exactly as in a regular list. Every related record is read through its own strict
 * access control, so a query only matches on related records the user can open, and a related record must belong to the
 * tenant of the record it hangs from. The result only narrows the list it is applied to. A budget bounds how much SQL a
 * single query can ask for.
 *
 * Lists: a relationship to many records is a list of rows (owner, item, link details). `.any` asks for a matching row,
 * `.none` for none, `.all` for at least one row in scope and no row in scope failing the test, `.count` groups the
 * distinct items per owner. A list named after a related type (`contacts` on Account) merges the native relationship
 * with confirmed relation records (the relationships panel); inside it `origin` is a detail of the link, not of the item.
 */
class Compiler
{
    private const MAX_LENGTH = 2000;
    /** Relationship hops, e.g. opportunities.account.assignedUser. */
    private const MAX_DEPTH = 3;
    /** Field conditions and list hops in one query. Each list costs a few sub-queries, so this bounds the SQL. */
    private const MAX_CONDITIONS = 40;
    private const MAX_LISTS = 8;
    /**
     * Sub-selects the compiled query may contain. Compiling multiplies the text: a merged list reads three places and
     * `.all` compiles its scope twice, so this is counted on what is built, not on what was typed.
     */
    private const MAX_SELECTS = 64;
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
    /** count(…) compared with a number, and the comparison that holds exactly when it does not. */
    private const NEGATED = ['=' => '!=', '!=' => '=', '>' => '<=', '>=' => '<', '<' => '>=', '<=' => '>'];
    /** Always true / always false, on the id of the query's main entity. */
    private const TRUE = ['id!=' => null];
    private const FALSE = ['id' => null];

    private string $timeZone = 'UTC';

    private Schema $schema;
    private int $cost = 0;

    public function __construct(
        private User $user,
        private ConverterFactory $converters,
        private CheckerFactory $checkers,
        private SelectBuilderFactory $selects,
        private ApplicationConfig $config,
        InjectableFactory $factory,
    ) {
        // The query's user, never the container's: jobs build lists for a user while running as system.
        $this->schema = $factory->createWith(Schema::class, ['user' => $user]);
    }

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
        $node = (new Parser())->parse($query);
        $this->budget($node);
        $this->cost = 0;
        return ['id=s' => $this->select($entityType, $node, 0, false)];
    }

    /** @param array<int, mixed> $node */
    private function budget(array $node): void
    {
        $conditions = 0;
        $lists = 0;
        $walk = function (?array $node) use (&$walk, &$conditions, &$lists): void {
            if (!$node) return;
            switch ($node[0]) {
                case 'and':
                case 'or':
                    foreach ($node[1] as $child) $walk($child);
                    return;
                case 'not':
                    $walk($node[1]);
                    return;
                case 'list':
                    $lists += count($node[1]);
                    foreach ($node[3] as $child) $walk($child);
                    $walk($node[4]);
                    return;
                case 'count':
                    $lists += count($node[1]);
                    foreach ($node[2] as $child) $walk($child);
                    $walk($node[3]);
                    return;
                default:
                    $conditions++;
                    $lists += count($node[1]) - 1;
            }
        };
        $walk($node);
        if ($conditions > self::MAX_CONDITIONS || $lists > self::MAX_LISTS) throw QueryError::at('too_complex', 0);
    }

    private function spend(int $selects = 1): void
    {
        $this->cost += $selects;
        if ($this->cost > self::MAX_SELECTS) throw QueryError::at('too_complex', 0);
    }

    /**
     * IDs of $entityType matching the node. Joins a condition needs stay inside this sub-select.
     *
     * @param ?array<int, mixed> $node
     */
    private function select(string $entityType, ?array $node, int $depth, bool $accessControl): Select
    {
        $this->spend();
        $builder = $accessControl
            ? $this->selects->create()->forUser($this->user)->from($entityType)->withStrictAccessControl()->buildQueryBuilder()->order([])
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
            'list' => $this->quantified($entityType, $node, $depth),
            'count' => $this->counted($entityType, $node, $depth),
            default => $this->compare($entityType, $node, $builder, $depth),
        };
    }

    /**
     * The first list on a path and the rest; `related.Account` is one (older) name.
     *
     * @param list<string> $path
     * @return array{string, list<string>}
     */
    private function hop(array $path): array
    {
        $name = array_shift($path);
        if ($name === 'related' && $path) $name .= '.' . array_shift($path);
        return [$name, $path];
    }

    /**
     * @param list<array<int, mixed>> $nodes
     * @return ?array<int, mixed>
     */
    private function conjunction(array $nodes): ?array
    {
        return match (count($nodes)) {
            0 => null,
            1 => $nodes[0],
            default => ['and', $nodes],
        };
    }

    /**
     * path.any / .all / .none, with .where narrowing the rows first.
     *
     * @param array<int, mixed> $node
     * @return array<int|string, mixed>
     */
    private function quantified(string $entityType, array $node, int $depth): array
    {
        [, $path, $mode, $where, $test, $position] = $node;
        [$name, $rest] = $this->hop($path);
        // account.contacts.all(…): the hops before the list are followed as "has one that".
        if ($rest) return $this->exists($entityType, $name, ['list', $rest, $mode, $where, $test, $position], $depth, $position);
        $matching = $this->conjunction($test ? [...$where, $test] : $where);
        return match ($mode) {
            'any' => $this->exists($entityType, $name, $matching, $depth, $position),
            'none' => ['NOT' => $this->exists($entityType, $name, $matching, $depth, $position)],
            // At least one row in scope, and no row in scope that fails the test.
            'all' => ['AND' => [
                $this->exists($entityType, $name, $this->conjunction($where), $depth, $position),
                ...($test ? [['NOT' => $this->exists($entityType, $name, $this->conjunction([...$where, ['not', $test]]), $depth, $position)]] : []),
            ]],
        };
    }

    /**
     * path.count(…) compared with a number: distinct items per owner. When the comparison holds for zero, owners
     * without rows match too, so the clause excludes the owners for which it fails instead.
     *
     * @param array<int, mixed> $node
     * @return array<int|string, mixed>
     */
    private function counted(string $entityType, array $node, int $depth): array
    {
        [, $path, $where, $test, $operator, $number, $position] = $node;
        [$name, $rest] = $this->hop($path);
        if ($rest) return $this->exists($entityType, $name, ['count', $rest, $where, $test, $operator, $number, $position], $depth, $position);
        $zero = match ($operator) {
            '=' => $number === 0, '!=' => $number !== 0, '>' => false, '>=' => $number === 0, '<' => $number > 0, '<=' => true,
        };
        $branches = $this->branches($entityType, $name, $this->conjunction($test ? [...$where, $test] : $where), $depth, $position, true);
        $union = UnionBuilder::create();
        foreach ($branches as $branch) $union->query($branch);
        $rows = count($branches) === 1 ? $branches[0] : $union->build();
        $owners = SelectBuilder::create()->fromQuery($rows, 'recordQueryRows')->select(['owner'])->group(['owner'])
            ->having(['COUNT:item' . ($zero ? self::NEGATED[$operator] : $operator) => $number])->build();
        return [$zero ? 'id!=s' : 'id=s' => $owners];
    }

    /**
     * Has a row in list $name matching $node (all of it on the same row).
     *
     * @param ?array<int, mixed> $node
     * @return array<int|string, mixed>
     */
    private function exists(string $entityType, string $name, ?array $node, int $depth, int $position): array
    {
        $items = array_map(
            fn (Select $branch) => ['id=s' => $branch],
            $this->branches($entityType, $name, $node, $depth, $position, false),
        );
        return count($items) === 1 ? $items[0] : ['OR' => $items];
    }

    /**
     * Sub-selects of owner IDs (or owner/item pairs) with a matching row, one per place the rows live.
     *
     * @param ?array<int, mixed> $node
     * @return list<Select>
     */
    private function branches(string $entityType, string $name, ?array $node, int $depth, int $position, bool $pairs): array
    {
        if ($depth >= self::MAX_DEPTH) throw QueryError::at('too_deep', $position, $name);
        $source = $this->schema->source($entityType, $name) ?? throw QueryError::at('unknown_link', $position, $name);
        $this->spend($source['kind'] === 'native' || $source['kind'] === 'results' ? 1 : ($source['link'] ? 3 : 2));
        if ($source['kind'] === 'results') return [$this->resultRows($entityType, $node, $position, $pairs)];
        $branches = [];
        if ($source['link']) $branches[] = $this->linkRows($entityType, $name, $source, $node, $depth, $pairs);
        if ($source['kind'] !== 'native') {
            foreach ([['subject', 'object'], ['object', 'subject']] as [$own, $other]) {
                $branches[] = $this->relationRows($entityType, $source, $own, $other, $node, $depth, $pairs);
            }
        }
        return $branches;
    }

    /**
     * Rows of a native relationship. When the query names link details, a pair that also has a confirmed relation
     * record counts once, through that record, so its details are the record's.
     *
     * @param array{kind: string, entity: string, link: ?string, tenant: bool, fields: list<string>} $source
     * @param ?array<int, mixed> $node
     */
    private function linkRows(string $entityType, string $name, array $source, ?array $node, int $depth, bool $pairs): Select
    {
        $item = $source['entity'];
        $builder = SelectBuilder::create()->from($entityType)->join($name, 'recordQueryItem');
        $where = [];
        // Never match across tenants, whatever the links in the data say.
        if ($source['tenant']) $where['recordQueryItem.tenantId=:'] = 'tenantId';
        if ($node && $this->namesLinkFields($node, $source['fields'])) {
            $builder->leftJoin('RecordRelation', 'recordQueryPair', [
                'OR' => [
                    ['recordQueryPair.subjectType' => $entityType, 'recordQueryPair.subjectId=:' => 'id',
                        'recordQueryPair.objectType' => $item, 'recordQueryPair.objectId=:' => 'recordQueryItem.id'],
                    ['recordQueryPair.objectType' => $entityType, 'recordQueryPair.objectId=:' => 'id',
                        'recordQueryPair.subjectType' => $item, 'recordQueryPair.subjectId=:' => 'recordQueryItem.id'],
                ],
                'recordQueryPair.status' => 'confirmed',
                'recordQueryPair.deleted' => false,
                'recordQueryPair.tenantId=:' => 'tenantId',
                'AND' => [['OR' => $this->evidence('recordQueryPair.', $entityType, $item, $depth)]],
            ]);
            $where['recordQueryPair.id'] = null;
        }
        $where[] = $this->rows($node, $item, $source['fields'], 'recordQueryItem.id', fn (array $cmp) => $this->unlinkedOrigin($cmp), $depth);
        $builder->select($pairs ? [['id', 'owner'], ['recordQueryItem.id', 'item']] : ['id'])->where($where);
        if ($pairs) $builder->distinct();
        return $builder->build();
    }

    /**
     * Rows of confirmed relation records with the owner on side $own. Both ends must belong to the relation's tenant,
     * and a relation backed by a document only counts when the user can read that document.
     *
     * @param array{kind: string, entity: string, link: ?string, tenant: bool, fields: list<string>} $source
     * @param ?array<int, mixed> $node
     */
    private function relationRows(string $entityType, array $source, string $own, string $other, ?array $node, int $depth, bool $pairs): Select
    {
        $item = $source['entity'];
        $builder = SelectBuilder::create()->from('RecordRelation')
            ->join($entityType, 'recordQueryOwner', ['recordQueryOwner.id=:' => $own . 'Id', 'recordQueryOwner.tenantId=:' => 'tenantId']);
        if ($source['tenant']) {
            $builder->join($item, 'recordQueryOther', ['recordQueryOther.id=:' => $other . 'Id', 'recordQueryOther.tenantId=:' => 'tenantId']);
        }
        $builder->where([$own . 'Type' => $entityType, $other . 'Type' => $item, 'status' => 'confirmed', 'OR' => $this->evidence('', $entityType, $item, $depth)])
            ->where($this->rows($node, $item, $source['fields'], $other . 'Id', fn (array $cmp) => $this->relationOrigin($cmp), $depth));
        $builder->select($pairs ? [[$own . 'Id', 'owner'], [$other . 'Id', 'item']] : [$own . 'Id']);
        if ($pairs) $builder->distinct();
        return $builder->build();
    }

    /** @return list<array<string, mixed>> */
    private function evidence(string $prefix, string $one, string $other, int $depth): array
    {
        $evidence = [[$prefix . 'sourceDocumentId' => null]];
        if ($this->schema->documentsReadable() && $this->schema->evidenceBacked($one, $other)) {
            $evidence[] = [$prefix . 'sourceDocumentId=s' => $this->select('Document', null, $depth, true)];
        }
        return $evidence;
    }

    /**
     * Where a row matches $node: the item is one the user can read, item conditions apply to it, and link details
     * (`origin`) apply to the row itself.
     *
     * @param ?array<int, mixed> $node
     * @param list<string> $fields link details this list has
     * @param callable(array<int, mixed>): array<int|string, mixed> $linkField
     * @return array<int|string, mixed>
     */
    private function rows(?array $node, string $item, array $fields, string $column, callable $linkField, int $depth): array
    {
        if (!$node || !$this->namesLinkFields($node, $fields)) return [$column . '=s' => $this->select($item, $node, $depth + 1, true)];
        return ['AND' => [
            [$column . '=s' => $this->select($item, null, $depth + 1, true)],
            $this->mixed($node, $item, $fields, $column, $linkField, $depth),
        ]];
    }

    /**
     * @param array<int, mixed> $node
     * @param list<string> $fields
     * @return array<int|string, mixed>
     */
    private function mixed(array $node, string $item, array $fields, string $column, callable $linkField, int $depth): array
    {
        if (!$this->namesLinkFields($node, $fields)) return [$column . '=s' => $this->select($item, $node, $depth + 1, false)];
        return match ($node[0]) {
            'and', 'or' => [strtoupper($node[0]) => array_map(
                fn (array $child) => $this->mixed($child, $item, $fields, $column, $linkField, $depth), $node[1],
            )],
            'not' => ['NOT' => $this->mixed($node[1], $item, $fields, $column, $linkField, $depth)],
            default => $linkField($node),
        };
    }

    /**
     * Whether the node names a link detail of the list it sits in. Lists nested inside have their own details.
     *
     * @param array<int, mixed> $node
     * @param list<string> $fields
     */
    private function namesLinkFields(array $node, array $fields): bool
    {
        if (!$fields) return false;
        return match ($node[0]) {
            'and', 'or' => (bool) array_filter($node[1], fn (array $child) => $this->namesLinkFields($child, $fields)),
            'not' => $this->namesLinkFields($node[1], $fields),
            'cmp' => count($node[1]) === 1 && in_array($node[1][0], $fields, true),
            default => false,
        };
    }

    /**
     * `origin` on a relation record: enrichment wrote it, else who confirmed it (manual, assistant).
     *
     * @param array<int, mixed> $node
     * @return array<int|string, mixed>
     */
    private function relationOrigin(array $node): array
    {
        [$positive, $origins] = $this->origins($node);
        if ($origins === null) return $positive ? self::TRUE : self::FALSE;
        // Each alternative is a definite true or false (never SQL NULL), so NOT negates it exactly.
        $any = ['OR' => array_map(
            fn (string $origin) => $origin === 'enrichment' ? ['via!=' => null, 'via' => 'enrichment'] : ['origin' => $origin, 'via' => null],
            $origins,
        )];
        return $positive ? $any : ['NOT' => $any];
    }

    /**
     * `origin` on a native link without a relation record: empty.
     *
     * @param array<int, mixed> $node
     * @return array<int|string, mixed>
     */
    private function unlinkedOrigin(array $node): array
    {
        [$positive] = $this->origins($node);
        // IS EMPTY, != and NOT IN hold; IS NOT EMPTY, = and IN fail.
        return $positive ? self::FALSE : self::TRUE;
    }

    /**
     * @param array<int, mixed> $node
     * @return array{bool, ?list<string>} positive (= IN IS NOT EMPTY) or not, and the origins named (null for emptiness)
     */
    private function origins(array $node): array
    {
        [, $path, $operator, $value, $position] = $node;
        if ($operator === 'empty' || $operator === 'notEmpty') return [$operator === 'notEmpty', null];
        if (!in_array($operator, ['=', '!=', 'in', 'notIn'], true)) throw QueryError::at('bad_operator', $position, $path[0]);
        $origins = [];
        foreach (in_array($operator, ['in', 'notIn'], true) ? $value : [$value] as $one) {
            if ($one[0] !== 'str' || !in_array($one[1], Schema::ORIGINS, true)) throw QueryError::at('bad_value', $position, $path[0]);
            $origins[] = $one[1];
        }
        return [in_array($operator, ['=', 'in'], true), $origins];
    }

    /**
     * enrichmentResults on a Contact: one row per email or phone enrichment found for it. The owner Contact was already
     * read through access control by the list this hangs from; the rows only carry flags, never the values.
     *
     * @param ?array<int, mixed> $node
     */
    private function resultRows(string $entityType, ?array $node, int $position, bool $pairs): Select
    {
        $builder = SelectBuilder::create()->from(Schema::RESULTS)
            ->join($entityType, 'recordQueryOwner', ['recordQueryOwner.id=:' => 'contactId', 'recordQueryOwner.tenantId=:' => 'tenantId']);
        if ($node) $builder->where($this->resultWhere($node));
        $builder->select($pairs ? [['contactId', 'owner'], ['id', 'item']] : ['contactId']);
        return $builder->build();
    }

    /**
     * @param array<int, mixed> $node
     * @return array<int|string, mixed>
     */
    private function resultWhere(array $node): array
    {
        switch ($node[0]) {
            case 'and':
            case 'or':
                return [strtoupper($node[0]) => array_map(fn (array $child) => $this->resultWhere($child), $node[1])];
            case 'not':
                return ['NOT' => $this->resultWhere($node[1])];
            case 'list':
            case 'count':
                throw QueryError::at('unknown_link', $node[count($node) - 1], $node[1][0]);
        }
        [, $path, $operator, $value, $position] = $node;
        $name = $path[0];
        if (count($path) > 1 || !array_key_exists($name, Schema::RESULT_FIELDS)) throw QueryError::at('unknown_field', $position, $name);
        if ($name === 'type') {
            if (!in_array($operator, ['=', '!=', 'in', 'notIn'], true)) throw QueryError::at('bad_operator', $position, $name);
            $types = [];
            foreach (in_array($operator, ['in', 'notIn'], true) ? $value : [$value] as $one) {
                if ($one[0] !== 'str' || !in_array($one[1], Schema::RESULT_FIELDS['type'], true)) throw QueryError::at('bad_value', $position, $name);
                $types[] = $one[1];
            }
            return [in_array($operator, ['=', 'in'], true) ? 'type' : 'type!=' => $types];
        }
        if (!in_array($operator, ['=', '!='], true)) throw QueryError::at('bad_operator', $position, $name);
        if ($value[0] !== 'bool') throw QueryError::at('bad_value', $position, $name);
        return [$name => $operator === '=' ? $value[1] : !$value[1]];
    }

    /**
     * @param array<int, mixed> $node
     * @return array<int|string, mixed>
     */
    private function compare(string $entityType, array $node, SelectBuilder $builder, int $depth): array
    {
        [, $path, $operator, $value, $position] = $node;
        [$name, $rest] = $this->hop($path);
        // account.industry = "SaaS", opportunities.amount > 1000: "has one where".
        if ($rest) return $this->quantified($entityType, ['list', [$name], 'any', [], ['cmp', $rest, $operator, $value, $position], $position], $depth);
        $kind = str_contains($name, '.') ? null : $this->schema->kind($entityType, $name);
        if (!$kind) {
            if (!$this->schema->source($entityType, $name)) throw QueryError::at('unknown_field', $position, $name);
            return $this->compareLink($entityType, ['cmp', [$name], $operator, $value, $position], $depth);
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
        if ($positive) return ['NOT' => $this->compareLink($entityType, ['cmp', $path, $positive, $value, $position], $depth)];
        if ($operator === 'notEmpty') return $this->exists($entityType, $path[0], null, $depth, $position);
        if (!in_array($operator, ['=', 'in', '~'], true)) throw QueryError::at('bad_operator', $position, $path[0]);
        $me = $value === ['kw', 'me'];
        return $this->exists(
            $entityType, $path[0],
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
