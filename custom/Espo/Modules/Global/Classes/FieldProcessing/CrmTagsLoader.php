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
        if (!$entity->has('tagsIds')) $entity->set('tagsIds', []);
        $this->tags->filterOutput($entity);
        foreach (['tagsIds', 'tagsNames', 'tagsColumns'] as $attribute) {
            if ($entity->has($attribute)) $entity->setFetched($attribute, $entity->get($attribute));
        }
    }
}
