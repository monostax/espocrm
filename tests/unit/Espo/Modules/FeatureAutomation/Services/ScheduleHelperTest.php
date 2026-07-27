<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureAutomation\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Modules\FeatureAutomation\Services\ScheduleHelper;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ScheduleHelperTest extends TestCase
{
    private ScheduleHelper $helper;

    protected function setUp(): void
    {
        $this->helper = new ScheduleHelper();
    }

    public function testWeeklyRruleIsDueOnlyOncePerMinute(): void
    {
        $timezone = new DateTimeZone('America/Sao_Paulo');
        $start = new DateTimeImmutable('2026-06-30 08:00:00', $timezone);
        $now = new DateTimeImmutable('2026-07-01 09:30:45', $timezone);
        $rrule = 'FREQ=WEEKLY;BYDAY=WE;BYHOUR=9;BYMINUTE=30';

        $this->assertTrue($this->helper->isDue($rrule, null, 'America/Sao_Paulo', $now, $start));
        $this->assertFalse($this->helper->isDue(
            $rrule,
            '2026-07-01 12:30:15',
            'America/Sao_Paulo',
            $now,
            $start,
        ));
    }

    public function testMinuteIntervalsAndPhaseShiftsUseTheRuleDtStart(): void
    {
        $timezone = new DateTimeZone('UTC');
        $start = new DateTimeImmutable('2026-07-01 10:00:00', $timezone);

        $this->assertSame(
            '2026-07-01 10:45:00',
            $this->helper->nextRunAt('FREQ=MINUTELY;INTERVAL=45', 'UTC', $start, $start),
        );
        $this->assertTrue($this->helper->isDue(
            'FREQ=DAILY;INTERVAL=10',
            null,
            'UTC',
            new DateTimeImmutable('2026-07-11 10:00:00', $timezone),
            $start,
        ));
    }

    public function testRecurrenceSetSkipsExcludedDates(): void
    {
        $schedule = implode("\n", [
            'DTSTART;TZID=America/Sao_Paulo:20260701T093000',
            'RRULE:FREQ=DAILY;BYHOUR=9;BYMINUTE=30',
            'EXDATE;TZID=America/Sao_Paulo:20260702T093000',
        ]);

        $this->assertSame(
            '2026-07-03 12:30:00',
            $this->helper->nextRunAt(
                $schedule,
                'America/Sao_Paulo',
                new DateTimeImmutable('2026-07-01 09:30:00', new DateTimeZone('America/Sao_Paulo')),
            ),
        );
    }

    public function testCompoundRulesCanCoverSeparateCalendarWindows(): void
    {
        $schedule = implode("\n", [
            'DTSTART:20260101T090000Z',
            'RRULE:FREQ=YEARLY;BYMONTH=11;BYDAY=MO,WE;BYHOUR=9;BYMINUTE=0',
            'RRULE:FREQ=YEARLY;BYMONTH=12;BYDAY=SA,SU;BYHOUR=9;BYMINUTE=0',
        ]);

        $this->assertSame(
            '2026-12-05 09:00:00',
            $this->helper->nextRunAt(
                $schedule,
                'UTC',
                new DateTimeImmutable('2026-11-30 10:00:00', new DateTimeZone('UTC')),
            ),
        );
    }

    public function testPositionalRulesCanSelectTheLastWeekdayOfTheMonth(): void
    {
        $schedule = implode("\n", [
            'DTSTART:20260101T090000Z',
            'RRULE:FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1;BYHOUR=9;BYMINUTE=0',
        ]);

        $this->assertTrue($this->helper->isDue(
            $schedule,
            null,
            'UTC',
            new DateTimeImmutable('2026-01-30 09:00:00', new DateTimeZone('UTC')),
        ));

        $fifthSaturday = "DTSTART:20260101T090000Z\nRRULE:FREQ=MONTHLY;BYDAY=5SA;BYHOUR=9;BYMINUTE=0";
        $this->assertTrue($this->helper->isDue(
            $fifthSaturday,
            null,
            'UTC',
            new DateTimeImmutable('2026-05-30 09:00:00', new DateTimeZone('UTC')),
        ));
    }

    public function testSecondRulesUsePersistedNextOccurrence(): void
    {
        $schedule = "DTSTART:20260701T100000Z\nRRULE:FREQ=SECONDLY;INTERVAL=45";
        $timezone = new DateTimeZone('UTC');

        $this->assertSame(
            '2026-07-01 10:00:45',
            $this->helper->nextRunAt(
                $schedule,
                'UTC',
                new DateTimeImmutable('2026-07-01 10:00:00', $timezone),
            ),
        );
        $this->assertTrue($this->helper->isDue(
            $schedule,
            null,
            'UTC',
            new DateTimeImmutable('2026-07-01 10:00:50', $timezone),
            null,
            '2026-07-01 10:00:45',
        ));
    }

    public function testCronExpressionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->helper->validate('0 19 * * *');
    }
}
