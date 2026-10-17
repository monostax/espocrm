<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Classes\Record;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\Hook\LinkHook;
use Espo\Core\Record\Hook\UnlinkHook;
use Espo\ORM\Entity;

class OwnedDocumentRelationships implements LinkHook, UnlinkHook
{
    public function process(Entity $entity, string $link, Entity $foreignEntity): void
    {
        if ($entity->get('knowledgeRecordType') && in_array($link, ['teams', 'assignedUser', 'createdBy'], true)) {
            throw new Forbidden('Owned document sharing follows its parent.');
        }
        if ($foreignEntity->get('knowledgeRecordType') && in_array($entity->getEntityType(), ['Team', 'User'], true)) {
            throw new Forbidden('Owned document sharing follows its parent.');
        }
    }
}
