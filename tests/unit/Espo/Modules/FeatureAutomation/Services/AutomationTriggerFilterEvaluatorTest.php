<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureAutomation\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\FeatureAutomation\Services\AutomationTriggerFilterEvaluator;
use Espo\Modules\FeatureJourney\Services\CustomFieldsBag;
use Espo\Modules\FeatureJourney\Services\RestrictedFormulaRunner;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class AutomationTriggerFilterEvaluatorTest extends TestCase
{
    public function testEmptyFilterAllowsTriggerWithoutLoadingSubject(): void
    {
        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->expects($this->never())->method('getEntityById');

        $evaluator = $this->makeEvaluator($entityManager);

        $this->assertTrue($evaluator->matches($this->automationWith([]), null, null));
    }

    public function testVisualAndOrTreeIsEvaluatedAgainstTriggerSubject(): void
    {
        $tree = [
            'type' => 'and',
            'value' => [
                ['type' => 'equals', 'attribute' => 'status', 'value' => 'New'],
                [
                    'type' => 'or',
                    'value' => [
                        ['type' => 'equals', 'attribute' => 'source', 'value' => 'Web'],
                    ],
                ],
            ],
        ];
        $subject = $this->createMock(Entity::class);
        $entityManager = $this->createMock(EntityManager::class);
        $entityManager
            ->expects($this->once())
            ->method('getEntityById')
            ->with('Contact', 'contact-1')
            ->willReturn($subject);
        $bag = $this->createMock(CustomFieldsBag::class);
        $bag
            ->expects($this->once())
            ->method('matchWhereItem')
            ->with($tree, $subject)
            ->willReturn(true);

        $evaluator = $this->makeEvaluator($entityManager, bag: $bag);

        $this->assertTrue($evaluator->matches(
            $this->automationWith(['entityTypeFilter' => $tree]),
            'Contact',
            'contact-1',
        ));
    }

    public function testFormulaOverridesVisualTree(): void
    {
        $subject = $this->createMock(Entity::class);
        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getEntityById')->willReturn($subject);
        $formulaRunner = $this->createMock(RestrictedFormulaRunner::class);
        $formulaRunner
            ->expects($this->once())
            ->method('run')
            ->with(
                "comparison\\equals(entity\\attribute('status'), 'New')",
                $subject,
                $this->isInstanceOf(\stdClass::class),
                RestrictedFormulaRunner::MODE_CONDITION,
            )
            ->willReturn(false);
        $bag = $this->createMock(CustomFieldsBag::class);
        $bag->expects($this->never())->method('matchWhereItem');

        $evaluator = $this->makeEvaluator(
            $entityManager,
            formulaRunner: $formulaRunner,
            bag: $bag,
        );

        $this->assertFalse($evaluator->matches(
            $this->automationWith([
                'entityTypeFilter' => ['type' => 'equals', 'attribute' => 'status', 'value' => 'New'],
                'entityTypeFilterFormula' => "comparison\\equals(entity\\attribute('status'), 'New')",
            ]),
            'Contact',
            'contact-1',
        ));
    }

    public function testHasSubjectFilterDetectsVisualAndFormula(): void
    {
        $evaluator = $this->makeEvaluator($this->createMock(EntityManager::class));

        $this->assertFalse($evaluator->hasSubjectFilter($this->automationWith([])));
        $this->assertTrue($evaluator->hasSubjectFilter($this->automationWith([
            'entityTypeFilter' => ['type' => 'equals', 'attribute' => 'emailAddress', 'value' => 'a@b.com'],
        ])));
        $this->assertTrue($evaluator->hasSubjectFilter($this->automationWith([
            'entityTypeFilterFormula' => 'true',
        ])));
    }

    public function testResolveSubjectEntityTypePrefersDedicatedField(): void
    {
        $evaluator = $this->makeEvaluator($this->createMock(EntityManager::class));

        $this->assertSame('Contact', $evaluator->resolveSubjectEntityType($this->automationWith([
            'subjectEntityType' => 'Contact',
            'entityTypeFilter' => 'Lead',
        ])));
        $this->assertSame('Lead', $evaluator->resolveSubjectEntityType($this->automationWith([
            'entityTypeFilter' => 'Lead',
        ])));
    }

    /**
     * Regression: an unresolved tenant used to skip the tenant predicate entirely,
     * leaving only an ACL filter that an admin run-as user bypasses — i.e. an
     * instance-wide read. It must now abort before any query is built.
     *
     * @dataProvider unresolvedTenantProvider
     */
    public function testFindMatchingSubjectsRefusesUnscopedVisualTreeRead(?string $tenantId): void
    {
        $entityManager = $this->createMock(EntityManager::class);
        // Nothing may be queried at all when the tenant is unresolved.
        $entityManager->expects($this->never())->method('getRDBRepository');

        $selectBuilderFactory = $this->createMock(SelectBuilderFactory::class);
        $selectBuilderFactory->expects($this->never())->method('create');

        $evaluator = $this->makeEvaluator(
            $entityManager,
            tenantGuard: $this->failClosedTenantGuard(),
            selectBuilderFactory: $selectBuilderFactory,
        );

        $this->expectException(Error::class);

        $evaluator->findMatchingSubjects(
            $this->automationWith([
                'entityTypeFilter' => ['type' => 'equals', 'attribute' => 'status', 'value' => 'New'],
            ]),
            'Contact',
            $tenantId,
            10,
            [],
            $this->createMock(User::class),
        );
    }

    /**
     * @dataProvider unresolvedTenantProvider
     */
    public function testFindMatchingSubjectsRefusesUnscopedFormulaCandidateScan(?string $tenantId): void
    {
        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->expects($this->never())->method('getRDBRepository');

        $formulaRunner = $this->createMock(RestrictedFormulaRunner::class);
        // The scan must abort before any candidate reaches user-authored formula code.
        $formulaRunner->expects($this->never())->method('run');

        $evaluator = $this->makeEvaluator(
            $entityManager,
            formulaRunner: $formulaRunner,
            tenantGuard: $this->failClosedTenantGuard(),
        );

        $this->expectException(Error::class);

        $evaluator->findMatchingSubjects(
            $this->automationWith(['entityTypeFilterFormula' => 'true']),
            'Contact',
            $tenantId,
            10,
            [],
            $this->createMock(User::class),
        );
    }

    /**
     * @return iterable<string, array{0: ?string}>
     */
    public static function unresolvedTenantProvider(): iterable
    {
        yield 'null tenant' => [null];
        yield 'empty tenant' => [''];
    }

    /**
     * A TenantGuard double that enforces the real fail-closed contract.
     */
    private function failClosedTenantGuard(): TenantGuard
    {
        $guard = $this->createMock(TenantGuard::class);
        $guard->method('tenantWhereForRead')->willReturnCallback(
            static function (string $entityType, ?string $tenantId, string $ctx, bool $cross = false): array {
                if ($cross) {
                    return [];
                }
                if ($tenantId === null || trim($tenantId) === '') {
                    throw new Error("TenantGuard: {$ctx} requires a resolved tenant.");
                }

                return ['tenantId' => $tenantId];
            },
        );

        return $guard;
    }

    private function makeEvaluator(
        EntityManager $entityManager,
        ?RestrictedFormulaRunner $formulaRunner = null,
        ?CustomFieldsBag $bag = null,
        ?TenantGuard $tenantGuard = null,
        ?SelectBuilderFactory $selectBuilderFactory = null,
    ): AutomationTriggerFilterEvaluator {
        return new AutomationTriggerFilterEvaluator(
            $entityManager,
            $formulaRunner ?? $this->createMock(RestrictedFormulaRunner::class),
            $bag ?? $this->createMock(CustomFieldsBag::class),
            $selectBuilderFactory ?? $this->createMock(SelectBuilderFactory::class),
            $tenantGuard ?? $this->createMock(TenantGuard::class),
            $this->createMock(Log::class),
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function automationWith(array $attributes): Entity
    {
        $automation = $this->createMock(Entity::class);
        $automation->method('get')->willReturnCallback(
            static fn (string $attribute): mixed => $attributes[$attribute] ?? null,
        );
        $automation->method('getId')->willReturn('automation-1');

        return $automation;
    }
}
