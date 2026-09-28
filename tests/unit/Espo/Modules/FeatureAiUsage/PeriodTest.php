<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureAiUsage;

use DateTimeImmutable;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureAiUsage\Services\Period;
use PHPUnit\Framework\TestCase;

class PeriodTest extends TestCase
{
    public function testHistoricalCalendarBoundariesHonorDst(): void
    {
        $period = Period::create('2026-03', 'America/New_York', new DateTimeImmutable('2026-09-27'));
        $this->assertSame('2026-03-01 05:00:00', $period->utcStart());
        $this->assertSame('2026-04-01 04:00:00', $period->utcCutoff());
        $this->assertSame('2026-03-08', $period->day('2026-03-09 03:59:59'));
        $this->assertSame('2026-03-09', $period->day('2026-03-09 04:00:00'));
    }

    public function testComparisonMatchesElapsedLocalDaysAndCapsAtMonthEnd(): void
    {
        $period = Period::create('2026-03', 'UTC', new DateTimeImmutable('2026-03-31T10:00:00Z'));
        $this->assertSame('2026-03-01 00:00:00', $period->previous()->utcCutoff());
        $period = Period::create('2026-09', 'America/Sao_Paulo', new DateTimeImmutable('2026-09-15T13:45:00Z'));
        $this->assertSame('2026-08-15 13:45:00', $period->previous()->utcCutoff());
    }

    public function testInvalidAndFutureMonthsAreRejected(): void
    {
        $this->expectException(BadRequest::class);
        Period::create('2026-13', 'UTC');
    }
}
