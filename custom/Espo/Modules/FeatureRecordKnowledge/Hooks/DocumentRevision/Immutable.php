<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Hooks\DocumentRevision;

use Espo\Core\Exceptions\Forbidden;
use Espo\ORM\Entity;

class Immutable
{
    public function beforeSave(Entity $entity, array $options): void
    {
        if (!$entity->isNew()) throw new Forbidden('Document revisions are immutable.');
    }
    public function beforeRemove(Entity $entity, array $options): void { throw new Forbidden('Document revisions are immutable.'); }
}
