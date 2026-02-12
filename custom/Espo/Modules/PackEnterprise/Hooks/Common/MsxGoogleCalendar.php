<?php

namespace Espo\Modules\PackEnterprise\Hooks\Common;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Generic hook for activity entities (Meeting, Call, custom activities)
 * related to MsxGoogleCalendar.
 * Handles assignedUserId changes to reset the Google Calendar event association.
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

        if (
            !$entity->isAttributeChanged('assignedUserId') ||
            $entity->getEntityType() === 'Meeting' ||
            $entity->getEntityType() === 'Call'
        ) {
            return;
        }

        // For custom activity types that use MsxGoogleCalendarEvent junction table,
        // clear the association when the assigned user changes.
        $entity->set('msxGoogleCalendarEventId', null);
        $entity->set('msxGoogleCalendarId', null);
    }
}
