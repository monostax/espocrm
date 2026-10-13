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
}
