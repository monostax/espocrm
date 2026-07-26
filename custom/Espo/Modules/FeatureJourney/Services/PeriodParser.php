<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use DateInterval;
use InvalidArgumentException;

/**
 * Parses period strings like "3 days", "12 hours", "30 minutes" into DateInterval.
 */
class PeriodParser
{
    public function parse(string $period): DateInterval
    {
        $period = trim($period);

        if ($period === '') {
            throw new InvalidArgumentException('Empty period.');
        }

        if (preg_match('/^P/i', $period)) {
            return new DateInterval($period);
        }

        if (!preg_match('/^(\d+)\s*(second|seconds|minute|minutes|hour|hours|day|days|week|weeks)$/i', $period, $m)) {
            throw new InvalidArgumentException("Invalid period '{$period}'.");
        }

        $n = (int) $m[1];
        $unit = strtolower($m[2]);

        $map = [
            'second' => 'PT%dS', 'seconds' => 'PT%dS',
            'minute' => 'PT%dM', 'minutes' => 'PT%dM',
            'hour' => 'PT%dH', 'hours' => 'PT%dH',
            'day' => 'P%dD', 'days' => 'P%dD',
            'week' => 'P%dW', 'weeks' => 'P%dW',
        ];

        return new DateInterval(sprintf($map[$unit], $n));
    }

    public function addToNow(string $period, ?int $fromTs = null): string
    {
        $dt = new \DateTimeImmutable('@' . ($fromTs ?? time()));
        $dt = $dt->setTimezone(new \DateTimeZone('UTC'));
        $dt = $dt->add($this->parse($period));

        return $dt->format('Y-m-d H:i:s');
    }

    public function isDue(string $baseDatetime, string $period, ?int $now = null): bool
    {
        $now = $now ?? time();
        $base = new \DateTimeImmutable($baseDatetime, new \DateTimeZone('UTC'));
        $due = $base->add($this->parse($period));

        return $due->getTimestamp() <= $now;
    }
}
