<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureAutomation\Services;

use Espo\Core\Exceptions\Error;
use Espo\Modules\FeatureAutomation\Services\PayloadBag;
use Espo\Modules\FeatureAutomation\Services\RunDataBag;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class RunDataBagTest extends TestCase
{
    private RunDataBag $bag;

    protected function setUp(): void
    {
        $em = $this->createMock(EntityManager::class);
        $this->bag = new RunDataBag($em, new PayloadBag());
    }

    public function testPickPublicSkipsReserved(): void
    {
        $slice = $this->bag->pickPublic([
            'report' => ['totals' => 1],
            '_trigger' => ['x' => 1],
            '_run' => [],
        ], true);

        $this->assertSame(['report' => ['totals' => 1]], $slice);
    }

    public function testPickPublicFiltersKeys(): void
    {
        $slice = $this->bag->pickPublic([
            'report' => 1,
            'conversations' => 2,
            'other' => 3,
        ], ['report', 'other']);

        $this->assertSame(['report' => 1, 'other' => 3], $slice);
    }

    public function testSeedPayloadFlattensAndSnapshots(): void
    {
        $payload = $this->bag->seedPayload(
            ['t' => ['id' => '1']],
            ['report' => ['totals' => ['COUNT:id' => 5]], 'conversations' => ['totals' => 2]],
            true,
            'root',
            false,
        );

        $this->assertSame(['id' => '1'], $payload['t']);
        $this->assertSame(5, $payload['report']['totals']['COUNT:id']);
        $this->assertSame(2, $payload['conversations']['totals']);
        $this->assertArrayHasKey('_runBag', $payload);
        $this->assertSame(5, $payload['_runBag']['report']['totals']['COUNT:id']);
    }

    public function testSeedPayloadDoesNotOverwriteMapKeys(): void
    {
        $payload = $this->bag->seedPayload(
            ['report' => 'local'],
            ['report' => 'fromBag'],
            true,
            'root',
            false,
        );

        $this->assertSame('local', $payload['report']);
    }

    public function testSeedPayloadOverwrite(): void
    {
        $payload = $this->bag->seedPayload(
            ['report' => 'local'],
            ['report' => 'fromBag'],
            true,
            'root',
            true,
        );

        $this->assertSame('fromBag', $payload['report']);
    }

    public function testNormalizeExportConfig(): void
    {
        $this->assertNull($this->bag->normalizeExportConfig(false));
        $cfg = $this->bag->normalizeExportConfig(true);
        $this->assertNotNull($cfg);
        $this->assertTrue($cfg['keys']);
        $this->assertSame('lastDone', $cfg['from']);

        $cfg2 = $this->bag->normalizeExportConfig('report, conversations');
        $this->assertSame(['report', 'conversations'], $cfg2['keys']);
    }

    public function testNormalizeImportConfig(): void
    {
        $cfg = $this->bag->normalizeImportConfig(['keys' => ['report'], 'into' => 'bag']);
        $this->assertNotNull($cfg);
        $this->assertSame(['report'], $cfg['keys']);
        $this->assertSame('runBag', $cfg['into']);
    }

    public function testExportMergeOnEntity(): void
    {
        $run = $this->createMock(Entity::class);
        $store = ['dataBag' => ['a' => 1]];
        $run->method('get')->willReturnCallback(function ($f) use (&$store) {
            return $store[$f] ?? null;
        });
        $run->method('set')->willReturnCallback(function ($f, $v = null) use (&$store, $run) {
            if (is_array($f)) {
                foreach ($f as $k => $val) {
                    $store[$k] = $val;
                }
            } else {
                $store[$f] = $v;
            }

            return $run;
        });
        $run->method('hasId')->willReturn(true);
        $run->method('getId')->willReturn('sim_run1');

        $next = $this->bag->exportFromPayload(
            $run,
            ['a' => 9, 'b' => 2, '_x' => 1],
            true,
            'merge',
            false,
        );

        $this->assertSame(['a' => 9, 'b' => 2], $next);
    }

    public function testRejectsInvalidKey(): void
    {
        $this->expectException(Error::class);
        $this->bag->normalizeExportConfig(['bad-key!']);
    }
}
