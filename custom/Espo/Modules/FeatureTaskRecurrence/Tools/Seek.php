<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTaskRecurrence\Tools;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RRule\RRule;

/** Seek count-free rules by whole frequency periods, preserving DTSTART defaults and interval phase. */
class Seek
{
    public static function rule(RRule $rule, ?DateTimeInterface $from): RRule
    {
        $parts = $rule->getRule();
        if ($from === null || !empty($parts['COUNT'])) return $rule;
        $start = DateTimeImmutable::createFromInterface($parts['DTSTART']);
        $local = DateTimeImmutable::createFromInterface($from)->setTimezone($start->getTimezone());
        $civilZone = new DateTimeZone('UTC');
        $civilStart = new DateTimeImmutable($start->format('Y-m-d H:i:s'), $civilZone);
        $civilFrom = new DateTimeImmutable($local->format('Y-m-d H:i:s'), $civilZone);
        $interval = (int) ($parts['INTERVAL'] ?? 1);
        $frequency = $parts['FREQ'];
        $units = match ($frequency) {
            'YEARLY' => (int) $local->format('Y') - (int) $start->format('Y'),
            'MONTHLY' => ((int) $local->format('Y') - (int) $start->format('Y')) * 12 + (int) $local->format('n') - (int) $start->format('n'),
            default => (int) floor(($civilFrom->getTimestamp() - $civilStart->getTimestamp()) / (['WEEKLY' => 604800, 'DAILY' => 86400, 'HOURLY' => 3600, 'MINUTELY' => 60, 'SECONDLY' => 1][$frequency])),
        };
        $periods = max(0, (int) floor($units / $interval) - 1);
        if ($periods === 0) return $rule;
        // The same defaults the library derives from the immutable original anchor.
        if (empty($parts['BYWEEKNO']) && empty($parts['BYYEARDAY']) && empty($parts['BYMONTHDAY']) && empty($parts['BYDAY'])) {
            if ($frequency === 'YEARLY' && empty($parts['BYMONTH'])) $parts['BYMONTH'] = [(int) $start->format('n')];
            if (in_array($frequency, ['YEARLY', 'MONTHLY'], true)) $parts['BYMONTHDAY'] = [(int) $start->format('j')];
        }
        $shift = $periods * $interval;
        $seek = match ($frequency) {
            'YEARLY' => $civilStart->setDate((int) $start->format('Y') + $shift, 1, 1),
            'MONTHLY' => $civilStart->modify('first day of this month')->modify('+' . $shift . ' months'),
            default => $civilStart->modify('+' . ($shift * (['WEEKLY' => 604800, 'DAILY' => 86400, 'HOURLY' => 3600, 'MINUTELY' => 60, 'SECONDLY' => 1][$frequency])) . ' seconds'),
        };
        $parts['DTSTART'] = new DateTimeImmutable($seek->format('Y-m-d H:i:s'), $start->getTimezone());
        return new RRule($parts);
    }
}
