<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureJourney\Services;

use Espo\Core\Formula\Manager as FormulaManager;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\Modules\FeatureJourney\Classes\JourneyActions\Action;
use Espo\Modules\FeatureJourney\Services\ActionConditionEvaluator;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\ActionRunner;
use Espo\Modules\FeatureJourney\Services\JourneyRateLimiter;
use Espo\Modules\FeatureJourney\Services\RestrictedFormulaRunner;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ActionRunnerConditionFormulaTest extends TestCase
{
    public function testFalseConditionSkipsAction(): void
    {
        $formulaManager = $this->createMock(FormulaManager::class);
        $formulaManager->expects($this->once())->method('run')->willReturn(false);
        $factory = $this->createMock(InjectableFactory::class);
        $factory->expects($this->never())->method('create');

        $result = $this->runActions(
            [
                $this->action([
                    'conditionFormula' => 'util\\empty(entity\\attribute("emailAddress"))',
                ]),
            ],
            $formulaManager,
            $factory,
        );

        $this->assertSame(['ok' => true, 'skipped' => 1, 'failed' => []], $result);
    }

    public function testTrueConditionRunsAction(): void
    {
        $formulaManager = $this->createMock(FormulaManager::class);
        $formulaManager->expects($this->once())->method('run')->willReturn(true);
        $implementation = $this->createMock(Action::class);
        $implementation->expects($this->once())->method('run');
        $factory = $this->createMock(InjectableFactory::class);
        $factory->expects($this->once())->method('create')->willReturn($implementation);

        $result = $this->runActions(
            [
                $this->action([
                    'conditionFormula' => '!util\\empty(entity\\attribute("emailAddress"))',
                ]),
            ],
            $formulaManager,
            $factory,
        );

        $this->assertSame(['ok' => true, 'skipped' => 0, 'failed' => []], $result);
    }

    public function testEmptyConditionRunsWithoutFormulaEvaluation(): void
    {
        $formulaManager = $this->createMock(FormulaManager::class);
        $formulaManager->expects($this->never())->method('run');
        $implementation = $this->createMock(Action::class);
        $implementation->expects($this->once())->method('run');
        $factory = $this->createMock(InjectableFactory::class);
        $factory->expects($this->once())->method('create')->willReturn($implementation);

        $result = $this->runActions(
            [$this->action(['conditionFormula' => '   '])],
            $formulaManager,
            $factory,
        );

        $this->assertSame(['ok' => true, 'skipped' => 0, 'failed' => []], $result);
    }

    public function testUnsafeConditionFailsAction(): void
    {
        $formulaManager = $this->createMock(FormulaManager::class);
        $formulaManager->expects($this->never())->method('run');
        $factory = $this->createMock(InjectableFactory::class);
        $factory->expects($this->never())->method('create');

        $result = $this->runActions(
            [
                $this->action([
                    'conditionFormula' => 'entity\\setAttribute("status", "Hacked")',
                ]),
            ],
            $formulaManager,
            $factory,
        );

        $this->assertFalse($result['ok']);
        $this->assertStringStartsWith('conditionFormula: ', $result['error'] ?? '');
        $this->assertCount(1, $result['failed'] ?? []);
        $this->assertSame(0, $result['skipped'] ?? null);
    }

    public function testConditionErrorHonorsContinueOnError(): void
    {
        $formulaManager = $this->createMock(FormulaManager::class);
        $formulaManager->expects($this->never())->method('run');
        $implementation = $this->createMock(Action::class);
        $implementation->expects($this->once())->method('run');
        $factory = $this->createMock(InjectableFactory::class);
        $factory->expects($this->once())->method('create')->willReturn($implementation);

        $result = $this->runActions(
            [
                $this->action([
                    'conditionFormula' => 'journey\\signal("test", "Contact", "id")',
                    'continueOnError' => true,
                ]),
                $this->action(),
            ],
            $formulaManager,
            $factory,
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['skipped'] ?? null);
        $this->assertCount(1, $result['failed'] ?? []);
    }

    public function testMatchingVisualConditionsRunAction(): void
    {
        $formulaManager = $this->createMock(FormulaManager::class);
        $formulaManager->expects($this->never())->method('run');
        $conditions = $this->createMock(ActionConditionEvaluator::class);
        $conditions->expects($this->once())->method('isEmpty')->willReturn(false);
        $conditions->expects($this->once())->method('matches')->willReturn(true);
        $implementation = $this->createMock(Action::class);
        $implementation->expects($this->once())->method('run');
        $factory = $this->createMock(InjectableFactory::class);
        $factory->method('create')->willReturn($implementation);

        $result = $this->runActions(
            [$this->action(['conditionsGroup' => ['type' => 'and', 'value' => []]])],
            $formulaManager,
            $factory,
            $conditions,
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['skipped'] ?? null);
    }

    public function testNonMatchingVisualConditionsSkipAction(): void
    {
        $formulaManager = $this->createMock(FormulaManager::class);
        $formulaManager->expects($this->never())->method('run');
        $conditions = $this->createMock(ActionConditionEvaluator::class);
        $conditions->expects($this->once())->method('isEmpty')->willReturn(false);
        $conditions->expects($this->once())->method('matches')->willReturn(false);
        $factory = $this->createMock(InjectableFactory::class);
        $factory->expects($this->never())->method('create');

        $result = $this->runActions(
            [$this->action(['conditionsGroup' => ['type' => 'or', 'value' => []]])],
            $formulaManager,
            $factory,
            $conditions,
        );

        $this->assertSame(['ok' => true, 'skipped' => 1, 'failed' => []], $result);
    }

    public function testFormulaOverridesVisualConditions(): void
    {
        $formulaManager = $this->createMock(FormulaManager::class);
        $formulaManager->expects($this->once())->method('run')->willReturn(true);
        $conditions = $this->createMock(ActionConditionEvaluator::class);
        $conditions->expects($this->never())->method('isEmpty');
        $conditions->expects($this->never())->method('matches');
        $implementation = $this->createMock(Action::class);
        $implementation->expects($this->once())->method('run');
        $factory = $this->createMock(InjectableFactory::class);
        $factory->method('create')->willReturn($implementation);

        $result = $this->runActions(
            [$this->action([
                'conditionFormula' => 'true',
                'conditionsGroup' => ['type' => 'or', 'value' => []],
            ])],
            $formulaManager,
            $factory,
            $conditions,
        );

        $this->assertTrue($result['ok']);
    }

    public function testVisualConditionErrorHonorsContinueOnError(): void
    {
        $formulaManager = $this->createMock(FormulaManager::class);
        $formulaManager->expects($this->never())->method('run');
        $conditions = $this->createMock(ActionConditionEvaluator::class);
        $conditions->expects($this->exactly(2))->method('isEmpty')->willReturnOnConsecutiveCalls(
            false,
            true,
        );
        $conditions->expects($this->once())->method('matches')->willThrowException(
            new \RuntimeException('invalid dynamic value'),
        );
        $implementation = $this->createMock(Action::class);
        $implementation->expects($this->once())->method('run');
        $factory = $this->createMock(InjectableFactory::class);
        $factory->method('create')->willReturn($implementation);

        $result = $this->runActions(
            [
                $this->action([
                    'conditionsGroup' => ['type' => 'and', 'value' => []],
                    'continueOnError' => true,
                ]),
                $this->action(),
            ],
            $formulaManager,
            $factory,
            $conditions,
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['skipped'] ?? null);
        $this->assertCount(1, $result['failed'] ?? []);
    }

    /**
     * @param list<Entity> $actions
     * @return array{ok: bool, error?: string, skipped?: int, failed?: list<string>}
     */
    private function runActions(
        array $actions,
        FormulaManager $formulaManager,
        InjectableFactory $factory,
        ?ActionConditionEvaluator $conditionEvaluator = null,
    ): array {
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturn([
            'implementationClassName' => ActionRunnerConditionFormulaImplementation::class,
        ]);

        $tenantGuard = $this->createMock(TenantGuard::class);
        $tenantGuard->method('assertTenantScope')->willReturn('tenant-1');
        if ($conditionEvaluator === null) {
            $conditionEvaluator = $this->createMock(ActionConditionEvaluator::class);
            $conditionEvaluator->method('isEmpty')->willReturn(true);
        }

        $runner = new ActionRunner(
            $this->createMock(EntityManager::class),
            $metadata,
            $factory,
            $this->createMock(JourneyRateLimiter::class),
            $tenantGuard,
            new RestrictedFormulaRunner($formulaManager),
            $conditionEvaluator,
            $this->createMock(Log::class),
        );

        $target = $this->createMock(Entity::class);
        $record = $this->entityWithId('record-1');
        $stage = $this->entityWithId('stage-1');
        $journey = $this->entityWithId('journey-1');

        return $runner->runActionsList(
            $actions,
            $target,
            $record,
            $stage,
            $journey,
            'OnEnter',
            'tenant-1',
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    private function action(array $values = []): Entity&MockObject
    {
        $values += [
            'isActive' => true,
            'type' => 'test',
            'conditionFormula' => null,
            'conditionsGroup' => null,
            'maxRetries' => 0,
            'continueOnError' => false,
            'params' => [],
            'formula' => null,
        ];

        $action = $this->createMock(Entity::class);
        $action->method('get')->willReturnCallback(
            static fn (string $field): mixed => $values[$field] ?? null,
        );
        $action->method('getId')->willReturn('action-' . spl_object_id($action));

        return $action;
    }

    private function entityWithId(string $id): Entity&MockObject
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn($id);

        return $entity;
    }
}

class ActionRunnerConditionFormulaImplementation implements Action
{
    public function run(ActionContext $context): void {}
}
