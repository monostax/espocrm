<?php

namespace tests\unit\Espo\Modules\FeatureAutomation\Services;

use Espo\Core\Utils\Metadata;
use Espo\Modules\FeatureAutomation\Services\AutomationDefinitionValidator;
use Espo\Modules\FeatureAutomation\Services\PayloadBag;
use Espo\Modules\FeatureAutomation\Services\RunDataBag;
use Espo\Modules\FeatureJourney\Services\PeriodParser;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Stages expand legacy map+actions; forEach/once validation reshape.
 */
class BatchStagesNormalizeTest extends TestCase
{
    private function validator(): AutomationDefinitionValidator
    {
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturnCallback(function ($path) {
            if ($path === ['app', 'automationActionTypes', 'typeList']) {
                return ['notifyUser', 'setPayload', 'assign'];
            }

            return null;
        });

        $period = $this->createMock(PeriodParser::class);
        $period->method('parse')->willReturn(new \DateInterval('PT1H'));

        $em = $this->createMock(EntityManager::class);
        $runBag = new RunDataBag($em, new PayloadBag());
        $wake = $this->createMock(
            \Espo\Modules\FeatureAutomation\Services\WakeAtResolver::class
        );

        return new AutomationDefinitionValidator($metadata, $period, $runBag, $wake, $em);
    }

    /**
     * @param array<string, mixed> $def
     * @return array<string, mixed>
     */
    private function validateBatch(array $def): array
    {
        $v = $this->validator();
        $ref = new ReflectionClass($v);
        $m = $ref->getMethod('validateBatch');
        $m->setAccessible(true);

        return $m->invoke($v, $def);
    }

    public function testLegacyMapExpandsToSingleForEachStage(): void
    {
        $out = $this->validateBatch([
            'kind' => 'batch',
            'map' => [
                ['id' => 't', 'mode' => 'primary', 'source' => 'query', 'entityType' => 'Tenant', 'where' => []],
            ],
            'actions' => [
                ['type' => 'notifyUser', 'params' => ['message' => 'hi']],
            ],
            'onFailure' => [],
            'itemMode' => 'allMatching',
        ]);

        $this->assertSame('batch', $out['kind']);
        $this->assertCount(1, $out['stages']);
        $this->assertSame('forEach', $out['stages'][0]['scope']);
        $this->assertSame('s0', $out['stages'][0]['id']);
        $this->assertNotEmpty($out['stages'][0]['map']);
        $this->assertCount(1, $out['stages'][0]['actions']);
        $this->assertSame($out['stages'][0]['map'], $out['map']);
        $this->assertSame($out['stages'][0]['actions'], $out['actions']);
    }

    public function testForEachThenOncePipeline(): void
    {
        $out = $this->validateBatch([
            'kind' => 'batch',
            'stages' => [
                [
                    'id' => 'wave',
                    'scope' => 'forEach',
                    'map' => [
                        ['id' => 't', 'mode' => 'primary', 'source' => 'query', 'entityType' => 'Tenant'],
                    ],
                    'actions' => [
                        ['type' => 'notifyUser', 'params' => ['message' => 'item']],
                    ],
                ],
                [
                    'id' => 'done',
                    'scope' => 'once',
                    'actions' => [
                        ['type' => 'notifyUser', 'params' => ['message' => 'all done']],
                    ],
                ],
            ],
        ]);

        $this->assertCount(2, $out['stages']);
        $this->assertSame('forEach', $out['stages'][0]['scope']);
        $this->assertSame('once', $out['stages'][1]['scope']);
        $this->assertSame([], $out['stages'][1]['map']);
        $this->assertSame('wave', $out['stages'][0]['id']);
        $this->assertSame('t', $out['map'][0]['id']);
        $this->assertNotEmpty($out['map']);
    }

    public function testOnceOnlyStageAllowed(): void
    {
        $out = $this->validateBatch([
            'kind' => 'batch',
            'stages' => [
                [
                    'id' => 'only',
                    'scope' => 'once',
                    'actions' => [
                        ['type' => 'notifyUser', 'params' => ['message' => 'ping']],
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $out['stages']);
        $this->assertSame('once', $out['stages'][0]['scope']);
    }

    public function testRunBagStageFlagsNormalized(): void
    {
        $out = $this->validateBatch([
            'kind' => 'batch',
            'stages' => [
                [
                    'id' => 'compute',
                    'scope' => 'once',
                    'exportToRunBag' => ['report', 'conversations'],
                    'actions' => [
                        ['type' => 'notifyUser', 'params' => ['message' => 'x']],
                    ],
                ],
                [
                    'id' => 'send',
                    'scope' => 'forEach',
                    'importRunBag' => true,
                    'map' => [
                        ['id' => 't', 'mode' => 'primary', 'source' => 'query', 'entityType' => 'Tenant'],
                    ],
                    'actions' => [
                        ['type' => 'notifyUser', 'params' => ['message' => 'y']],
                    ],
                ],
            ],
        ]);

        $this->assertIsArray($out['stages'][0]['exportToRunBag']);
        $this->assertSame(['report', 'conversations'], $out['stages'][0]['exportToRunBag']['keys']);
        $this->assertSame('lastDone', $out['stages'][0]['exportToRunBag']['from']);
        $this->assertIsArray($out['stages'][1]['importRunBag']);
        $this->assertTrue($out['stages'][1]['importRunBag']['keys']);
        $this->assertSame('root', $out['stages'][1]['importRunBag']['into']);
        $this->assertFalse($out['stages'][0]['importRunBag']);
        $this->assertFalse($out['stages'][1]['exportToRunBag']);
    }
}
