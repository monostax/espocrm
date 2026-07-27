<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Services;

use RRule\RSet;

/**
 * RFC 5545 recurrence-set helpers for Automation.scheduling.
 *
 * A schedule can contain one or more RRULE lines as well as EXDATE, RDATE,
 * and EXRULE entries. Bare FREQ=... values are normalized into a recurrence
 * set using the Automation activation timestamp as DTSTART.
 */
class ScheduleHelper
{
    /**
     * @throws \InvalidArgumentException
     */
    public function validate(string $schedule, string $timezone = 'UTC'): void
    {
        $this->buildSet($schedule, $timezone, null);
    }

    /**
     * Whether a schedule is due at $now given its persisted next occurrence.
     *
     * nextRunAt is preferred because it preserves RRULE seconds and avoids
     * re-iterating a long-running high-frequency recurrence on every poll.
     */
    public function isDue(
        string $schedule,
        ?string $lastRunAt,
        string $timezone = 'UTC',
        ?\DateTimeInterface $now = null,
        ?\DateTimeInterface $startAt = null,
        ?string $nextRunAt = null,
    ): bool {
        try {
            $tz = $this->getTimezone($timezone);
            $nowDt = $now
                ? \DateTimeImmutable::createFromInterface($now)->setTimezone($tz)
                : new \DateTimeImmutable('now', $tz);
            $last = $this->parseUtcDate($lastRunAt);
            $set = $this->buildSet($schedule, $timezone, $startAt);
            $next = $this->parseUtcDate($nextRunAt);
            if ($next) {
                if ($last && $last >= $next) {
                    return false;
                }

                return $next <= $nowDt;
            }

            // Existing schedules without nextRunAt retain the previous
            // minute-granular behavior unless their rule explicitly uses seconds.
            $point = $this->hasSecondPrecision($set)
                ? $nowDt
                : $this->toMinute($nowDt, $tz);

            if ($last && $last >= $point) {
                return false;
            }

            return $set->occursAt($point);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Next recurrence-set occurrence as a UTC timestamp for display and due tracking.
     */
    public function nextRunAt(
        string $schedule,
        string $timezone = 'UTC',
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $startAt = null,
    ): ?string {
        try {
            $tz = $this->getTimezone($timezone);
            $fromDt = $from
                ? \DateTimeImmutable::createFromInterface($from)->setTimezone($tz)
                : new \DateTimeImmutable('now', $tz);
            $next = $this->buildSet($schedule, $timezone, $startAt)
                ->getNthOccurrenceAfter($fromDt, 1);

            if (!$next) {
                return null;
            }

            return \DateTimeImmutable::createFromInterface($next)
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    // RSet's vendor type omits an IteratorAggregate value type.
    /** @phpstan-ignore-next-line missingType.iterableValue */
    private function buildSet(
        string $schedule,
        string $timezone,
        ?\DateTimeInterface $startAt,
    ): RSet {
        $schedule = trim(str_replace(["\r\n", "\r"], "\n", $schedule));
        if ($schedule === '') {
            throw new \InvalidArgumentException('RRULE is required.');
        }

        $tz = $this->getTimezone($timezone);
        $fallbackStart = $startAt
            ? \DateTimeImmutable::createFromInterface($startAt)
            : new \DateTimeImmutable('2000-01-01 00:00:00', $tz);
        $fallbackStart = $this->toMinute($fallbackStart, $tz);
        $schedule = $this->normalizeSet($schedule, $fallbackStart, $tz);

        try {
            $set = new RSet($schedule);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Invalid recurrence rule: ' . $e->getMessage(), 0, $e);
        }

        if ($set->getRRules() === [] && $set->getDates() === []) {
            throw new \InvalidArgumentException('A recurrence set requires an RRULE or RDATE.');
        }

        return $set;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function normalizeSet(
        string $schedule,
        \DateTimeImmutable $fallbackStart,
        \DateTimeZone $timezone,
    ): string {
        $hasDtStart = preg_match('/(^|\n)\s*DTSTART(?:;[^:]*)?:/i', $schedule) === 1;
        if ($hasDtStart && !preg_match(
            '/(^|\n)\s*DTSTART(?:(?:;TZID=[^:]+):[^\n]*|:[^\n]*Z)\s*$/im',
            $schedule,
        )) {
            throw new \InvalidArgumentException('DTSTART must use TZID or UTC (Z).');
        }

        $hasProperty = preg_match('/(^|\n)\s*[A-Z][A-Z0-9-]*(?:;[^:]*)?:/i', $schedule) === 1;
        if (!$hasProperty) {
            $schedule = 'RRULE:' . $schedule;
        }

        if (!$hasDtStart) {
            $schedule = $this->formatDtStart($fallbackStart, $timezone) . "\n" . $schedule;
        }

        return $schedule;
    }

    /** @phpstan-ignore-next-line missingType.iterableValue */
    private function hasSecondPrecision(RSet $set): bool
    {
        foreach (array_merge($set->getRRules(), $set->getExRules()) as $rule) {
            $parts = $rule->getRule();
            if (strtoupper((string) ($parts['FREQ'] ?? '')) === 'SECONDLY') {
                return true;
            }

            foreach ($this->toList($parts['BYSECOND'] ?? null) as $second) {
                if ((int) $second !== 0) {
                    return true;
                }
            }

            $dtStart = $parts['DTSTART'] ?? null;
            if ($dtStart instanceof \DateTimeInterface && (int) $dtStart->format('s') !== 0) {
                return true;
            }
        }

        foreach (array_merge($set->getDates(), $set->getExDates()) as $date) {
            if ((int) $date->format('s') !== 0) {
                return true;
            }
        }

        return false;
    }

    private function formatDtStart(\DateTimeImmutable $date, \DateTimeZone $timezone): string
    {
        $date = $date->setTimezone($timezone);
        if ($timezone->getName() === 'UTC') {
            return 'DTSTART:' . $date->format('Ymd\THis\Z');
        }

        return 'DTSTART;TZID=' . $timezone->getName() . ':' . $date->format('Ymd\THis');
    }

    private function getTimezone(string $timezone): \DateTimeZone
    {
        try {
            return new \DateTimeZone($timezone ?: 'UTC');
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Invalid timezone: ' . $timezone, 0, $e);
        }
    }

    private function parseUtcDate(?string $value): ?\DateTimeImmutable
    {
        if (!$value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function toMinute(\DateTimeImmutable $date, \DateTimeZone $timezone): \DateTimeImmutable
    {
        $date = $date->setTimezone($timezone);

        return $date->setTime(
            (int) $date->format('G'),
            (int) $date->format('i'),
            0,
        );
    }

    /**
     * @return list<string|int>
     */
    private function toList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (!is_array($value)) {
            return explode(',', (string) $value);
        }

        return array_values(array_map(
            static fn (mixed $item): string|int => is_int($item) ? $item : (string) $item,
            $value,
        ));
    }
}
