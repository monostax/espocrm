<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Classes\NextAction;

use Espo\Core\Utils\Metadata;
use Espo\ORM\Entity;

/** Whether a Task, Call or Meeting is still an unfinished next step. */
final class PendingActivity
{
    public const TYPES = ['Task', 'Call', 'Meeting'];

    public static function isPending(Entity $activity, Metadata $metadata): bool
    {
        $type = $activity->getEntityType();

        return $type === 'Task'
            ? !in_array($activity->get('status'), $metadata->get([
                'entityDefs', 'Task', 'fields', 'status', 'notActualOptions',
            ]) ?? [], true)
            : in_array($activity->get('status'), $metadata->get(['scopes', $type, 'activityStatusList']) ?? [], true);
    }
}
