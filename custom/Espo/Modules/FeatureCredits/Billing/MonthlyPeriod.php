<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Billing;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Modules\FeatureCredits\Accounting\GrantInput;

/** Original Asaas due-date schedule, never arrival time or mutable subscription nextDueDate. */
final class MonthlyPeriod
{
    public static function forDueDate(string $anchor, string $due): array
    {
        GrantInput::timestamp($anchor . ' 00:00:00');
        GrantInput::timestamp($due . ' 00:00:00');
        $zone = new DateTimeZone('America/Sao_Paulo');
        $first = new DateTimeImmutable($anchor, $zone);
        $date = new DateTimeImmutable($due, $zone);
        $months = ((int) $date->format('Y') - (int) $first->format('Y')) * 12 + (int) $date->format('n') - (int) $first->format('n');
        if ($months < 0) throw new \InvalidArgumentException('Payment precedes original subscription schedule.');
        $boundary = static function (int $offset) use ($first): DateTimeImmutable {
            $month = $first->modify('first day of this month')->modify("+$offset months");
            return $month->setDate((int) $month->format('Y'), (int) $month->format('n'), min((int) $first->format('j'), (int) $month->format('t')));
        };
        $start = $boundary($months); $end = $boundary($months + 1);
        if ($start->format('Y-m-d') !== $due) throw new \InvalidArgumentException('Payment is off the original monthly schedule.');
        $utc = new DateTimeZone('UTC');
        return ['start' => $start->setTimezone($utc)->format('Y-m-d H:i:s'), 'end' => $end->setTimezone($utc)->format('Y-m-d H:i:s')];
    }

    /**
     * The end of the period after a paid one, on the same schedule. A monthly
     * grant lasts until then, so credits left at renewal carry over one month
     * and no more (grants are spent soonest-expiring first).
     */
    public static function followingEnd(string $startsAt, string $endsAt): string
    {
        GrantInput::timestamp($startsAt);
        GrantInput::timestamp($endsAt);
        $utc = new DateTimeZone('UTC');
        $zone = new DateTimeZone('America/Sao_Paulo');
        $start = (new DateTimeImmutable($startsAt, $utc))->setTimezone($zone);
        $end = (new DateTimeImmutable($endsAt, $utc))->setTimezone($zone);
        if ($end <= $start) throw new \InvalidArgumentException('A billing period must end after it starts.');
        // Only a short month clamps a boundary, and two short months never follow
        // each other, so the larger of the two days is the schedule's day.
        $day = max((int) $start->format('j'), (int) $end->format('j'));
        $month = $end->modify('first day of this month')->modify('+1 month');
        $next = $month->setDate((int) $month->format('Y'), (int) $month->format('n'), min($day, (int) $month->format('t')));
        return $next->setTimezone($utc)->format('Y-m-d H:i:s');
    }
}
