<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Classes\Select\CrmTag;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Select\Where\Item;
use Espo\Core\Select\Where\ItemConverter;
use Espo\Modules\Global\Tools\CrmTags;
use Espo\ORM\Query\Part\WhereClause;
use Espo\ORM\Query\Part\WhereItem;
use Espo\ORM\Query\SelectBuilder;

/** Tag predicates operate on the visible set, including "has no tags". */
class VisibleTagFilter implements ItemConverter
{
    public function __construct(private string $entityType, private CrmTags $tags) {}

    public function convert(SelectBuilder $queryBuilder, Item $item): WhereItem
    {
        $type = $item->getType();
        $ids = $item->getValue();
        $visible = $this->tags->query()->select(['id']);
        if (in_array($type, ['linkedWith', 'notLinkedWith', 'linkedWithAll'], true)) {
            if (!is_array($ids) || array_filter($ids, fn ($id) => !is_string($id))) {
                throw new BadRequest('Tag filter requires a list of IDs.');
            }
            $ids = array_values(array_unique($ids));
            $visible->where(['id' => $ids]);
        }
        $records = SelectBuilder::create()->from($this->entityType)->select(['id'])
            ->join('tags', 'crmTag')->where(['crmTag.id=s' => $visible->build()]);
        if ($type === 'linkedWithAll') {
            $records->group('id')->having(['COUNT:crmTag.id' => count($ids)]);
        }
        $operator = in_array($type, ['notLinkedWith', 'isNotLinked'], true) ? 'id!=s' : 'id=s';
        return WhereClause::fromRaw([$operator => $records->build()]);
    }
}
