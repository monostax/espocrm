<?php

namespace Espo\Modules\PackEnterprise\Core\GoogleCalendar\Items;

use DateTime;
use DateTimeZone;
use Exception;
use RuntimeException;

/**
 * Data model for a Google Calendar event.
 * Handles conversion between Google Calendar API format and EspoCRM format.
 */
class CalendarEvent
{
    public const EVENT_TYPE_DEFAULT = 'default';
    public const EVENT_TYPE_FROM_GMAIL = 'fromGmail';

    private const RETURN_FORMAT_DATETIME = 'Y-m-d H:i:s';
    private const RETURN_FORMAT_DATE = 'Y-m-d';
    private const FORMAT_RFC_3339 = "Y-m-d\TH:i:s\Z";

    /** @var array<string, mixed> */
    private array $item;

    /** @var array<string, mixed> */
    private array $defaults = [];

    /**
     * @param array<string, mixed> $item
     */
    public function __construct(array $item = [])
    {
        $this->item = $item;
    }

    public function getId(): ?string
    {
        return $this->item['id'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefaults(): array
    {
        return $this->defaults;
    }

    /**
     * @param array<string, mixed> $value
     */
    public function setDefaults(array $value = []): void
    {
        $this->defaults = array_merge($this->defaults, $value);
    }

    public function getEventType(): ?string
    {
        return $this->item['eventType'] ?? null;
    }

    public function getStatus(): ?string
    {
        return $this->item['status'] ?? null;
    }

    public function setStatus(string $value): void
    {
        $this->item['status'] = $value;
    }

    public function getSource(): ?string
    {
        return isset($this->item['source']) ? $this->item['source']['title'] : null;
    }

    public function setSource(string $title = '', string $url = ''): void
    {
        if (!empty($title) && empty($this->item['source']['title'])) {
            $this->item['source']['title'] = $title;
        }

        if (!empty($url) && empty($this->item['source']['url'])) {
            $this->item['source']['url'] = $url;
        }
    }

    public function isDeleted(): bool
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

    public function hasEnd(): bool
    {
        return !isset($this->item['endTimeUnspecified']);
    }

    public function restore(): void
    {
        $this->setStatus('confirmed');
    }

    public function isPrivate(): bool
    {
        $visibility = $this->item['visibility'] ?? null;

        return in_array($visibility, ['private', 'confidential']);
    }

    public function getSummary(): ?string
    {
        return $this->item['summary'] ?? null;
    }

    public function setSummary(?string $value): void
    {
        $this->item['summary'] = $value;
    }

    public function getLocation(): ?string
    {
        return $this->item['location'] ?? null;
    }

    public function setLocation(?string $value): void
    {
        $this->item['location'] = $value;
    }

    public function getDescription(): ?string
    {
        return $this->item['description'] ?? null;
    }

    public function setDescription(?string $value): void
    {
        $this->item['description'] = $value;
    }

    public function getStart(): ?string
    {
        if (isset($this->item['start'])) {
            return $this->decodeGoogleDate($this->item['start']);
        }

        return null;
    }

    public function getEnd(): ?string
    {
        if (isset($this->item['end'])) {
            return $this->decodeGoogleDate($this->item['end']);
        }

        return null;
    }

    public function getStartDate(): ?string
    {
        if (isset($this->item['start'])) {
            return $this->decodeGoogleDateDate($this->item['start']);
        }

        return null;
    }

    public function getEndDate(): ?string
    {
        if (isset($this->item['end'])) {
            return $this->decodeGoogleDateDate($this->item['end'], true);
        }

        return null;
    }

    public function setStart(?string $value): void
    {
        $this->item['start'] = $this->encodeGoogleDateTime('start', $value);
    }

    public function setEnd(?string $value): void
    {
        $this->item['end'] = $this->encodeGoogleDateTime('end', $value);
    }

    public function setStartDate(?string $value): void
    {
        if (!$value) {
            return;
        }

        $this->item['start'] = $this->encodeGoogleDate($value);
    }

    public function setEndDate(?string $value): void
    {
        if (!$value) {
            return;
        }

        $this->item['end'] = $this->encodeGoogleDate($value, true);
    }

    /**
     * @return mixed
     */
    public function getRecurrence()
    {
        return $this->item['recurrence'] ?? null;
    }

    /**
     * @return mixed
     */
    public function getRecurringEventId()
    {
        return $this->item['recurringEventId'] ?? null;
    }

    /**
     * @return string|false
     */
    public function updated()
    {
        if (isset($this->item['updated'])) {
            try {
                $updated = new DateTime($this->item['updated']);
            } catch (Exception $e) {
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

    public function getOrganizerEmail(): ?string
    {
        $organizer = $this->item['organizer'] ?? [];

        return $organizer['email'] ?? null;
    }

    public function isOrganizer(): bool
    {
        $organizer = $this->item['organizer'] ?? [];

        return !empty($organizer['self']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAttendees(): array
    {
        return $this->item['attendees'] ?? [];
    }

    public function deleteAttendee(string $email): void
    {
        $key = $this->getAttendeeIndex($email);

        if ($key !== false) {
            unset($this->item['attendees'][$key]);
        }
    }

    /**
     * @return array<string, mixed>|false
     */
    public function findAttendee(string $email)
    {
        $key = $this->getAttendeeIndex($email);

        if ($key !== false) {
            return $this->item['attendees'][$key];
        }

        return false;
    }

    public function addAttendee(string $email, string $status = 'needsAction'): bool
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

    /**
     * @return int|false
     */
    private function getAttendeeIndex(string $email)
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
    public function build(): array
    {
        return $this->item;
    }

    /**
     * @param array<string, mixed> $date
     */
    private function decodeGoogleDate(array $date): string
    {
        $fieldName = isset($date['dateTime']) ? 'dateTime' : 'date';

        if (!isset($date['timeZone'])) {
            if ($fieldName == 'date') {
                $calendarTZ = $this->defaults['userTimeZone'] ?? 'UTC';
            } else {
                $calendarTZ = $this->defaults['timeZone'] ?? 'UTC';
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

    /**
     * @param array<string, mixed> $date
     */
    private function decodeGoogleDateDate(array $date, bool $oneDayShift = false): ?string
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

    /**
     * @return array<string, string>
     */
    private function encodeGoogleDateTime(string $field, ?string $date): array
    {
        try {
            $dateTime = new DateTime($date);
        } catch (Exception $e) {
            throw new RuntimeException($e->getMessage());
        }

        return ['dateTime' => $dateTime->format(self::FORMAT_RFC_3339)];
    }

    /**
     * @return array<string, string>
     */
    private function encodeGoogleDate(string $date, bool $oneDayShift = false): array
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
