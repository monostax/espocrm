<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureInitiative\Classes\RecordHooks;

use Espo\Modules\FeatureInitiative\Services\ValidationError;
use Espo\Core\Record\Hook\LinkHook;
use Espo\Core\Record\Hook\UnlinkHook;
use Espo\ORM\Entity;

/** Relationship endpoints must not bypass save-time ownership/progress validation. */
class BlockRelationshipMutation implements LinkHook, UnlinkHook
{
    public function process(Entity $entity, string $link, Entity $foreignEntity): void
    {
        if (in_array($link, ['initiativeType', 'stage', 'stages', 'initiatives', 'teams', 'tenant', 'initiative', 'parent', 'relations', 'assignedUser'], true)) {
            throw ValidationError::badRequest('useRecordFields', 'Update the record fields instead of linking or unlinking this relationship.');
        }
    }
}
