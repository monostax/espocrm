<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Classes\FieldProcessing;

use Espo\Core\FieldProcessing\Loader;
use Espo\Core\FieldProcessing\Loader\Params;
use Espo\Modules\Global\Tools\CrmTags;
use Espo\ORM\Entity;

/** Filter link data at load time too, including exports and duplication. */
class CrmTagsLoader implements Loader
{
    public function __construct(private CrmTags $tags) {}

    public function process(Entity $entity, Params $params): void
    {
        $field = CrmTags::field($entity->getEntityType());
        if (!$entity->has($field . 'Ids')) $entity->set($field . 'Ids', []);
        $this->tags->filterOutput($entity);
        foreach ([$field . 'Ids', $field . 'Names', $field . 'Columns'] as $attribute) {
            if ($entity->has($attribute)) $entity->setFetched($attribute, $entity->get($attribute));
        }
    }
}
