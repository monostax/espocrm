<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureAutomation\Services;

use Espo\Core\Exceptions\Error;
use Espo\Modules\FeatureAutomation\Services\PayloadBag;
use Espo\Modules\FeatureAutomation\Services\ReportPayloadBuilder;
use PHPUnit\Framework\TestCase;

class PayloadBagTest extends TestCase
{
    private PayloadBag $bag;

    protected function setUp(): void
    {
        $this->bag = new PayloadBag();
    }

    public function testSetAndGetNestedPath(): void
    {
        $root = [];
        $root = $this->bag->setByPath($root, 'vars.digest', 'hello');
        $root = $this->bag->setByPath($root, 'report.totals.count', 3);

        $this->assertSame('hello', $this->bag->getByPath($root, 'vars.digest'));
        $this->assertSame(3, $this->bag->getByPath($root, 'report.totals.count'));
        $this->assertNull($this->bag->getByPath($root, 'missing.key'));
    }

    public function testMergeObjects(): void
    {
        $root = $this->bag->setByPath([], 'vars', ['a' => 1, 'b' => 2]);
        $root = $this->bag->setByPath($root, 'vars', ['b' => 9, 'c' => 3], true);

        $this->assertSame(['a' => 1, 'b' => 9, 'c' => 3], $this->bag->getByPath($root, 'vars'));
    }

    public function testRejectsReservedRoots(): void
    {
        $this->expectException(Error::class);
        $this->bag->setByPath([], '_pendingJoins.x', 1);
    }

    public function testRejectsInvalidPath(): void
    {
        $this->expectException(Error::class);
        $this->bag->setByPath([], 'bad-path!', 1);
    }

    public function testNormalizeStdClass(): void
    {
        $obj = (object) ['vars' => (object) ['x' => 1]];
        $arr = $this->bag->normalize($obj);
        $this->assertSame(1, $arr['vars']['x']);
    }
}

class ReportPayloadBuilderPeriodTest extends TestCase
{
    public function testCurrentWeekIsMondayToMonday(): void
    {
        $builder = new ReportPayloadBuilder(
            $this->createMock(\Espo\ORM\EntityManager::class),
            $this->createMock(\Espo\Core\InjectableFactory::class),
        );

        // 2026-07-26 is Sunday
        $meta = $builder->resolvePeriod('currentWeek', 'UTC');
        $this->assertNotNull($meta);
        $this->assertSame('currentWeek', $meta['label']);

        $start = new \DateTimeImmutable($meta['start'], new \DateTimeZone('UTC'));
        $end = new \DateTimeImmutable($meta['end'], new \DateTimeZone('UTC'));

        $this->assertSame('1', $start->format('N')); // Monday
        $this->assertSame(7 * 24 * 3600, $end->getTimestamp() - $start->getTimestamp());
    }

    public function testNonePeriodReturnsNull(): void
    {
        $builder = new ReportPayloadBuilder(
            $this->createMock(\Espo\ORM\EntityManager::class),
            $this->createMock(\Espo\Core\InjectableFactory::class),
        );

        $this->assertNull($builder->resolvePeriod('none', 'UTC'));
    }
}
