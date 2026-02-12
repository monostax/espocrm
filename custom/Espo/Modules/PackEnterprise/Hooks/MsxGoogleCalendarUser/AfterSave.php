<?php

namespace Espo\Modules\PackEnterprise\Hooks\MsxGoogleCalendarUser;

use Espo\Modules\PackEnterprise\Repositories\MsxGoogleCalendar;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * AfterSave hook for MsxGoogleCalendarUser.
 * Creates and manages monitored MsxGoogleCalendarUser records based on
 * the calendarMonitoredCalendarsIds field from the main record.
 * Mirrors functionality from Espo\Modules\Google\Hooks\ExternalAccount\Google
 */
class AfterSave
{
    public static int $order = 9;

    private EntityManager $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function afterSave(Entity $entity, array $options = []): void
    {
        // Only process main records to avoid recursion
        $type = $entity->get('type');
        if ($type !== 'main') {
            return;
        }

        $userId = $entity->get('userId');
        if (!$userId) {
            return;
        }

        /** @var MsxGoogleCalendar $repo */
        $repo = $this->entityManager->getRepository('MsxGoogleCalendar');
        $storedUsersCalendars = $repo->storedUsersCalendars($userId);

        $direction = $entity->get('calendarDirection');
        $monitoredCalendarIds = $entity->get('calendarMonitoredCalendarsIds') ?? [];
        $monitoredCalendars = $entity->get('calendarMonitoredCalendarsNames') ?? (object) [];

        if (!is_object($monitoredCalendars)) {
            $monitoredCalendars = (object) $monitoredCalendars;
        }

        if (!is_array($monitoredCalendarIds)) {
            $monitoredCalendarIds = [];
        }

        $mainCalendarId = $entity->get('calendarMainCalendarId');
        $mainCalendarName = $entity->get('calendarMainCalendarName');

        // If direction is GCToEspo, also monitor the main calendar
        if ($direction === 'GCToEspo' && !in_array($mainCalendarId, $monitoredCalendarIds)) {
            $monitoredCalendarIds[] = $mainCalendarId;
            $monitoredCalendars->$mainCalendarId = $mainCalendarName;
        }

        // Process monitored calendars
        foreach ($monitoredCalendarIds as $calendarId) {
            $googleCalendar = $repo->getCalendarByGCId($calendarId);

            if (!$googleCalendar) {
                $googleCalendar = $this->entityManager->getNewEntity('MsxGoogleCalendar');
                $googleCalendar->set('name', $monitoredCalendars->$calendarId ?? $calendarId);
                $googleCalendar->set('calendarId', $calendarId);
                $this->entityManager->saveEntity($googleCalendar);
            }

            $id = $googleCalendar->get('id');

            if (isset($storedUsersCalendars['monitored'][$id])) {
                // Reactivate if inactive
                if (!$storedUsersCalendars['monitored'][$id]['active']) {
                    $calendarUser = $this->entityManager
                        ->getEntityById('MsxGoogleCalendarUser', $storedUsersCalendars['monitored'][$id]['id']);
                    if ($calendarUser) {
                        $calendarUser->set('active', true);
                        $this->entityManager->saveEntity($calendarUser);
                    }
                }
            } else {
                // Create new monitored record
                $calendarUser = $this->entityManager->getNewEntity('MsxGoogleCalendarUser');
                $calendarUser->set('userId', $userId);
                $calendarUser->set('type', 'monitored');
                $calendarUser->set('role', 'owner');
                $calendarUser->set('msxGoogleCalendarId', $id);
                $calendarUser->set('oAuthAccountId', $entity->get('oAuthAccountId'));
                
                // Copy calendar settings from main record
                $calendarUser->set('calendarMainCalendarId', $calendarId);
                $calendarUser->set('calendarMainCalendarName', $monitoredCalendars->$calendarId ?? $calendarId);
                $calendarUser->set('calendarDirection', $entity->get('calendarDirection'));
                $calendarUser->set('calendarEntityTypes', $entity->get('calendarEntityTypes'));
                $calendarUser->set('calendarStartDate', $entity->get('calendarStartDate'));
                $calendarUser->set('calendarDefaultEntity', $entity->get('calendarDefaultEntity'));
                $calendarUser->set('removeGCEventIfRemovedInEspo', $entity->get('removeGCEventIfRemovedInEspo'));
                $calendarUser->set('dontSyncEventAttendees', $entity->get('dontSyncEventAttendees'));
                $calendarUser->set('calendarAssignDefaultTeam', $entity->get('calendarAssignDefaultTeam'));
                
                $this->entityManager->saveEntity($calendarUser);
            }
        }

        // Deactivate monitored calendars that are no longer in the list
        foreach ($storedUsersCalendars['monitored'] as $calendar) {
            if (
                $calendar['active'] &&
                (!is_array($monitoredCalendarIds) || !in_array($calendar['calendarId'], $monitoredCalendarIds))
            ) {
                $calendarUser = $this->entityManager->getEntityById('MsxGoogleCalendarUser', $calendar['id']);
                if ($calendarUser) {
                    $calendarUser->set('active', false);
                    $this->entityManager->saveEntity($calendarUser);
                }
            }
        }

        // Handle main calendar activation/deactivation
        if ($direction === 'GCToEspo') {
            $mainCalendarId = '';
            $mainCalendarName = null;
        }

        if (empty($mainCalendarId)) {
            // No main calendar selected - deactivate all main records
            foreach ($storedUsersCalendars['main'] as $calendar) {
                if ($calendar['active']) {
                    $calendarUser = $this->entityManager->getEntityById('MsxGoogleCalendarUser', $calendar['id']);
                    if ($calendarUser) {
                        $calendarUser->set('active', false);
                        $this->entityManager->saveEntity($calendarUser);
                    }
                }
            }
        } else {
            // Main calendar selected - ensure MsxGoogleCalendar exists
            $googleCalendar = $repo->getCalendarByGCId($mainCalendarId);

            if (!$googleCalendar) {
                $googleCalendar = $this->entityManager->getNewEntity('MsxGoogleCalendar');
                $googleCalendar->set('name', $mainCalendarName ?? $mainCalendarId);
                $googleCalendar->set('calendarId', $mainCalendarId);
                $this->entityManager->saveEntity($googleCalendar);
            }

            $id = $googleCalendar->get('id');

            // Handle activation/deactivation of main records
            foreach ($storedUsersCalendars['main'] as $calendarId => $calendar) {
                if ($calendar['active'] && $id != $calendarId) {
                    // Different calendar active - deactivate it
                    $calendarUser = $this->entityManager->getEntityById('MsxGoogleCalendarUser', $calendar['id']);
                    if ($calendarUser) {
                        $calendarUser->set('active', false);
                        $this->entityManager->saveEntity($calendarUser);
                    }
                } elseif (!$calendar['active'] && $id == $calendarId) {
                    // This calendar should be active
                    $calendarUser = $this->entityManager->getEntityById('MsxGoogleCalendarUser', $calendar['id']);
                    if ($calendarUser) {
                        $calendarUser->set('active', true);
                        $this->entityManager->saveEntity($calendarUser);
                    }
                }
            }

            // Create main record if it doesn't exist
            if (!isset($storedUsersCalendars['main'][$id])) {
                // The main record is the entity being saved, so it should already exist
                // But if somehow it doesn't, the current entity will serve as the main
            }
        }
    }
}
