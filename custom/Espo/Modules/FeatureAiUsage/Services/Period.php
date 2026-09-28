<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAiUsage\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Exceptions\BadRequest;

final class Period
{
    public function __construct(
        public readonly DateTimeImmutable $start,
        public readonly DateTimeImmutable $end,
        public readonly DateTimeImmutable $cutoff,
    ) {}

    public static function create(string $month, string $timezone, ?DateTimeImmutable $now = null): self
    {
        $tz = new DateTimeZone($timezone);
        $now = ($now ?? new DateTimeImmutable('now', $tz))->setTimezone($tz);
        $month = $month ?: $now->format('Y-m');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month) || $month < '2000-01' || $month > $now->format('Y-m')) {
            throw new BadRequest('Choose a valid current or previous month.');
        }
        $start = new DateTimeImmutable($month . '-01 00:00:00', $tz);
        $end = $start->modify('+1 month');
        return new self($start, $end, $end < $now ? $end : $now);
    }

    public function previous(): self
    {
        $start = $this->start->modify('-1 month');
        $end = $this->start;
        if ($this->cutoff >= $this->end) {
            return new self($start, $end, $end);
        }
        // Match elapsed local calendar days and time, capped at the shorter month's end.
        $cutoff = $start->modify('+' . ((int) $this->cutoff->format('j') - 1) . ' days')
            ->setTime((int) $this->cutoff->format('H'), (int) $this->cutoff->format('i'), (int) $this->cutoff->format('s'));
        return new self($start, $end, min($cutoff, $end));
    }

    public function utcStart(): string
    {
        return $this->start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function utcCutoff(): string
    {
        return $this->cutoff->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function day(string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($this->start->getTimezone())->format('Y-m-d');
    }

    public function days(): array
    {
        $days = [];
        for ($date = $this->start; $date < $this->cutoff; $date = $date->modify('+1 day')) {
            $days[] = $date->format('Y-m-d');
        }
        return $days;
    }

    public function toArray(): array
    {
        return [
            'month' => $this->start->format('Y-m'),
            'from' => $this->start->format('Y-m-d'),
            'through' => ($this->cutoff >= $this->end ? $this->end->modify('-1 day') : $this->cutoff)->format('Y-m-d'),
            'resetAt' => $this->end->format(DATE_ATOM),
            'asOf' => $this->cutoff->format(DATE_ATOM),
            'timeZone' => $this->start->getTimezone()->getName(),
            'isCurrent' => $this->cutoff < $this->end,
        ];
    }
}
