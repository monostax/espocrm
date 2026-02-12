<?php

namespace Espo\Modules\PackEnterprise\Hooks\Meeting;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Hook for Meeting entity changes related to MsxGoogleCalendar.
 * Clears the msxGoogleCalendarEventId when the assigned user changes,
 * so the event gets re-synced with the new user's calendar.
 */
class MsxGoogleCalendar
{
    public static int $order = 9;

    private EntityManager $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function beforeSave(Entity $entity, array $options = []): void
    {
        if (!$entity->hasAttribute('msxGoogleCalendarEventId')) {
            return;
        }

        if (!$entity->isAttributeChanged('assignedUserId')) {
            return;
        }

        $entity->set('msxGoogleCalendarEventId', null);
        $entity->set('msxGoogleCalendarId', null);
    }
}
