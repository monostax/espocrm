<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureJourney\Services;

use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureJourney\Services\BusinessDaySchedule;
use PHPUnit\Framework\TestCase;

class BusinessDayScheduleTest extends TestCase
{
    /** @dataProvider dates */
    public function testDueDates(string $base, int $offset, string $zone, string $expected): void
    {
        $this->assertSame($expected, (new BusinessDaySchedule())->dueDate($base, $offset, $zone));
    }

    public static function dates(): array
    {
        return [
            // Thursday enrollment: +2, then +3, then +3 weekdays.
            ['2026-10-01 15:00:00', 0, 'America/Sao_Paulo', '2026-10-01'],
            ['2026-10-01 15:00:00', 2, 'America/Sao_Paulo', '2026-10-05'],
            ['2026-10-01 15:00:00', 5, 'America/Sao_Paulo', '2026-10-08'],
            ['2026-10-01 15:00:00', 8, 'America/Sao_Paulo', '2026-10-13'],
            // Saturday UTC is still Friday in Brazil.
            ['2026-10-03 01:00:00', 0, 'America/Sao_Paulo', '2026-10-02'],
            ['2026-10-03 01:00:00', 2, 'America/Sao_Paulo', '2026-10-06'],
            // Weekend enrollment anchors D0 to Monday, then adds offsets.
            ['2026-10-03 15:00:00', 0, 'America/Sao_Paulo', '2026-10-05'],
            ['2026-10-04 15:00:00', 2, 'America/Sao_Paulo', '2026-10-07'],
            // DST and year boundaries use calendar dates, not fixed seconds.
            ['2026-03-06 15:00:00', 2, 'America/New_York', '2026-03-10'],
            ['2026-12-31 15:00:00', 2, 'America/Sao_Paulo', '2027-01-04'],
        ];
    }

    /** @dataProvider invalidOffsets */
    public function testRejectsInvalidOffsets(mixed $offset): void
    {
        $this->expectException(BadRequest::class);
        (new BusinessDaySchedule())->dueDate('2026-10-01 15:00:00', $offset, 'UTC');
    }

    public static function invalidOffsets(): array
    {
        return [[-1], [1.5], ['2 days'], [true], [3651], [null]];
    }
}
