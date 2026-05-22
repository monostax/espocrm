<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationCalCom\Services;

use stdClass;

/**
 * Parses and normalizes a cal.com webhook payload into a stable internal shape.
 *
 * Cal.com payload shape (BOOKING_CREATED example):
 *   {
 *     "triggerEvent": "BOOKING_CREATED",
 *     "createdAt": "2024-01-15T10:00:00.000Z",
 *     "payload": {
 *       "bookingId": 123,
 *       "uid": "abc123",
 *       "type": "30min",
 *       "title": "...",
 *       "startTime": "2024-01-20T15:00:00Z",
 *       "endTime":   "2024-01-20T15:30:00Z",
 *       "organizer": { "email": "...", "name": "..." },
 *       "attendees": [ { "email": "...", "name": "...", "timeZone": "..." } ],
 *       "eventType": { "id": 42, "slug": "30min" },
 *       "metadata":  { ... custom fields including fbclid/fbc/fbp ... },
 *       "responses": { ... form responses including phone, fbclid ... }
 *     }
 *   }
 *
 * Reference: https://cal.com/docs/core-features/webhooks/webhook-triggers
 */
class CalComPayloadParser
{
    /**
     * @return ParsedCalComBooking|null  Null if the payload is malformed or missing required attendee.
     */
    public function parse(stdClass $rawPayload): ?ParsedCalComBooking
    {
        $triggerEvent = isset($rawPayload->triggerEvent) && is_string($rawPayload->triggerEvent)
            ? $rawPayload->triggerEvent
            : null;

        if (!$triggerEvent) {
            return null;
        }

        $payload = $rawPayload->payload ?? null;

        if (!is_object($payload)) {
            return null;
        }

        $attendee = $this->firstAttendee($payload);

        if (!$attendee) {
            return null;
        }

        $email = $this->stringOrNull($attendee, 'email');

        if (!$email) {
            return null;
        }

        $bookingUid = $this->stringOrNull($payload, 'uid')
            ?? $this->stringOrNull($payload, 'bookingId');

        $name = $this->stringOrNull($attendee, 'name');
        [$firstName, $lastName] = $this->splitName($name);

        $phone = $this->extractPhone($payload, $attendee);

        $eventTypeSlug = null;
        $eventTypeId = null;
        if (isset($payload->eventType) && is_object($payload->eventType)) {
            $eventTypeSlug = $this->stringOrNull($payload->eventType, 'slug');
            $rawId = $payload->eventType->id ?? null;
            $eventTypeId = is_int($rawId) || is_string($rawId) ? (string) $rawId : null;
        }

        $startTime = $this->extractTimestamp($payload, 'startTime');
        $endTime   = $this->extractTimestamp($payload, 'endTime');

        $fbc = $this->extractTrackingId($payload, ['fbc', '_fbc']);
        $fbp = $this->extractTrackingId($payload, ['fbp', '_fbp']);
        $fbclid = $this->extractTrackingId($payload, ['fbclid']);

        // If we have fbclid but no fbc, construct fbc per Meta spec: fb.1.{ts}.{fbclid}
        if (!$fbc && $fbclid) {
            $fbc = sprintf('fb.1.%d.%s', $startTime ?? time(), $fbclid);
        }

        $title = $this->stringOrNull($payload, 'title');

        return new ParsedCalComBooking(
            triggerEvent: $triggerEvent,
            bookingUid: $bookingUid,
            email: $email,
            phone: $phone,
            firstName: $firstName,
            lastName: $lastName,
            title: $title,
            eventTypeSlug: $eventTypeSlug,
            eventTypeId: $eventTypeId,
            startTime: $startTime,
            endTime: $endTime,
            fbc: $fbc,
            fbp: $fbp,
            fbclid: $fbclid,
        );
    }

    private function firstAttendee(stdClass $payload): ?stdClass
    {
        if (!isset($payload->attendees) || !is_array($payload->attendees)) {
            return null;
        }

        $first = $payload->attendees[0] ?? null;

        return is_object($first) ? $first : null;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function splitName(?string $name): array
    {
        if ($name === null || trim($name) === '') {
            return [null, null];
        }

        $parts = preg_split('/\s+/', trim($name), 2) ?: [];

        $first = $parts[0] ?? null;
        $last  = $parts[1] ?? null;

        return [$first, $last];
    }

    private function extractPhone(stdClass $payload, stdClass $attendee): ?string
    {
        // Direct phone on attendee (newer cal.com versions).
        $phone = $this->stringOrNull($attendee, 'phoneNumber')
            ?? $this->stringOrNull($attendee, 'phone');

        if ($phone) {
            return $phone;
        }

        // Form responses (custom phone question).
        if (isset($payload->responses) && is_object($payload->responses)) {
            foreach (['phone', 'phoneNumber', 'tel', 'telephone'] as $key) {
                $value = $this->stringOrNull($payload->responses, $key);
                if ($value) {
                    return $value;
                }

                // Some responses are objects with a `value` field.
                if (isset($payload->responses->{$key}) && is_object($payload->responses->{$key})) {
                    $nested = $this->stringOrNull($payload->responses->{$key}, 'value');
                    if ($nested) {
                        return $nested;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param string[] $keys
     */
    private function extractTrackingId(stdClass $payload, array $keys): ?string
    {
        // 1. payload.metadata.{key}
        if (isset($payload->metadata) && is_object($payload->metadata)) {
            foreach ($keys as $k) {
                $value = $this->stringOrNull($payload->metadata, $k);
                if ($value) {
                    return $value;
                }
            }
        }

        // 2. payload.responses.{key}  (could be string or object {value: string})
        if (isset($payload->responses) && is_object($payload->responses)) {
            foreach ($keys as $k) {
                $value = $this->stringOrNull($payload->responses, $k);
                if ($value) {
                    return $value;
                }

                if (isset($payload->responses->{$k}) && is_object($payload->responses->{$k})) {
                    $nested = $this->stringOrNull($payload->responses->{$k}, 'value');
                    if ($nested) {
                        return $nested;
                    }
                }
            }
        }

        return null;
    }

    private function extractTimestamp(stdClass $payload, string $field): ?int
    {
        if (!isset($payload->{$field}) || !is_string($payload->{$field})) {
            return null;
        }

        $ts = strtotime($payload->{$field});

        return $ts === false ? null : $ts;
    }

    private function stringOrNull(stdClass $obj, string $field): ?string
    {
        if (!isset($obj->{$field})) {
            return null;
        }

        $value = $obj->{$field};

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }
}
