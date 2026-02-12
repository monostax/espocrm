<?php

namespace Espo\Modules\PackEnterprise\Hooks\MsxGoogleCalendarUser;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Auto-creates or links a MsxGoogleCalendar entity based on the user's
 * selected calendarMainCalendarId. This bridges the UI selection to the
 * internal MsxGoogleCalendar record that the sync engine requires.
 */
class BeforeSave
{
    public static int $order = 9;

    private EntityManager $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function beforeSave(Entity $entity, array $options = []): void
    {
        $calendarId = $entity->get('calendarMainCalendarId');

        if (empty($calendarId)) {
            return;
        }

        // Only process if the calendar selection changed or msxGoogleCalendar is not set.
        if (
            !$entity->isAttributeChanged('calendarMainCalendarId') &&
            $entity->get('msxGoogleCalendarId')
        ) {
            return;
        }

        // Find existing MsxGoogleCalendar with this calendarId.
        $msxGoogleCalendar = $this->entityManager
            ->getRDBRepository('MsxGoogleCalendar')
            ->where(['calendarId' => $calendarId])
            ->findOne();

        if (!$msxGoogleCalendar) {
            // Create a new MsxGoogleCalendar record.
            $msxGoogleCalendar = $this->entityManager->getNewEntity('MsxGoogleCalendar');
            $msxGoogleCalendar->set('calendarId', $calendarId);
            $msxGoogleCalendar->set('name', $entity->get('calendarMainCalendarName') ?? $calendarId);

            $this->entityManager->saveEntity($msxGoogleCalendar);
        }

        $entity->set('msxGoogleCalendarId', $msxGoogleCalendar->getId());
    }
}
