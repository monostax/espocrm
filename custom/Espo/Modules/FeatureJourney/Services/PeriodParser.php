<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use DateInterval;
use InvalidArgumentException;
use Throwable;

/**
 * Parses period strings like "3 days", "12 hours", "30 minutes" into DateInterval.
 *
 * Accepts English and pt-BR unit words (singular or plural, case-insensitive), plus
 * raw ISO-8601 durations ("PT30M", "P3D"). Storage stays canonical English — the UI
 * writes English tokens and only localises the label — but user-typed Portuguese is
 * accepted so a pt-BR operator typing "3 dias" does not silently break a timer.
 *
 * NOTE: the unit alias table below is mirrored in three other places. Keep in sync:
 *   - client/custom/modules/feature-journey/src/helpers/transition-rules.js
 *   - client/custom/modules/feature-journey/src/views/fields/period.js
 *   - Espo\Modules\FeatureAutomation\Services\MapMaterializer (time bucket subset)
 */
class PeriodParser
{
    /** Canonical unit => seconds. */
    private const UNIT_SECONDS = [
        'second' => 1,
        'minute' => 60,
        'hour' => 3600,
        'day' => 86400,
        'week' => 604800,
    ];

    /** Canonical unit => DateInterval spec template. */
    private const UNIT_SPEC = [
        'second' => 'PT%dS',
        'minute' => 'PT%dM',
        'hour' => 'PT%dH',
        'day' => 'P%dD',
        'week' => 'P%dW',
    ];

    /**
     * Accepted word => canonical unit. English + pt-BR, singular and plural.
     * Accent-insensitive matching is handled by normaliseUnit().
     */
    private const UNIT_ALIASES = [
        // English
        'second' => 'second', 'seconds' => 'second',
        'minute' => 'minute', 'minutes' => 'minute',
        'hour' => 'hour', 'hours' => 'hour',
        'day' => 'day', 'days' => 'day',
        'week' => 'week', 'weeks' => 'week',
        // pt-BR
        'segundo' => 'second', 'segundos' => 'second',
        'minuto' => 'minute', 'minutos' => 'minute',
        'hora' => 'hour', 'horas' => 'hour',
        'dia' => 'day', 'dias' => 'day',
        'semana' => 'week', 'semanas' => 'week',
    ];

    public function parse(string $period): DateInterval
    {
        $period = trim($period);

        if ($period === '') {
            throw new InvalidArgumentException('Empty period.');
        }

        // Raw ISO-8601 duration passthrough. Must look like a duration, not just start
        // with "P" — otherwise strings such as "pending" reached DateInterval and threw
        // a non-InvalidArgumentException that callers were not catching.
        if (preg_match('/^P(?=[\dT])[\dTWDHMSY.,]*$/i', $period)) {
            try {
                return new DateInterval(strtoupper($period));
            } catch (Throwable $e) {
                throw new InvalidArgumentException("Invalid ISO-8601 period '{$period}'.", 0, $e);
            }
        }

        [$n, $unit] = $this->split($period);

        return new DateInterval(sprintf(self::UNIT_SPEC[$unit], $n));
    }

    /**
     * Duration in seconds, or null when unparseable. Word forms ("3 days", "3 dias") are
     * exact. ISO-8601 inputs are resolved against a fixed reference instant, so a spec
     * carrying months or years is an approximation — fine for comparing two periods,
     * not for scheduling. Use parse() when you need calendar-correct arithmetic.
     */
    public function toSeconds(string $period): ?int
    {
        $period = trim($period);

        try {
            [$n, $unit] = $this->split($period);

            return $n * self::UNIT_SECONDS[$unit];
        } catch (Throwable) {
            // fall through to the ISO branch
        }

        try {
            $interval = $this->parse($period);
        } catch (Throwable) {
            return null;
        }

        $ref = new \DateTimeImmutable('@0');

        return $ref->add($interval)->getTimestamp();
    }

    /** True when $period is a value this parser understands. */
    public function isValid(string $period): bool
    {
        try {
            $this->parse($period);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Canonical English form ("3 dias" => "3 days"), for normalising user input before
     * it is persisted. Returns null when unparseable.
     */
    public function normalise(string $period): ?string
    {
        try {
            [$n, $unit] = $this->split(trim($period));
        } catch (Throwable) {
            return null;
        }

        return $n . ' ' . $unit . ($n === 1 ? '' : 's');
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

    /**
     * @return array{0: int, 1: string} amount and canonical unit
     */
    private function split(string $period): array
    {
        if (!preg_match('/^(\d+)\s*([\p{L}]+)$/u', $period, $m)) {
            throw new InvalidArgumentException("Invalid period '{$period}'.");
        }

        $unit = $this->normaliseUnit($m[2]);

        if ($unit === null) {
            throw new InvalidArgumentException("Invalid period '{$period}'. Unknown unit '{$m[2]}'.");
        }

        return [(int) $m[1], $unit];
    }

    /** Strip accents, lowercase, then resolve through the alias table. */
    private function normaliseUnit(string $word): ?string
    {
        // Accents are folded before strtolower() so this stays byte-safe and does not
        // depend on the mbstring extension.
        $word = strtr($word, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'Á' => 'a', 'À' => 'a', 'Ã' => 'a', 'Â' => 'a',
            'é' => 'e', 'ê' => 'e', 'É' => 'e', 'Ê' => 'e',
            'í' => 'i', 'Í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'Ó' => 'o', 'Ô' => 'o', 'Õ' => 'o',
            'ú' => 'u', 'Ú' => 'u',
            'ç' => 'c', 'Ç' => 'c',
        ]);

        return self::UNIT_ALIASES[strtolower($word)] ?? null;
    }
}
