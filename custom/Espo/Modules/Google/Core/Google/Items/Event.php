<?php
/***********************************************************************************
 * The contents of this file are subject to the Extension License Agreement
 * ("Agreement") which can be viewed at
 * https://www.espocrm.com/extension-license-agreement/.
 * By copying, installing downloading, or using this file, You have unconditionally
 * agreed to the terms and conditions of the Agreement, and You may not use this
 * file except in compliance with the Agreement. Under the terms of the Agreement,
 * You shall not license, sublicense, sell, resell, rent, lease, lend, distribute,
 * redistribute, market, publish, commercialize, or otherwise transfer rights or
 * usage to the software or any modified version or derivative work of the software
 * created by or for you.
 *
 * Copyright (C) 2015-2025 EspoCRM, Inc.
 *
 * License ID: 99e925c7f52e4853679eb7c383162336
 ************************************************************************************/

namespace Espo\Modules\Google\Core\Google\Items;

use DateTime;
use DateTimeZone;
use Exception;
use RuntimeException;

class Event
{
    public const EVENT_TYPE_DEFAULT = 'default';
    public const EVENT_TYPE_FROM_GMAIL = 'fromGmail';

    const RETURN_FORMAT_DATETIME = "Y-m-d H:i:s";
    const RETURN_FORMAT_DATE = "Y-m-d";
    const FORMAT_RFC_3339 = "Y-m-d\TH:i:s\Z";

    private $item;

    private $defaults = [];

    /**
     * @param array<string, mixed> $item
     */
    public function __construct($item)
    {
        $this->item = $item;
    }

    /**
     * @return string|null
     */
    public function getId()
    {
        return (isset($this->item['id'])) ? $this->item['id'] : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefaults()
    {
        return $this->defaults;
    }

    public function setDefaults($value = [])
    {
        if (is_array($value)) {
            $this->defaults = array_merge($this->defaults, $value);
        }
    }

    /**
     * @return string|null
     */
    public function getEventType()
    {
        return $this->item['eventType'] ?? null;
    }

    /**
     * @return string|null
     */
    public function getStatus()
    {
        return (isset($this->item['status'])) ? $this->item['status'] : null;
    }

    /**
     * @param string $value
     * @return void
     */
    public function setStatus($value)
    {
        $this->item['status'] = $value;
    }

    /**
     * @return string|null
     */
    public function getSource()
    {
        return isset($this->item['source']) ? $this->item['source']['title'] : null;
    }

    /**
     * @param string $title
     * @param string $url
     * @return void
     */
    public function setSource($title = '', $url = '')
    {
        if (!empty($title) && empty($this->item['source']['title'])) {
            $this->item['source']['title'] = $title;
        }
        if (!empty($url) && empty($this->item['source']['url'])) {
            $this->item['source']['url'] = $url;
        }
    }

    /**
     * @return bool
     */
    public function isDeleted()
    {
        return $this->getStatus() === 'cancelled';
    }

    public function setICalUID(?string $iCalUID): void
    {
        $this->item['iCalUID'] = $iCalUID;
    }

    public function getICalUID(): ?string
    {
        return $this->item['iCalUID'] ?? null;
    }

    /**
     * @return bool
     */
    public function hasEnd()
    {
        return !isset($this->item['endTimeUnspecified']);
    }

    /**
     * @return void
     */
    public function restore()
    {
        $this->setStatus('confirmed');
    }

    /**
     * @return bool
     */
    public function isPrivate()
    {
        $visibility = $this->item['visibility'] ?? null;

        return in_array($visibility, ["private", "confidential"]);
    }

    /**
     * @return string|null
     */
    public function getSummary()
    {
        return $this->item['summary'] ?? null;
    }

    /**
     * @param string|null $value
     * @return void
     */
    public function setSummary($value)
    {
        $this->item['summary'] = $value;
    }

    /**
     * @return string|null
     */
    public function getLocation()
    {
        return $this->item['location'] ?? null;
    }

    /**
     * @param string|null $value
     * @return void
     */
    public function setLocation($value): void
    {
        $this->item['location'] = $value;
    }

    /**
     * @return string|null
     */
    public function getDescription()
    {
        return $this->item['description'] ?? null;
    }

    /**
     * @param string|null $value
     * @return void
     */
    public function setDescription($value)
    {
        $this->item['description'] = $value;
    }

    /**
     * @return string|null
     */
    public function getStart()
    {
        if (isset($this->item['start'])) {
            return $this->decodeGoogleDate($this->item['start']);
        }

        return null;
    }

    /**
     * @return string|null
     */
    public function getEnd()
    {
        if (isset($this->item['end'])) {
            return $this->decodeGoogleDate($this->item['end']);
        }

        return null;
    }

    /**
     * @return string|null
     */
    public function getStartDate()
    {
        if (isset($this->item['start'])) {
            return $this->decodeGoogleDateDate($this->item['start']);
        }

        return null;
    }

    /**
     * @return string|null
     */
    public function getEndDate()
    {
        if (isset($this->item['end'])) {
            return $this->decodeGoogleDateDate($this->item['end'], true);
        }

        return null;
    }

    /**
     * @param ?string $value
     * @return void
     */
    public function setStart($value)
    {
        $this->item['start'] = $this->encodeGoogleDateTime('start', $value);
    }

    /**
     * @param ?string $value
     * @return void
     */
    public function setEnd($value)
    {
        $this->item['end'] = $this->encodeGoogleDateTime('end', $value);
    }

    /**
     * @param ?string $value
     * @return void
     */
    public function setStartDate($value)
    {
        if (!$value) {
            return;
        }

        $this->item['start'] = $this->encodeGoogleDate($value);
    }

    /**
     * @param ?string $value
     * @return void
     */
    public function setEndDate($value)
    {
        if (!$value) {
            return;
        }

        $this->item['end'] = $this->encodeGoogleDate($value, true);
    }

    public function getRecurrence()
    {
        return (isset($this->item['recurrence'])) ? $this->item['recurrence'] : null;
    }

    public function getRecurringEventId()
    {
        return (isset($this->item['recurringEventId'])) ? $this->item['recurringEventId'] : null;
    }

    /**
     * @return false|string
     */
    public function updated()
    {
        if (isset($this->item['updated'])) {
            try {
                $updated = new DateTime($this->item['updated']);
            }
            catch (Exception $e) {
                throw new RuntimeException($e->getMessage());
            }

            return $updated->format(self::RETURN_FORMAT_DATETIME);
        }

        return false;
    }

    public function appendJoinUrlToDescription(string $joinUrl): void
    {
        $description = $this->getDescription();

        if ($description && str_contains($description, $joinUrl)) {
            return;
        }

        $description ??= '';

        if ($description) {
            $description .= "\n---\n";
        }

        $description .= $joinUrl;

        $this->setDescription($description);
    }

    /*public function setJoinUrl(?string $joinUrl): void
    {
        if (!$joinUrl) {
            unset($this->item['conferenceData']);
        }

        $this->item['conferenceData'] = [
            'conferenceSolution' => [
                'key' => [
                    'type' => 'addOn',
                ],
                'name' => 'Zoom',
            ],
            'entryPoints' => [
                [
                    'uri' => $joinUrl,
                    'entryPointType' => 'video',
                ]
            ]
        ];
    }*/

    protected function getCreator()
    {
        return (isset($this->item['creator'])) ? $this->item['creator'] : [];
    }

    protected function getOrganizer()
    {
        return (isset($this->item['organizer'])) ? $this->item['organizer'] : [];
    }

    public function getOrganizerEmail()
    {
        $organizer = $this->getOrganizer();

        return $organizer['email'] ?? null;
    }

    public function isOrganizer()
    {
        $organizer = $this->getOrganizer();
        return !empty($organizer['self']);
    }

    public function getAttendees()
    {
        return $this->item['attendees'] ?? [];
    }

    public function deleteAttendee($email)
    {
        $key = $this->getAttendeeIndex($email);

        if ($key !== false) {
            unset($this->item['attendees'][$key]);
        }
    }

    public function findAttendee($email)
    {
        $key = $this->getAttendeeIndex($email);

        if ($key !== false) {
            return $this->item['attendees'][$key];
        }

        return  false;
    }

    public function addAttendee($email, $status = 'needsAction')
    {
        $key = $this->getAttendeeIndex($email);

        if ($key === false) {
            if (!isset($this->item['attendees'])) {
                $this->item['attendees'] = [];
            }

            $this->item['attendees'][] = ['email' => $email, 'responseStatus' => $status];

            return true;
        }

        if ($this->item['attendees'][$key]['responseStatus'] != $status) {
            $this->item['attendees'][$key]['responseStatus'] = $status;

            return true;
        }

        return false;
    }

    private function getAttendeeIndex($email)
    {
        if (isset($this->item['attendees']) && is_array($this->item['attendees'])) {
            foreach ($this->item['attendees'] as $key => $attendee) {
                if (strtolower($attendee['email']) == strtolower($email)) {
                    return $key;
                }
            }
        }

        return false;
    }


    /**
     * @return array<string, mixed>
     */
    public function build()
    {
        return $this->item;
    }

    private function decodeGoogleDate($date)
    {
        $fieldName = (isset($date['dateTime'])) ? 'dateTime' : 'date' ;

        if (!isset($date['timeZone'])) {
            if ($fieldName == 'date') {
                $calendarTZ = (isset($this->defaults['userTimeZone'])) ? $this->defaults['userTimeZone'] : 'UTC';
            } else {
                $calendarTZ = (isset($this->defaults['timeZone'])) ? $this->defaults['timeZone'] : 'UTC';
            }
        } else {
            $calendarTZ = $date['timeZone'];
        }

        if (!$calendarTZ) {
            $calendarTZ = 'UTC';
        }

        $tz = new DateTimeZone($calendarTZ);
        $dateTime = new DateTime($date[$fieldName], $tz);

        $utcTZ = new DateTimeZone('UTC');
        $dateTime->setTimeZone($utcTZ);

        return $dateTime->format(self::RETURN_FORMAT_DATETIME);

    }

    private function decodeGoogleDateDate($date, $oneDayShift = false)
    {
        if (isset($date['dateTime'])) {
            return null;
        }

        $dt = new DateTime($date['date']);

        if ($oneDayShift) {
            $dt->modify('-1 day');
        }

        return $dt->format(self::RETURN_FORMAT_DATE);
    }

    private function encodeGoogleDateTime($field, string $date)
    {
        $result = [];

        try {
            $dateTime = new DateTime($date);
        }
        catch (Exception $e) {
            throw new RuntimeException($e->getMessage());
        }

        /*if (isset($this->item[$field])) {
            $result = $this->item[$field];

            if (isset($result['dateTime'])) {
                $result['dateTime'] = $dateTime->format(self::FORMAT_RFC_3339);
            } else {
                $result['date'] = $dateTime->format('Y-m-d');
            }
        } else {*/
            $result['dateTime'] = $dateTime->format(self::FORMAT_RFC_3339);
        //}

        return $result;
    }

    private function encodeGoogleDate(string $date, bool $oneDayShift = false)
    {
        try {
            $dateTime = new DateTime($date);
        } catch (Exception $e) {
            throw new RuntimeException($e->getMessage());
        }

        if ($oneDayShift) {
            $dateTime->modify('+1 day');
        }

        return ['date' => $dateTime->format('Y-m-d')];
    }

    public function getJoinUrl(): ?string
    {
        $conferenceData = $this->item['conferenceData'] ?? null;

        if (!is_array($conferenceData)) {
            return null;
        }

        $entryPoints = $conferenceData['entryPoints'] ?? null;

        if (!is_array($entryPoints)) {
            return null;
        }

        foreach ($entryPoints as $point) {
            if (!is_array($point)) {
                return null;
            }

            $uri = $point['uri'] ?? null;

            if ($uri) {
                return $uri;
            }
        }

        return null;
    }
}
