<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTaskRecurrence\Tools;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use RRule\RSet;

/** The single scheduling contract used by preview, binding, and materialization. */
class Schedule
{
    public const WINDOW_DAYS = 30;
    public const WINDOW_LIMIT = 256;
    public const CHUNK_SIZE = 50;
    private const PROPERTIES = ['FREQ', 'INTERVAL', 'COUNT', 'UNTIL', 'BYDAY', 'BYMONTHDAY', 'BYMONTH', 'BYSETPOS', 'WKST', 'BYWEEKNO', 'BYYEARDAY', 'BYHOUR', 'BYMINUTE', 'BYSECOND'];

    public function normalize(object $input, ?object $task = null): object
    {
        $basis = $input->basis ?? 'ScheduledDate';
        if (!in_array($basis, ['ScheduledDate', 'CompletedDate'], true)) {
            throw new InvalidArgumentException('recurrence.basis: Choose scheduled date or completed date.');
        }
        $timezone = $input->timezone ?? 'UTC';
        if (!is_string($timezone) || !in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new InvalidArgumentException('recurrence.timezone: Choose an IANA timezone.');
        }
        $dateOnly = $input->dateOnly ?? !empty($task->dateEndDate);
        if (!is_bool($dateOnly)) throw new InvalidArgumentException('recurrence.dateOnly: Expected a boolean.');
        $anchor = $input->anchor ?? ($dateOnly ? ($task->dateEndDate ?? null) : ($task->dateEnd ?? null));
        $start = $this->date($anchor, $dateOnly ? 'Y-m-d' : 'Y-m-d H:i:s', new DateTimeZone('UTC'));
        $definition = (object) [
            'basis' => $basis, 'timezone' => $timezone, 'dateOnly' => $dateOnly,
            'anchor' => $start->format($dateOnly ? 'Y-m-d' : 'Y-m-d H:i:s'),
        ];
        if ($basis === 'CompletedDate') {
            if (!empty($input->schedule)) throw new InvalidArgumentException('recurrence.schedule: Calendar filters do not apply to completed-date repetition.');
            $interval = $input->interval ?? null;
            if (!is_object($interval) || !in_array($interval->unit ?? null, ['day', 'week', 'month', 'year'], true) ||
                !is_int($interval->value ?? null) || $interval->value < 1 || $interval->value > 1000) {
                throw new InvalidArgumentException('recurrence.interval: Choose an interval from 1 to 1000 days, weeks, months, or years.');
            }
            $definition->interval = (object) ['unit' => $interval->unit, 'value' => $interval->value];
            $definition->count = $input->count ?? null;
            if ($definition->count !== null && (!is_int($definition->count) || $definition->count < 1 || $definition->count > 10000)) {
                throw new InvalidArgumentException('recurrence.count: Choose 1–10000 occurrences.');
            }
            $definition->until = empty($input->until) ? null : $this->date($input->until, 'Y-m-d', new DateTimeZone('UTC'))->format('Y-m-d');
            if ($definition->count !== null && $definition->until !== null) throw new InvalidArgumentException('recurrence: Choose one end condition.');
            if ($definition->until !== null && $this->localDate($definition->anchor, $definition) > $definition->until) throw new InvalidArgumentException('recurrence.until: The end date must include the initial deadline.');
            return $definition;
        }
        if (isset($input->interval) || isset($input->count) || isset($input->until)) {
            throw new InvalidArgumentException('recurrence: Calendar end conditions belong in RRULE, not the completion interval.');
        }
        $text = $input->schedule ?? '';
        if (!is_string($text) || strlen($text) > 16384 || trim($text) === '') {
            throw new InvalidArgumentException('recurrence.schedule: Provide an RRULE or specific dates (maximum 16 KB).');
        }
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if (str_starts_with($text, 'FREQ=')) $text = 'RRULE:' . $text;
        if (!preg_match('/(^|\n)DTSTART[;:]/', $text)) {
            $text = $this->property('DTSTART', $start, $definition) . "\n" . $text;
        }
        $definition->schedule = $text;
        $set = $this->set($definition);
        $first = $set->getOccurrences(1)[0] ?? null;
        if (!$first) throw new InvalidArgumentException('recurrence.schedule: The schedule has no effective dates.');
        $definition->anchor = $this->deadline($first, $definition);
        $this->assertDensity($definition);
        return $definition;
    }

    public function preview(object $definition, ?string $exampleCompletion = null, int $sequence = 1, ?string $from = null): object
    {
        if ($definition->basis === 'CompletedDate') {
            $event = $exampleCompletion ? $this->date($exampleCompletion, 'Y-m-d H:i:s', new DateTimeZone('UTC')) : new DateTimeImmutable('now');
            $deadline = $this->completedDeadline($definition, $event);
            $exhausted = ($definition->count !== null && $sequence + 1 > $definition->count) ||
                ($definition->until !== null && $this->localDate($deadline, $definition) > $definition->until);
            return (object) ['definition' => $definition, 'hypothetical' => true, 'exampleCompletion' => $event->format('Y-m-d H:i:s'),
                'dates' => $exhausted ? [] : [$deadline], 'exhausted' => $exhausted, 'summary' => $this->summary($definition)];
        }
        return (object) ['definition' => $definition, 'hypothetical' => false, 'summary' => $this->summary($definition),
            'dates' => array_map(fn ($date) => $this->deadline($date, $definition), $from === null ? $this->set($definition)->getOccurrences(10) : $this->set($definition)->getOccurrencesAfter($this->slotDate($from, $definition), true, 10))];
    }

    /** Bounded, exclusive cursor. The caller persists each committed slot independently. */
    public function next(object $definition, ?string $cursor, int $limit = self::CHUNK_SIZE): array
    {
        $set = $this->set($definition);
        $dates = $cursor === null ? $set->getOccurrences($limit) : $set->getOccurrencesAfter($this->slotDate($cursor, $definition), false, $limit);
        return array_map(fn ($date) => $this->deadline($date, $definition), $dates);
    }

    public function identity(string $deadline, object $definition): string
    {
        return $definition->dateOnly ? 'D:' . $deadline : 'T:' . $this->slotDate($deadline, $definition)->format('Ymd\THis\Z');
    }

    public function contains(string $deadline, object $definition): bool
    {
        return $this->set($definition)->occursAt($this->slotDate($deadline, $definition));
    }

    public function completedDeadline(object $definition, DateTimeInterface $event): string
    {
        $zone = new DateTimeZone($definition->timezone);
        $date = DateTimeImmutable::createFromInterface($event)->setTimezone($zone)->setTime(0, 0);
        $value = $definition->interval->value;
        $unit = $definition->interval->unit;
        if ($unit === 'day' || $unit === 'week') {
            $date = $date->modify('+' . ($unit === 'week' ? $value * 7 : $value) . ' days');
        } else {
            $day = (int) $date->format('j');
            $date = $date->modify('first day of this month')->modify('+' . ($unit === 'year' ? $value * 12 : $value) . ' months');
            $date = $date->setDate((int) $date->format('Y'), (int) $date->format('n'), min($day, (int) $date->format('t')));
        }
        if ($definition->dateOnly) return $date->format('Y-m-d');
        $anchor = $this->slotDate($definition->anchor, $definition)->setTimezone($zone);
        $date = $date->setTime((int) $anchor->format('H'), (int) $anchor->format('i'), (int) $anchor->format('s'));
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function slotDate(string $deadline, object $definition): DateTimeImmutable
    {
        return $this->date($deadline, $definition->dateOnly ? 'Y-m-d' : 'Y-m-d H:i:s', new DateTimeZone('UTC'));
    }

    public function localDate(string $deadline, object $definition): string
    {
        return $definition->dateOnly ? $deadline : $this->slotDate($deadline, $definition)->setTimezone(new DateTimeZone($definition->timezone))->format('Y-m-d');
    }

    public function property(string $name, DateTimeInterface $date, object $definition): string
    {
        if ($definition->dateOnly) return $name . ';VALUE=DATE:' . $date->format('Ymd');
        $local = DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone($definition->timezone));
        return $name . ';TZID=' . $definition->timezone . ':' . $local->format('Ymd\THis');
    }

    public function summary(object $definition): object
    {
        // Structured, localized by both native clients; raw advanced text is never reserialized.
        if ($definition->basis === 'CompletedDate') return (object) ['basis' => $definition->basis, 'unit' => $definition->interval->unit, 'interval' => $definition->interval->value];
        preg_match('/(?:^|\n)RRULE:([^\n]+)/', $definition->schedule, $match);
        $parts = [];
        foreach (explode(';', $match[1] ?? '') as $pair) {
            if (str_contains($pair, '=')) { [$key, $value] = explode('=', $pair, 2); $parts[$key] = $value; }
        }
        return (object) ['basis' => $definition->basis, 'frequency' => $parts['FREQ'] ?? 'RDATE', 'interval' => (int) ($parts['INTERVAL'] ?? 1), 'parts' => (object) $parts];
    }

    public function assertDensity(object $definition, ?string $from = null): void
    {
        $start = $this->slotDate($from ?? $definition->anchor, $definition);
        $dates = $this->set($definition)->getOccurrencesBetween($start, $start->modify('+31 days'), self::WINDOW_LIMIT + 1);
        if (count($dates) > self::WINDOW_LIMIT) throw new InvalidArgumentException('recurrence.schedule: At most 256 Tasks may fall within a 31-day planning window.');
    }

    private function set(object $definition): RSet
    {
        $starts = 0;
        $rules = 0;
        foreach (explode("\n", $definition->schedule) as $line) {
            if (!preg_match('/^(DTSTART|RRULE|RDATE|EXDATE)((?:;[^:]*)?):(.+)$/', trim($line), $match)) {
                throw new InvalidArgumentException('recurrence.schedule: Supported properties are DTSTART, one RRULE, RDATE, and EXDATE.');
            }
            [, $name, $params, $value] = $match;
            if ($name === 'RRULE') {
                $rules++;
                $parts = [];
                foreach (explode(';', $value) as $pair) {
                    $entry = explode('=', $pair);
                    if (count($entry) !== 2 || !in_array($entry[0], self::PROPERTIES, true) || isset($parts[$entry[0]])) throw new InvalidArgumentException('recurrence.schedule: Invalid or repeated RRULE property.');
                    $parts[$entry[0]] = $entry[1];
                }
                if (isset($parts['COUNT'], $parts['UNTIL'])) throw new InvalidArgumentException('recurrence.schedule: COUNT and UNTIL are mutually exclusive.');
                if (isset($parts['COUNT']) && (!ctype_digit($parts['COUNT']) || (int) $parts['COUNT'] > 10000)) throw new InvalidArgumentException('recurrence.schedule: COUNT must be 1–10000.');
                $minInterval = ['HOURLY' => 3, 'MINUTELY' => 180, 'SECONDLY' => 10800][$parts['FREQ'] ?? ''] ?? 1;
                if ((int) ($parts['INTERVAL'] ?? 1) < $minInterval) throw new InvalidArgumentException('recurrence.schedule: Sub-daily repetition requires an interval of at least three hours.');
                if ($definition->dateOnly && (isset($parts['BYHOUR']) || isset($parts['BYMINUTE']) || isset($parts['BYSECOND']) || $minInterval > 1)) throw new InvalidArgumentException('recurrence.schedule: Date-only rules cannot contain time components.');
                if (isset($parts['BYSECOND']) && in_array('60', explode(',', $parts['BYSECOND']), true)) throw new InvalidArgumentException('recurrence.schedule: Leap seconds are not supported.');
                $combinations = 1;
                foreach (['BYHOUR', 'BYMINUTE', 'BYSECOND'] as $part) $combinations *= isset($parts[$part]) ? count(array_unique(explode(',', $parts[$part]))) : 1;
                if ($combinations > self::WINDOW_LIMIT) throw new InvalidArgumentException('recurrence.schedule: The time-component expansion exceeds the 256-slot budget.');
                continue;
            }
            if ($name === 'DTSTART') $starts++;
            $isDate = $params === ';VALUE=DATE';
            if ($isDate !== $definition->dateOnly) throw new InvalidArgumentException('recurrence.schedule: All dates must match the Task deadline type.');
            foreach (explode(',', $value) as $date) {
                if ($isDate) { $this->date($date, 'Ymd', new DateTimeZone('UTC')); continue; }
                if (!preg_match('/^\d{8}T\d{6}Z?$/', $date)) throw new InvalidArgumentException('recurrence.schedule: Invalid date-time.');
                if (str_ends_with($date, 'Z')) {
                    if ($params !== '' && $params !== ';VALUE=DATE-TIME') throw new InvalidArgumentException('recurrence.schedule: UTC dates cannot use TZID.');
                    $this->date($date, 'Ymd\THis\Z', new DateTimeZone('UTC'));
                } else {
                    if ($params !== ';TZID=' . $definition->timezone) throw new InvalidArgumentException('recurrence.schedule: Date-times must use the series TZID or UTC Z.');
                    $this->date($date, 'Ymd\THis', new DateTimeZone($definition->timezone));
                }
            }
        }
        if ($starts !== 1 || $rules > 1) throw new InvalidArgumentException('recurrence.schedule: Exactly one DTSTART and at most one RRULE are required.');
        try {
            $parsed = new RSet($definition->schedule);
            if ($definition->dateOnly) return $parsed;
            $set = new RSet();
            foreach ($parsed->getRRules() as $rule) $set->addRRule(new WallTimeRule($rule));
            foreach ($parsed->getDates() as $date) $set->addDate($date);
            foreach ($parsed->getExDates() as $date) $set->addExDate($date);
            return $set;
        }
        catch (\Throwable $e) { throw new InvalidArgumentException('recurrence.schedule: ' . $e->getMessage(), 0, $e); }
    }

    private function deadline(DateTimeInterface $date, object $definition): string
    {
        return $definition->dateOnly ? $date->format('Y-m-d') : DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function date(mixed $value, string $format, DateTimeZone $zone): DateTimeImmutable
    {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!' . $format, $value, $zone) : false;
        if (!$date || $date->format($format) !== $value) throw new InvalidArgumentException('recurrence.anchor: A valid deadline/date is required (' . $format . ').');
        return $date;
    }
}
