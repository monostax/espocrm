<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Hooks\Note;

use Espo\Modules\Chatwoot\Services\OpportunityStreamEvents;
use Espo\ORM\Entity;

class KeepOpportunityEventsInternal
{
    // Core Note/SetFields clears isInternal for non-Post Notes at the default order.
    public static int $order = 99;

    public function beforeSave(Entity $entity, array $options): void
    {
        if (in_array($entity->get('type'), OpportunityStreamEvents::EVENT_TYPES, true)) {
            $entity->set('isInternal', true);
        }
    }
}
