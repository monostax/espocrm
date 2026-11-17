<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Classes\Select\RecordQuery;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Select\Where\Item;
use Espo\Core\Select\Where\ItemConverter;
use Espo\Modules\Chatwoot\Tools\RecordQuery\Compiler;
use Espo\ORM\Query\Part\WhereClause;
use Espo\ORM\Query\Part\WhereItem;
use Espo\ORM\Query\SelectBuilder;

/** Where item {type: recordQuery, attribute: id, value: {query, timeZone}}: a typed query on any list of the entity. */
class Where implements ItemConverter
{
    public function __construct(private string $entityType, private Compiler $compiler) {}

    public function convert(SelectBuilder $queryBuilder, Item $item): WhereItem
    {
        $value = $item->getValue();
        if (is_object($value)) $value = (array) $value;
        $query = is_array($value) ? ($value['query'] ?? null) : $value;
        $timeZone = is_array($value) ? ($value['timeZone'] ?? null) : null;
        if (!is_string($query) || ($timeZone !== null && !is_string($timeZone))) throw new BadRequest('A record query is required.');
        return WhereClause::fromRaw($this->compiler->compile($this->entityType, $query, $timeZone));
    }
}
