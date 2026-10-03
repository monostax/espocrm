<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Classes\Record;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Record\Hook\LinkHook;
use Espo\Core\Record\Hook\UnlinkHook;
use Espo\ORM\Entity;

/** Relationship endpoints must not bypass immutable tenant/actor identity. */
class BlockPredicateRelationships implements LinkHook, UnlinkHook
{
    public function process(Entity $entity, string $link, Entity $foreignEntity): void
    {
        throw new Conflict('Predicate relationships are server-managed; update definition fields instead.');
    }
}
