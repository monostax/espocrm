<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class UsageSummaryQuery
{
    public const MAX_RANGE_DAYS = 366;

    public string $from;
    public string $to;
    public int $tzOffsetMinutes;

    public function __construct(
        public string $tenantId,
        string $from,
        string $to,
        int $tzOffsetMinutes = 0,
    ) {
        GrantInput::identity($tenantId);
        if ($tzOffsetMinutes < -840 || $tzOffsetMinutes > 840) {
            throw new InvalidArgumentException('Invalid timezone offset.');
        }

        $utc = new DateTimeZone('UTC');
        $fromDt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $from, $utc);
        $toDt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $to, $utc);
        if (!$fromDt || $fromDt->format('Y-m-d H:i:s') !== $from ||
            !$toDt || $toDt->format('Y-m-d H:i:s') !== $to ||
            $fromDt >= $toDt) {
            throw new InvalidArgumentException('Invalid credit summary date range.');
        }

        $maxTo = $fromDt->modify('+' . self::MAX_RANGE_DAYS . ' days');
        if ($toDt > $maxTo) {
            throw new InvalidArgumentException('Credit summary date range exceeds maximum span.');
        }

        $this->from = $from;
        $this->to = $to;
        $this->tzOffsetMinutes = $tzOffsetMinutes;
    }
}
