<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureSimpleJourney\Classes\RecordHooks;

use Espo\Modules\FeatureSimpleJourney\Services\ValidationError;
use Espo\Core\Record\Hook\LinkHook;
use Espo\Core\Record\Hook\UnlinkHook;
use Espo\ORM\Entity;

/** Relationship endpoints must not bypass save-time ownership/progress validation. */
class BlockRelationshipMutation implements LinkHook, UnlinkHook
{
    public function process(Entity $entity, string $link, Entity $foreignEntity): void
    {
        if (in_array($link, ['journey', 'stage', 'stages', 'records', 'teams', 'tenant', 'record', 'parent', 'parents', 'assignedUser'], true)) {
            throw ValidationError::badRequest('useRecordFields', 'Update the record fields instead of linking or unlinking this relationship.');
        }
    }
}
