<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Services;

/**
 * Lightweight schedule helpers for Automation.scheduling.
 *
 * Supports:
 * - cron 5-field: m h dom mon dow  (e.g. "0 19 * * *")
 * - every:Nmin / every:Nminutes
 * - daily:HH:MM
 */
class ScheduleHelper
{
    /**
     * Whether a schedule is due at $now given last successful fire time.
     */
    public function isDue(
        string $scheduling,
        ?string $lastRunAt,
        string $timezone = 'UTC',
        ?\DateTimeInterface $now = null,
    ): bool {
        $scheduling = trim($scheduling);
        if ($scheduling === '') {
            return false;
        }

        try {
            $tz = new \DateTimeZone($timezone ?: 'UTC');
        } catch (\Throwable) {
            $tz = new \DateTimeZone('UTC');
        }

        $nowDt = $now
            ? \DateTimeImmutable::createFromInterface($now)->setTimezone($tz)
            : new \DateTimeImmutable('now', $tz);

        $last = null;
        if ($lastRunAt) {
            try {
                $last = new \DateTimeImmutable($lastRunAt, new \DateTimeZone('UTC'));
                $last = $last->setTimezone($tz);
            } catch (\Throwable) {
                $last = null;
            }
        }

        if (preg_match('/^every:(\d+)\s*(m|min|minutes?)?$/i', $scheduling, $m)) {
            $minutes = max(1, (int) $m[1]);
            if (!$last) {
                return true;
            }

            return $last->getTimestamp() + ($minutes * 60) <= $nowDt->getTimestamp();
        }

        if (preg_match('/^daily:(\d{1,2}):(\d{2})$/', $scheduling, $m)) {
            $scheduling = sprintf('%d %d * * *', (int) $m[2], (int) $m[1]);
        }

        $parts = preg_split('/\s+/', $scheduling);
        if (!is_array($parts) || count($parts) !== 5) {
            return false;
        }

        [$min, $hour, $dom, $mon, $dow] = $parts;

        if (!$this->cronFieldMatches($min, (int) $nowDt->format('i'), 0, 59)) {
            return false;
        }

        if (!$this->cronFieldMatches($hour, (int) $nowDt->format('G'), 0, 23)) {
            return false;
        }

        if (!$this->cronFieldMatches($mon, (int) $nowDt->format('n'), 1, 12)) {
            return false;
        }

        if (!$this->cronFieldMatches($dom, (int) $nowDt->format('j'), 1, 31)) {
            return false;
        }

        // cron dow: 0-7 (0 and 7 = Sunday). PHP format('w') is 0=Sunday.
        if (!$this->cronFieldMatches($dow, (int) $nowDt->format('w'), 0, 7)) {
            return false;
        }

        // Fire at most once per matching minute window
        if ($last) {
            $windowStart = $nowDt->setTime((int) $nowDt->format('G'), (int) $nowDt->format('i'), 0);
            if ($last >= $windowStart) {
                return false;
            }
        }

        return true;
    }

    /**
     * Rough next run timestamp (UTC string) for display; best-effort.
     */
    public function nextRunAt(
        string $scheduling,
        string $timezone = 'UTC',
        ?\DateTimeInterface $from = null,
    ): ?string {
        try {
            $tz = new \DateTimeZone($timezone ?: 'UTC');
        } catch (\Throwable) {
            $tz = new \DateTimeZone('UTC');
        }

        $cursor = $from
            ? \DateTimeImmutable::createFromInterface($from)->setTimezone($tz)
            : new \DateTimeImmutable('now', $tz);

        // Scan next 8 days by minute steps carefully: hour steps then minutes
        for ($i = 0; $i < 60 * 24 * 8; $i++) {
            $cursor = $cursor->modify('+1 minute');
            if ($this->isDue($scheduling, null, $timezone, $cursor)) {
                // isDue with null last always true when match — re-check fields only
                return $cursor->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }

        return null;
    }

    private function cronFieldMatches(string $field, int $value, int $min, int $max): bool
    {
        $field = trim($field);
        if ($field === '*') {
            return true;
        }

        foreach (explode(',', $field) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (str_contains($part, '/')) {
                [$range, $step] = explode('/', $part, 2);
                $step = max(1, (int) $step);
                if ($range === '*') {
                    if (($value - $min) % $step === 0) {
                        return true;
                    }
                    continue;
                }
            }

            if (str_contains($part, '-')) {
                [$a, $b] = explode('-', $part, 2);
                $a = (int) $a;
                $b = (int) $b;
                if ($value >= $a && $value <= $b) {
                    return true;
                }
                continue;
            }

            if ((int) $part === $value) {
                return true;
            }

            // Sunday 0 or 7
            if ($max === 7 && (int) $part === 7 && $value === 0) {
                return true;
            }
        }

        return false;
    }
}
