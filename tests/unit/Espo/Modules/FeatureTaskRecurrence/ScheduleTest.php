<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureTaskRecurrence;

use DateTimeImmutable;
use Espo\Modules\FeatureTaskRecurrence\Tools\Schedule;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ScheduleTest extends TestCase
{
    private function calendar(string $rule, string $anchor = '2026-10-03'): object
    {
        return (new Schedule())->normalize((object) ['dateOnly' => true, 'anchor' => $anchor, 'schedule' => $rule, 'timezone' => 'America/Sao_Paulo']);
    }

    public static function patterns(): array
    {
        return [
            ['FREQ=DAILY;INTERVAL=2;COUNT=3', '2026-10-03', ['2026-10-03', '2026-10-05', '2026-10-07']],
            ['FREQ=WEEKLY;BYDAY=MO,WE,FR;COUNT=3', '2026-10-02', ['2026-10-02', '2026-10-05', '2026-10-07']],
            ['FREQ=MONTHLY;BYDAY=-1FR;COUNT=3', '2026-10-01', ['2026-10-30', '2026-11-27', '2026-12-25']],
            ['FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1;COUNT=3', '2026-10-01', ['2026-10-30', '2026-11-30', '2026-12-31']],
            ['FREQ=MONTHLY;BYMONTHDAY=31;COUNT=3', '2026-01-31', ['2026-01-31', '2026-03-31', '2026-05-31']],
            ['FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=29;COUNT=3', '2024-02-29', ['2024-02-29', '2028-02-29', '2032-02-29']],
            ['FREQ=DAILY;UNTIL=20261005', '2026-10-03', ['2026-10-03', '2026-10-04', '2026-10-05']],
            ['FREQ=DAILY;COUNT=1', '2026-10-03', ['2026-10-03']],
            ['FREQ=WEEKLY;INTERVAL=2;BYDAY=SU,TU;WKST=MO;COUNT=4', '2026-10-04', ['2026-10-04', '2026-10-13', '2026-10-18', '2026-10-27']],
        ];
    }

    #[DataProvider('patterns')]
    public function testCalendarPatterns(string $rule, string $anchor, array $dates): void
    {
        $schedule = new Schedule();
        $definition = $this->calendar($rule, $anchor);
        self::assertSame($dates, $schedule->preview($definition)->dates);
        self::assertSame($dates, $schedule->next($definition, null, 10));
        self::assertTrue($schedule->contains($dates[0], $definition));
        self::assertSame('D:' . $dates[0], $schedule->identity($dates[0], $definition));
        self::assertSame(array_slice($dates, 1), $schedule->next($definition, $dates[0], 10));
    }

    public function testSpecificDatesAreFiniteDeduplicatedAndDtstartIsNotImplicit(): void
    {
        $definition = $this->calendar("RDATE;VALUE=DATE:20261010,20261105,20261010\nEXDATE;VALUE=DATE:20261105");
        self::assertSame('2026-10-10', $definition->anchor);
        self::assertSame(['2026-10-10'], (new Schedule())->preview($definition)->dates);
        self::assertSame([], (new Schedule())->next($definition, '2026-10-10'));
    }

    public function testExclusionsDoNotExtendCountAndAdditionsAreIndependent(): void
    {
        $definition = $this->calendar("RRULE:FREQ=DAILY;COUNT=3\nEXDATE;VALUE=DATE:20261004\nRDATE;VALUE=DATE:20261101");
        self::assertSame(['2026-10-03', '2026-10-05', '2026-11-01'], (new Schedule())->preview($definition)->dates);
    }

    public function testAdvancedTextIsPreservedAndTimedUntilIsInclusive(): void
    {
        $text = "DTSTART;TZID=America/Sao_Paulo:20261003T090007\nRRULE:FREQ=DAILY;UNTIL=20261006T025959Z\nEXDATE;TZID=America/Sao_Paulo:20261004T090007";
        $schedule = new Schedule();
        $definition = $schedule->normalize((object) ['timezone' => 'America/Sao_Paulo', 'dateOnly' => false, 'anchor' => '2026-10-03 12:00:07', 'schedule' => $text]);
        self::assertSame($text, $definition->schedule);
        self::assertSame(['2026-10-03 12:00:07', '2026-10-05 12:00:07'], $schedule->preview($definition)->dates);
        self::assertSame('T:20261003T120007Z', $schedule->identity($definition->anchor, $definition));
    }

    public function testDstGapsAreOmittedWithoutConsumingCountAndOverlapUsesFirstInstant(): void
    {
        $schedule = new Schedule();
        $gap = $schedule->normalize((object) ['timezone' => 'America/New_York', 'dateOnly' => false, 'anchor' => '2026-03-01 07:30:00',
            'schedule' => "DTSTART;TZID=America/New_York:20260301T023000\nRRULE:FREQ=WEEKLY;COUNT=3"]);
        self::assertSame(['2026-03-01 07:30:00', '2026-03-15 06:30:00', '2026-03-22 06:30:00'], $schedule->preview($gap)->dates);
        $overlap = $schedule->normalize((object) ['timezone' => 'America/New_York', 'dateOnly' => false, 'anchor' => '2026-10-25 05:30:00',
            'schedule' => "DTSTART;TZID=America/New_York:20261025T013000\nRRULE:FREQ=WEEKLY;COUNT=3"]);
        self::assertSame(['2026-10-25 05:30:00', '2026-11-01 05:30:00', '2026-11-08 06:30:00'], $schedule->preview($overlap)->dates);
    }

    public static function completionIntervals(): array
    {
        return [
            ['week', 1, '2026-10-12 15:00:00', '2026-10-19'],
            ['month', 1, '2027-01-31 15:00:00', '2027-02-28'],
            ['month', 1, '2028-01-31 15:00:00', '2028-02-29'],
            ['year', 1, '2028-02-29 15:00:00', '2029-02-28'],
            ['day', 1, '2026-10-12 01:00:00', '2026-10-12'],
        ];
    }

    #[DataProvider('completionIntervals')]
    public function testCompletionUsesActualLocalDateAndClamps(string $unit, int $value, string $event, string $expected): void
    {
        $schedule = new Schedule();
        $definition = $schedule->normalize((object) ['basis' => 'CompletedDate', 'timezone' => 'America/Sao_Paulo', 'dateOnly' => true,
            'anchor' => '2026-10-03', 'interval' => (object) ['unit' => $unit, 'value' => $value]]);
        self::assertSame($expected, $schedule->completedDeadline($definition, new DateTimeImmutable($event . ' UTC')));
        self::assertTrue($schedule->preview($definition, $event)->hypothetical);
    }

    public function testTimedCompletionPreservesWallTimeAcrossDst(): void
    {
        $schedule = new Schedule();
        $definition = $schedule->normalize((object) ['basis' => 'CompletedDate', 'timezone' => 'America/New_York', 'dateOnly' => false,
            'anchor' => '2026-03-01 14:00:00', 'interval' => (object) ['unit' => 'week', 'value' => 1]]);
        self::assertSame('2026-03-12 13:00:00', $schedule->completedDeadline($definition, new DateTimeImmutable('2026-03-05 22:00:00 UTC')));
    }

    public static function invalidDefinitions(): array
    {
        return [
            ['RRULE:FREQ=DAILY;COUNT=2;UNTIL=20261004'],
            ['RRULE:FREQ=HOURLY'],
            ['RRULE:FREQ=DAILY;BYHOUR=9'],
            ['RDATE:20261003T090000Z'],
            ['RRULE:FREQ=MONTHLY;BYMONTH=2;BYMONTHDAY=31'],
            ['DTSTART;VALUE=DATE:20260230' . "\nRRULE:FREQ=DAILY"],
        ];
    }

    #[DataProvider('invalidDefinitions')]
    public function testInvalidOrIncompatibleSchedulesAreRejected(string $text): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calendar($text);
    }
}
