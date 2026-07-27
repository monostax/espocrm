<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureAutomation\Services;

use Espo\Modules\FeatureAutomation\Services\WakeAtResolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class WakeAtResolverTest extends TestCase
{
    private WakeAtResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new WakeAtResolver();
    }

    public function testSqlDatetimeAssumesTimezone(): void
    {
        $sql = $this->resolver->toUtcSql('2026-08-01 12:00:00', 'UTC');
        $this->assertSame('2026-08-01 12:00:00', $sql);

        $minus3 = $this->resolver->toUtcSql('2026-08-01 12:00:00', 'America/Sao_Paulo');
        $this->assertSame('2026-08-01 15:00:00', $minus3);
    }

    public function testIsoOffsetIgnoresTimezoneArg(): void
    {
        $sql = $this->resolver->toUtcSql('2026-08-01T12:00:00-03:00', 'UTC');
        $this->assertSame('2026-08-01 15:00:00', $sql);
    }

    public function testDateOnlyIsMidnight(): void
    {
        $sql = $this->resolver->toUtcSql('2026-08-01', 'UTC');
        $this->assertSame('2026-08-01 00:00:00', $sql);
    }

    public function testUnixTimestamp(): void
    {
        $sql = $this->resolver->toUtcSql(1700000000);
        $this->assertSame('2023-11-14 22:13:20', $sql);
    }

    public function testInvalidThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->resolver->toUtcSql('not-a-date');
    }

    public function testIsValidFixed(): void
    {
        $this->assertTrue($this->resolver->isValidFixed('2026-01-01 00:00:00'));
        $this->assertFalse($this->resolver->isValidFixed('tomorrow'));
    }
}
