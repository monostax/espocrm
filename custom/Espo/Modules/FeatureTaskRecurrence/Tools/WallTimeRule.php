<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTaskRecurrence\Tools;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RRule\RRule;

/** RFC gap/overlap normalization: expand civil time, omit gaps, choose first overlap. */
class WallTimeRule extends RRule
{
    private DateTimeZone $wallZone;
    private ?int $validCount;
    private ?DateTimeInterface $instantUntil;

    public function __construct(RRule $rule, ?DateTimeInterface $from = null)
    {
        $parts = $rule->getRule();
        $this->wallZone = $parts['DTSTART']->getTimezone();
        $this->validCount = isset($parts['COUNT']) ? (int) $parts['COUNT'] : null;
        $this->instantUntil = $parts['UNTIL'] ?? null;
        $civilZone = new DateTimeZone('UTC');
        $parts['DTSTART'] = new DateTimeImmutable($parts['DTSTART']->format('Y-m-d H:i:s'), $civilZone);
        if ($this->instantUntil) {
            $local = DateTimeImmutable::createFromInterface($this->instantUntil)->setTimezone($this->wallZone);
            // A UTC UNTIL inside the second overlap can include later first-fold wall times.
            $parts['UNTIL'] = new DateTimeImmutable($local->format('Y-m-d') . ' 23:59:59', $civilZone);
        }
        unset($parts['COUNT']);
        if ($this->validCount === null && $from !== null) {
            $localFrom = DateTimeImmutable::createFromInterface($from)->setTimezone($this->wallZone);
            $civilFrom = new DateTimeImmutable($localFrom->format('Y-m-d H:i:s'), $civilZone);
            $parts = Seek::rule(new RRule($parts), $civilFrom)->getRule();
        }
        parent::__construct($parts);
    }

    public function getIterator(): \Generator
    {
        $count = 0;
        foreach (parent::getIterator() as $civil) {
            $instant = $this->instant($civil);
            if (!$instant) continue;
            if ($this->instantUntil && $instant > $this->instantUntil) return;
            if ($this->validCount !== null && $count >= $this->validCount) return;
            $count++;
            yield DateTime::createFromImmutable($instant);
        }
    }

    public function isInfinite(): bool { return $this->validCount === null && parent::isInfinite(); }

    public function occursAt($date): bool
    {
        $date = self::parseDate($date);
        return $this->getOccurrencesBetween($date, $date, 1) !== [];
    }

    private function instant(DateTimeInterface $civil): ?DateTimeImmutable
    {
        $stamp = $civil->getTimestamp();
        $transitions = $this->wallZone->getTransitions($stamp - 86400, $stamp + 86400);
        $offsets = $transitions === false ? [$this->wallZone->getOffset($civil)] : array_unique(array_column($transitions, 'offset'));
        $matches = [];
        foreach ($offsets as $offset) {
            $date = (new DateTimeImmutable('@' . ($stamp - $offset)))->setTimezone($this->wallZone);
            if ($date->format('Y-m-d H:i:s') === $civil->format('Y-m-d H:i:s')) $matches[] = $date;
        }
        sort($matches);
        return $matches[0] ?? null;
    }
}
