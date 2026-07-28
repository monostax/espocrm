<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureJourney\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Formula\Manager as FormulaManager;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\Modules\FeatureJourney\Services\ActionRunner;
use Espo\Modules\FeatureJourney\Services\ActionConditionEvaluator;
use Espo\Modules\FeatureJourney\Services\JourneyRateLimiter;
use Espo\Modules\FeatureJourney\Services\RestrictedFormulaRunner;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Focus: paramFormulas evaluation + tenantId strip behaviour via resolveParamFormulas.
 */
class ActionRunnerParamFormulasTest extends TestCase
{
    public function testResolveParamFormulasMergesAndStripsMapKey(): void
    {
        $formulaManager = $this->createMock(FormulaManager::class);
        $formulaManager->method('run')->willReturnCallback(
            function (string $script) {
                if (str_contains($script, 'assignedUserId')) {
                    return 'user-42';
                }

                return 'Follow-up';
            }
        );

        $runner = new ActionRunner(
            $this->createMock(EntityManager::class),
            $this->createMock(Metadata::class),
            $this->createMock(InjectableFactory::class),
            $this->createMock(JourneyRateLimiter::class),
            $this->createMock(TenantGuard::class),
            new RestrictedFormulaRunner($formulaManager),
            $this->createMock(ActionConditionEvaluator::class),
            $this->createMock(Log::class),
        );

        $target = $this->createMock(Entity::class);
        $record = $this->createMock(Entity::class);
        $record->method('getId')->willReturn('rec-1');
        $stage = $this->createMock(Entity::class);
        $stage->method('getId')->willReturn('stg-1');
        $journey = $this->createMock(Entity::class);
        $journey->method('getId')->willReturn('jrn-1');

        $params = [
            'name' => 'static',
            'paramFormulas' => [
                'assignedUserId' => 'entity\\attribute("assignedUserId")',
                'name' => 'string\\concatenate("Follow", "-up")',
            ],
        ];

        $resolved = $runner->resolveParamFormulas(
            $params,
            $target,
            $record,
            $stage,
            $journey,
            'tenant-1',
        );

        $this->assertArrayNotHasKey('paramFormulas', $resolved);
        $this->assertSame('user-42', $resolved['assignedUserId']);
        $this->assertSame('Follow-up', $resolved['name']);
    }

    public function testResolveParamFormulasIgnoresTenantIdKey(): void
    {
        $formulaManager = $this->createMock(FormulaManager::class);
        $formulaManager->expects($this->never())->method('run');

        $runner = new ActionRunner(
            $this->createMock(EntityManager::class),
            $this->createMock(Metadata::class),
            $this->createMock(InjectableFactory::class),
            $this->createMock(JourneyRateLimiter::class),
            $this->createMock(TenantGuard::class),
            new RestrictedFormulaRunner($formulaManager),
            $this->createMock(ActionConditionEvaluator::class),
            $this->createMock(Log::class),
        );

        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn('x');

        $resolved = $runner->resolveParamFormulas(
            [
                'paramFormulas' => (object) [
                    'tenantId' => 'entity\\attribute("tenantId")',
                ],
            ],
            $entity,
            $entity,
            $entity,
            $entity,
            'tenant-real',
        );

        $this->assertArrayNotHasKey('tenantId', $resolved);
        $this->assertArrayNotHasKey('paramFormulas', $resolved);
    }

    public function testResolveParamFormulasBlocksMutatingScript(): void
    {
        $formulaManager = $this->createMock(FormulaManager::class);
        $formulaManager->expects($this->never())->method('run');

        $runner = new ActionRunner(
            $this->createMock(EntityManager::class),
            $this->createMock(Metadata::class),
            $this->createMock(InjectableFactory::class),
            $this->createMock(JourneyRateLimiter::class),
            $this->createMock(TenantGuard::class),
            new RestrictedFormulaRunner($formulaManager),
            $this->createMock(ActionConditionEvaluator::class),
            $this->createMock(Log::class),
        );

        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn('x');

        $this->expectException(Error::class);

        $runner->resolveParamFormulas(
            [
                'paramFormulas' => [
                    'x' => 'entity\\setAttribute("status", "Hacked")',
                ],
            ],
            $entity,
            $entity,
            $entity,
            $entity,
            't1',
        );
    }

    public function testResolveParamFormulasBlocksJourneyInConditionMode(): void
    {
        $formulaManager = $this->createMock(FormulaManager::class);
        $formulaManager->expects($this->never())->method('run');

        $runner = new ActionRunner(
            $this->createMock(EntityManager::class),
            $this->createMock(Metadata::class),
            $this->createMock(InjectableFactory::class),
            $this->createMock(JourneyRateLimiter::class),
            $this->createMock(TenantGuard::class),
            new RestrictedFormulaRunner($formulaManager),
            $this->createMock(ActionConditionEvaluator::class),
            $this->createMock(Log::class),
        );

        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn('x');

        $this->expectException(Error::class);

        $runner->resolveParamFormulas(
            [
                'paramFormulas' => [
                    'x' => 'journey\\signal("c", "Contact", "id")',
                ],
            ],
            $entity,
            $entity,
            $entity,
            $entity,
            't1',
        );
    }

    /**
     * jsonObject params arrive as nested stdClass; assigning fields.* must not wipe static fields.
     */
    public function testResolveParamFormulasPreservesNestedStdClassFields(): void
    {
        $formulaManager = $this->createMock(FormulaManager::class);
        $formulaManager->method('run')->willReturnCallback(
            function (string $script) {
                if (str_contains($script, 'accountId')) {
                    return 'acc-1';
                }

                return 'Lead — Cold Outreach';
            }
        );

        $runner = new ActionRunner(
            $this->createMock(EntityManager::class),
            $this->createMock(Metadata::class),
            $this->createMock(InjectableFactory::class),
            $this->createMock(JourneyRateLimiter::class),
            $this->createMock(TenantGuard::class),
            new RestrictedFormulaRunner($formulaManager),
            $this->createMock(ActionConditionEvaluator::class),
            $this->createMock(Log::class),
        );

        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn('x');

        // Simulate normalizeParams depth-cast of DB jsonObject.
        $ref = new \ReflectionClass($runner);
        $normalize = $ref->getMethod('normalizeParams');
        $normalize->setAccessible(true);
        $params = $normalize->invoke($runner, (object) [
            'link' => 'opportunitiesPrimary',
            'fields' => (object) [
                'funnelId' => 'funnel-1',
                'opportunityStageId' => 'stage-1',
                'amount' => 0,
            ],
            'paramFormulas' => (object) [
                'fields.name' => 'string\\concatenate("Lead", " — Cold Outreach")',
                'fields.accountId' => 'entity\\attribute("accountId")',
            ],
        ]);

        $resolved = $runner->resolveParamFormulas(
            $params,
            $entity,
            $entity,
            $entity,
            $entity,
            'tenant-1',
        );

        $this->assertSame('opportunitiesPrimary', $resolved['link']);
        $this->assertIsArray($resolved['fields']);
        $this->assertSame('funnel-1', $resolved['fields']['funnelId']);
        $this->assertSame('stage-1', $resolved['fields']['opportunityStageId']);
        $this->assertSame(0, $resolved['fields']['amount']);
        $this->assertSame('Lead — Cold Outreach', $resolved['fields']['name']);
        $this->assertSame('acc-1', $resolved['fields']['accountId']);
    }
}
