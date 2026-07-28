<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureJourney\Services;

use Espo\Core\Formula\Manager as FormulaManager;
use Espo\Core\Utils\Metadata;
use Espo\Modules\FeatureJourney\Services\ActionConditionEvaluator;
use Espo\Modules\FeatureJourney\Services\CustomFieldsBag;
use Espo\Modules\FeatureJourney\Services\RestrictedFormulaRunner;
use Espo\ORM\Entity;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ActionConditionEvaluatorTest extends TestCase
{
    public function testMatchesNestedAndOrTree(): void
    {
        $target = $this->target([
            'status' => 'Qualified',
            'emailAddress' => null,
            'customFields' => (object) ['plan' => 'pro'],
        ]);

        $matches = $this->evaluator()->matches($target, [
            'type' => 'and',
            'value' => [
                ['type' => 'equals', 'attribute' => 'status', 'value' => 'Qualified'],
                [
                    'type' => 'or',
                    'value' => [
                        ['type' => 'isNotNull', 'attribute' => 'emailAddress'],
                        ['type' => 'equals', 'attribute' => 'customFields.plan', 'value' => 'pro'],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($matches);
    }

    public function testNonMatchingNestedTreeReturnsFalse(): void
    {
        $target = $this->target([
            'emailAddress' => null,
            'customFields' => ['plan' => 'free'],
        ]);

        $matches = $this->evaluator()->matches($target, [
            'type' => 'or',
            'value' => [
                ['type' => 'isNotNull', 'attribute' => 'emailAddress'],
                ['type' => 'equals', 'attribute' => 'customFields.plan', 'value' => 'pro'],
            ],
        ]);

        $this->assertFalse($matches);
    }

    public function testLegacyConditionListRemainsSupported(): void
    {
        $target = $this->target(['status' => 'New', 'score' => 20]);

        $this->assertTrue($this->evaluator()->matches($target, [
            ['type' => 'equals', 'attribute' => 'status', 'value' => 'New'],
            ['type' => 'greaterThan', 'attribute' => 'score', 'value' => 10],
        ]));
    }

    public function testRejectsRelatedRecordFields(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->evaluator()->assertValid([
            'type' => 'equals',
            'attribute' => 'assignedUser.name',
            'value' => 'Test',
        ]);
    }

    public function testMalformedNonEmptyGroupFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->evaluator()->matches(
            $this->target(['status' => 'New']),
            ['type' => 'or', 'value' => []],
        );
    }

    public function testResolvesDynamicExpectedValue(): void
    {
        $formulaManager = $this->createMock(FormulaManager::class);
        $formulaManager->expects($this->once())->method('run')->willReturn('pro');

        $this->assertTrue($this->evaluator($formulaManager)->matches(
            $this->target(['customFields' => ['plan' => 'pro']]),
            [
                'type' => 'equals',
                'attribute' => 'customFields.plan',
                'valueFormula' => "entity\\attribute('preferredPlan')",
            ],
        ));
    }

    public function testOrShortCircuitsBeforeUnusedValueFormula(): void
    {
        $formulaManager = $this->createMock(FormulaManager::class);
        $formulaManager->expects($this->never())->method('run');

        $this->assertTrue($this->evaluator($formulaManager)->matches(
            $this->target(['status' => 'New']),
            [
                'type' => 'or',
                'value' => [
                    ['type' => 'equals', 'attribute' => 'status', 'value' => 'New'],
                    [
                        'type' => 'equals',
                        'attribute' => 'status',
                        'valueFormula' => "entity\\attribute('fallbackStatus')",
                    ],
                ],
            ],
        ));
    }

    private function evaluator(?FormulaManager $formulaManager = null): ActionConditionEvaluator
    {
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturnCallback(
            static fn (array $path): mixed => $path === ['app', 'customFields', 'attributeName']
                ? 'customFields'
                : null,
        );

        return new ActionConditionEvaluator(
            new CustomFieldsBag($metadata),
            new RestrictedFormulaRunner($formulaManager ?? $this->createMock(FormulaManager::class)),
        );
    }

    /** @param array<string, mixed> $values */
    private function target(array $values): Entity
    {
        $target = $this->createMock(Entity::class);
        $target->method('get')->willReturnCallback(
            static fn (string $field): mixed => $values[$field] ?? null,
        );

        return $target;
    }
}
