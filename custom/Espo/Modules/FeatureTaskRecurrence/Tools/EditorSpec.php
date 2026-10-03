<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTaskRecurrence\Tools;

use DateTimeImmutable;
use DateTimeZone;
use RRule\RSet;

/** Lossless simple-form capability detection. Unrepresentable definitions stay in advanced mode. */
class EditorSpec
{
    public function describe(object $definition): object
    {
        $editor = (object) ['preset' => 'advanced', 'basis' => $definition->basis, 'representable' => false];
        if ($definition->basis === 'CompletedDate') return (object) ['preset' => 'custom', 'basis' => 'CompletedDate', 'representable' => true,
            'unit' => $definition->interval->unit, 'interval' => $definition->interval->value,
            'ends' => $definition->count !== null ? 'count' : ($definition->until !== null ? 'until' : 'never'),
            'count' => $definition->count ?? 10, 'until' => $definition->until ?? ''];
        $set = new RSet($definition->schedule);
        $rule = $set->getRRules()[0] ?? null;
        $parts = $rule?->getRule() ?? [];
        $zone = new DateTimeZone($definition->timezone);
        preg_match('/(?:^|\n)DTSTART([^:]*):([^\n]+)/', $definition->schedule, $match);
        $start = $parts['DTSTART'] ?? new DateTimeImmutable($match[2], $definition->dateOnly ? new DateTimeZone('UTC') : $zone);
        $local = $definition->dateOnly ? DateTimeImmutable::createFromInterface($start) : DateTimeImmutable::createFromInterface($start)->setTimezone($zone);
        if (!$definition->dateOnly && ($local->format('s') !== '00' || (str_ends_with(trim($match[2]), 'Z') && $definition->timezone !== 'UTC'))) return $editor;
        foreach (['BYWEEKNO', 'BYYEARDAY', 'BYHOUR', 'BYMINUTE', 'BYSECOND'] as $key) if (isset($parts[$key]) && $parts[$key] !== '' && $parts[$key] !== []) return $editor;
        $dates = [];
        foreach (['additions' => $set->getDates(), 'exclusions' => $set->getExDates()] as $key => $values) {
            $dates[$key] = [];
            foreach ($values as $date) {
                $date = DateTimeImmutable::createFromInterface($date);
                if (!$definition->dateOnly) {
                    $date = $date->setTimezone($zone);
                    if ($date->format('H:i:s') !== $local->format('H:i:s')) return $editor;
                }
                $dates[$key][] = $date->format('Y-m-d');
            }
            $dates[$key] = array_values(array_unique($dates[$key])); sort($dates[$key]);
        }
        $frequency = $parts['FREQ'] ?? 'RDATE';
        if (!in_array($frequency, ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY', 'RDATE'], true)) return $editor;
        $until = !empty($parts['UNTIL']) ? DateTimeImmutable::createFromInterface($parts['UNTIL']) : null;
        if ($until && !$definition->dateOnly) {
            $until = $until->setTimezone($zone);
            if ($until->format('H:i:s') !== '23:59:59') return $editor;
        }
        $list = static fn ($value): array => empty($value) ? [] : (is_array($value) ? $value : explode(',', (string) $value));
        $days = $list($parts['BYDAY'] ?? null);
        $monthDays = array_map('intval', $list($parts['BYMONTHDAY'] ?? null));
        $months = array_map('intval', $list($parts['BYMONTH'] ?? null));
        $positions = array_map('intval', $list($parts['BYSETPOS'] ?? null));
        $weekday = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'][(int) $local->format('N') - 1];
        if ($frequency !== 'YEARLY' && $months) return $editor;
        if ($frequency === 'DAILY' && ($days || $monthDays || $positions)) return $editor;
        if ($frequency === 'WEEKLY' && $positions) return $editor;
        $pattern = 'days'; $ordinal = -1; $ordinalDays = [$weekday];
        if (in_array($frequency, ['MONTHLY', 'YEARLY'], true) && $days) {
            if ($monthDays) return $editor;
            $pattern = 'ordinal';
            if (count($days) === 1 && preg_match('/^(-1|[1-5])(MO|TU|WE|TH|FR|SA|SU)$/', $days[0], $ordinalMatch)) {
                if ($positions || ($frequency === 'YEARLY' && count($months) > 1)) return $editor;
                $ordinal = (int) $ordinalMatch[1]; $ordinalDays = [$ordinalMatch[2]];
            } elseif (count($positions) === 1 && in_array($positions[0], [1, 2, 3, 4, 5, -1], true)) {
                foreach ($days as $day) if (!in_array($day, ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'], true)) return $editor;
                $ordinal = $positions[0]; $ordinalDays = $days;
            } else return $editor;
        } elseif ($positions) return $editor;
        foreach ($monthDays as $day) if ($day !== -1 && ($day < 1 || $day > 31)) return $editor;
        $interval = (int) ($parts['INTERVAL'] ?? 1);
        $preset = 'custom';
        if ($frequency === 'RDATE') $preset = 'specific';
        elseif ($interval === 1 && $frequency === 'DAILY') $preset = 'daily';
        elseif ($interval === 1 && $frequency === 'WEEKLY' && (!$days || $days === [$weekday])) $preset = 'weekly';
        elseif ($interval === 1 && $frequency === 'WEEKLY' && $days === ['MO', 'TU', 'WE', 'TH', 'FR']) $preset = 'weekdays';
        elseif ($interval === 1 && $pattern === 'days' && (!$monthDays || $monthDays === [(int) $local->format('j')])) {
            if ($frequency === 'MONTHLY') $preset = 'monthly';
            if ($frequency === 'YEARLY' && (!$months || $months === [(int) $local->format('n')])) $preset = 'yearly';
        }
        return (object) ['preset' => $preset, 'basis' => 'ScheduledDate', 'representable' => true,
            'frequency' => $frequency === 'RDATE' ? 'DAILY' : $frequency, 'interval' => $interval,
            'start' => $local->format('Y-m-d'), 'time' => $local->format('H:i:s'), 'weekStart' => $parts['WKST'] ?? 'MO',
            'weekdays' => $frequency === 'WEEKLY' ? ($days ?: [$weekday]) : $ordinalDays, 'monthDays' => $monthDays ?: [(int) $local->format('j')],
            'monthPattern' => $pattern, 'ordinal' => $ordinal, 'ordinalDays' => $ordinalDays,
            'months' => $months ?: [(int) $local->format('n')], 'dates' => $frequency === 'RDATE' ? $dates['additions'] : [],
            'additions' => $frequency === 'RDATE' ? [] : $dates['additions'], 'exclusions' => $dates['exclusions'],
            'ends' => !empty($parts['COUNT']) ? 'count' : ($until ? 'until' : 'never'), 'count' => (int) ($parts['COUNT'] ?? 10), 'until' => $until?->format('Y-m-d') ?? ''];
    }
}
