<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Classes\Database;

use Espo\Core\Utils\Database\Orm\Defs\AttributeDefs;
use Espo\Core\Utils\Database\Orm\Defs\EntityDefs;
use Espo\Core\Utils\Database\Orm\Defs\IndexDefs;
use Espo\Core\Utils\Database\Orm\Defs\RelationDefs;
use Espo\Core\Utils\Database\Schema\EntityDefsModifier;
use Espo\Core\Utils\Metadata;
use Espo\ORM\Defs\EntityDefs as OrmEntityDefs;

/** Preserve explicit financial NOT NULL constraints across Espo's field converters. */
final class RequiredFields implements EntityDefsModifier
{
    public function __construct(private Metadata $metadata) {}

    public function modify(OrmEntityDefs $entityDefs): EntityDefs
    {
        $result = EntityDefs::create();
        $fields = $this->metadata->get(['entityDefs', $entityDefs->getName(), 'fields']) ?? [];

        foreach ($entityDefs->getParam('attributes') ?? [] as $name => $params) {
            if (($fields[$name]['notNull'] ?? false) && !($params['notStorable'] ?? false)) {
                $params['notNull'] = true;
            }
            if (str_ends_with($name, 'Id')) {
                $field = $fields[substr($name, 0, -2)] ?? [];
                if (($field['type'] ?? null) === 'link' && ($field['notNull'] ?? false)) {
                    $params['notNull'] = true;
                }
            }
            $result = $result->withAttribute(AttributeDefs::create($name)->withParamsMerged($params));
        }

        foreach ($entityDefs->getParam('relations') ?? [] as $name => $params) {
            $relation = RelationDefs::create($name);
            foreach ($params as $key => $value) {
                $relation = $relation->withParam($key, $value);
            }
            $result = $result->withRelation($relation);
        }

        foreach ($entityDefs->getParam('indexes') ?? [] as $name => $params) {
            $index = IndexDefs::create($name);
            foreach ($params as $key => $value) {
                $index = $index->withParam($key, $value);
            }
            $result = $result->withIndex($index);
        }

        return $result;
    }
}
