<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Exceptions\BadRequest;

/** Monday–Friday dates; holidays are not excluded. */
class BusinessDaySchedule
{
    public static function validateOffset(mixed $days): int
    {
        if ((!is_int($days) && !(is_string($days) && ctype_digit($days))) ||
            (int) $days < 0 || (int) $days > 3650) {
            throw new BadRequest('dueInBusinessDays must be an integer between 0 and 3650.');
        }

        return (int) $days;
    }

    public function dueDate(string $baseUtc, mixed $days, string $timeZone): string
    {
        $remaining = self::validateOffset($days);
        $date = (new DateTimeImmutable($baseUtc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone($timeZone))
            ->setTime(0, 0);

        // D0 on a weekend starts on the next Monday; all offsets share that anchor.
        while ((int) $date->format('N') > 5) {
            $date = $date->modify('+1 day');
        }

        while ($remaining > 0) {
            $date = $date->modify('+1 day');
            if ((int) $date->format('N') <= 5) {
                $remaining--;
            }
        }

        return $date->format('Y-m-d');
    }
}
