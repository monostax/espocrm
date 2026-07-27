<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Normalises absolute wait-until values to UTC SQL datetimes (Y-m-d H:i:s).
 *
 * Accepts common Espo/SQL forms, ISO-8601 with offset, date-only (midnight),
 * and unix timestamps (seconds). Optional $timezone applies when the input
 * has no explicit offset.
 */
class WakeAtResolver
{
    /**
     * @throws InvalidArgumentException
     */
    public function toUtcSql(mixed $value, ?string $timezone = null): string
    {
        $dt = $this->toUtcImmutable($value, $timezone);

        return $dt->format('Y-m-d H:i:s');
    }

    /**
     * Soft validate for definition-time fixed strings (no formula evaluation).
     */
    public function isValidFixed(string $value, ?string $timezone = null): bool
    {
        try {
            $this->toUtcImmutable($value, $timezone);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    public function toUtcImmutable(mixed $value, ?string $timezone = null): DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new DateTimeZone('UTC'));
        }

        if (is_int($value) || is_float($value)) {
            $ts = (int) $value;
            if ($ts > 1_000_000_000_000) {
                // ms → s
                $ts = (int) floor($ts / 1000);
            }
            if ($ts < 0) {
                throw new InvalidArgumentException('Negative unix timestamp.');
            }

            return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('UTC'));
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException('waitUntil must be a datetime string or unix timestamp.');
        }

        $raw = trim($value);
        if ($raw === '') {
            throw new InvalidArgumentException('Empty waitUntil.');
        }

        if (preg_match('/^-?\d+(\.\d+)?$/', $raw)) {
            return $this->toUtcImmutable((float) $raw, $timezone);
        }

        $tzName = $timezone !== null && trim($timezone) !== '' ? trim($timezone) : 'UTC';
        try {
            $tz = new DateTimeZone($tzName);
        } catch (Throwable $e) {
            throw new InvalidArgumentException("Invalid timezone '{$tzName}'.", 0, $e);
        }

        // Date only → start of day in zone
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            try {
                $dt = new DateTimeImmutable($raw . ' 00:00:00', $tz);
            } catch (Throwable $e) {
                throw new InvalidArgumentException("Invalid waitUntil date '{$raw}'.", 0, $e);
            }

            return $dt->setTimezone(new DateTimeZone('UTC'));
        }

        // SQL with space
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $raw)) {
            $norm = str_replace('T', ' ', $raw);
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $norm)) {
                $norm .= ':00';
            }
            try {
                $dt = new DateTimeImmutable($norm, $tz);
            } catch (Throwable $e) {
                throw new InvalidArgumentException("Invalid waitUntil '{$raw}'.", 0, $e);
            }

            return $dt->setTimezone(new DateTimeZone('UTC'));
        }

        // ISO-8601 with Z or offset (timezone arg ignored when offset present)
        try {
            $dt = new DateTimeImmutable($raw);
        } catch (Throwable $e) {
            throw new InvalidArgumentException("Invalid waitUntil '{$raw}'.", 0, $e);
        }

        return $dt->setTimezone(new DateTimeZone('UTC'));
    }
}
