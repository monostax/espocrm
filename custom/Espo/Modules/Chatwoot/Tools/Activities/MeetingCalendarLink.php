<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools\Activities;

use Espo\Core\Acl;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Derives a link only from an authorized, output-filtered Meeting detail. */
class MeetingCalendarLink
{
    public function __construct(private EntityManager $em, private Acl $acl) {}

    public function resolve(Entity $meeting): ?string
    {
        if ($meeting->getEntityType() !== 'Meeting' ||
            !$this->acl->checkField('Meeting', 'msxGoogleCalendar') ||
            !$this->acl->checkLink('Meeting', 'msxGoogleCalendar') ||
            !$this->acl->checkField('Meeting', 'msxGoogleCalendarEventId') ||
            !$this->acl->checkScope('MsxGoogleCalendar', 'read') ||
            !$this->acl->checkField('MsxGoogleCalendar', 'calendarId')) {
            return null;
        }

        $eventId = $meeting->get('msxGoogleCalendarEventId');
        $calendarRecordId = $meeting->get('msxGoogleCalendarId');
        if (!is_string($eventId) || trim($eventId) === '' ||
            !is_string($calendarRecordId) || trim($calendarRecordId) === '') {
            return null;
        }

        // One lookup, exclusively through this meeting's stored relationship.
        $calendar = $this->em->getEntityById('MsxGoogleCalendar', $calendarRecordId);
        if (!$calendar || !$this->acl->checkEntityRead($calendar)) {
            return null;
        }

        $calendarId = $calendar->get('calendarId');
        if (!is_string($calendarId) || trim($calendarId) === '') {
            return null;
        }

        // Keep recurring occurrence IDs and the actual calendar ID verbatim.
        return 'https://calendar.google.com/calendar/event?eid=' .
            rawurlencode(base64_encode($eventId . ' ' . $calendarId));
    }
}
