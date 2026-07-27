<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureJourney\Services;

use Espo\Modules\FeatureJourney\Services\PeriodParser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PeriodParserTest extends TestCase
{
    private PeriodParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PeriodParser();
    }

    /**
     * The pt-BR UI localises unit labels, so operators type Portuguese. These must not
     * throw, otherwise timers and SLAs silently never fire.
     *
     * @dataProvider portugueseProvider
     */
    public function testAcceptsPortugueseUnits(string $input, int $expectedSeconds): void
    {
        $this->assertSame($expectedSeconds, $this->parser->toSeconds($input));
        $this->assertTrue($this->parser->isValid($input));
    }

    /** @return list<array{string, int}> */
    public static function portugueseProvider(): array
    {
        return [
            ['45 segundos', 45],
            ['1 segundo', 1],
            ['30 minutos', 1800],
            ['1 minuto', 60],
            ['12 horas', 43200],
            ['1 hora', 3600],
            ['3 dias', 259200],
            ['1 dia', 86400],
            ['2 semanas', 1209600],
            ['1 semana', 604800],
            ['1 SEMANA', 604800],
            ['7dias', 604800],
        ];
    }

    /** @dataProvider englishProvider */
    public function testAcceptsEnglishUnits(string $input, int $expectedSeconds): void
    {
        $this->assertSame($expectedSeconds, $this->parser->toSeconds($input));
    }

    /** @return list<array{string, int}> */
    public static function englishProvider(): array
    {
        return [
            ['30 seconds', 30],
            ['30 minutes', 1800],
            ['1 minute', 60],
            ['12 hours', 43200],
            ['3 days', 259200],
            ['1 day', 86400],
            ['2 weeks', 1209600],
            ['3 DAYS', 259200],
        ];
    }

    /** @dataProvider isoProvider */
    public function testAcceptsIso8601(string $input, int $expectedSeconds): void
    {
        $this->assertSame($expectedSeconds, $this->parser->toSeconds($input));
        $this->assertTrue($this->parser->isValid($input));
    }

    /** @return list<array{string, int}> */
    public static function isoProvider(): array
    {
        return [
            ['PT30M', 1800],
            ['PT2H', 7200],
            ['P3D', 259200],
            ['P1W', 604800],
        ];
    }

    /**
     * Portuguese input is normalised to canonical English before it is persisted, so the
     * job, the rule compiler and the client all read one format.
     *
     * @dataProvider normaliseProvider
     */
    public function testNormalisesToCanonicalEnglish(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->parser->normalise($input));
    }

    /** @return list<array{string, string}> */
    public static function normaliseProvider(): array
    {
        return [
            ['3 dias', '3 days'],
            ['1 dia', '1 day'],
            ['12 horas', '12 hours'],
            ['1 hora', '1 hour'],
            ['2 semanas', '2 weeks'],
            ['1 semana', '1 week'],
            ['30 minutos', '30 minutes'],
            ['3 days', '3 days'],
            ['1 hours', '1 hour'],
        ];
    }

    /** @dataProvider invalidProvider */
    public function testRejectsInvalidValues(string $input): void
    {
        $this->assertFalse($this->parser->isValid($input));
        $this->assertNull($this->parser->toSeconds($input));
        $this->assertNull($this->parser->normalise($input));
    }

    /**
     * Every rejection must surface as InvalidArgumentException. "pending" used to slip
     * past the ISO check into DateInterval and throw a different class, which callers
     * catching only InvalidArgumentException would have missed.
     *
     * @dataProvider invalidProvider
     */
    public function testRejectionUsesInvalidArgumentException(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->parser->parse($input);
    }

    /** @return list<array{string}> */
    public static function invalidProvider(): array
    {
        return [
            [''],
            ['   '],
            ['pending'],
            ['P3X'],
            ['3 meses'],
            ['3 months'],
            ['3 anos'],
            ['abc'],
            ['3'],
            ['1 day 2 hours'],
            ['-3 days'],
            ['1.5 hours'],
            ['1,5 horas'],
        ];
    }

    public function testIsDueUsesPortuguesePeriod(): void
    {
        $twoHoursAgo = gmdate('Y-m-d H:i:s', time() - 7200);

        $this->assertTrue($this->parser->isDue($twoHoursAgo, '1 hora'));
        $this->assertFalse($this->parser->isDue($twoHoursAgo, '3 dias'));
    }

    public function testAddToNowIsTimezoneStableAndAcceptsPortuguese(): void
    {
        $base = 1700000000;

        $this->assertSame(
            gmdate('Y-m-d H:i:s', $base + 7200),
            $this->parser->addToNow('2 horas', $base)
        );
        $this->assertSame(
            $this->parser->addToNow('2 hours', $base),
            $this->parser->addToNow('2 horas', $base)
        );
    }

    public function testParseReturnsUsableInterval(): void
    {
        $this->assertSame(30, $this->parser->parse('30 minutos')->i);
        $this->assertSame(3, $this->parser->parse('3 dias')->d);
    }
}
