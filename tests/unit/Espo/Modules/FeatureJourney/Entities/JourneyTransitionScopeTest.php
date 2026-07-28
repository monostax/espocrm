<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureJourney\Entities;

use Espo\Modules\FeatureJourney\Entities\JourneyTransition;
use Espo\Core\ORM\Entity;
use PHPUnit\Framework\TestCase;

class JourneyTransitionScopeTest extends TestCase
{
    public function testLegacyScopeIsDerivedFromSourceStage(): void
    {
        $stageTransition = $this->entity(['fromStageId' => 'stage-1']);
        $enrollmentTransition = $this->entity(['fromStageId' => null]);

        $this->assertSame(JourneyTransition::SCOPE_STAGE, JourneyTransition::resolveScope($stageTransition));
        $this->assertSame(
            JourneyTransition::SCOPE_ENROLLMENT,
            JourneyTransition::resolveScope($enrollmentTransition),
        );
    }

    public function testSourceStageWinsOverAnInconsistentStoredLegacyScope(): void
    {
        $transition = $this->entity([
            'scope' => JourneyTransition::SCOPE_ENROLLMENT,
            'fromStageId' => 'stage-1',
        ]);

        $this->assertSame(JourneyTransition::SCOPE_STAGE, JourneyTransition::resolveScope($transition));
        $this->assertTrue(JourneyTransition::appliesToStage($transition, 'stage-1'));
    }

    public function testJourneyScopeAppliesToAnyCurrentStageButEnrollmentDoesNot(): void
    {
        $journeyTransition = $this->entity([
            'scope' => JourneyTransition::SCOPE_JOURNEY,
            'fromStageId' => null,
        ]);
        $enrollmentTransition = $this->entity([
            'scope' => JourneyTransition::SCOPE_ENROLLMENT,
            'fromStageId' => null,
        ]);

        $this->assertTrue(JourneyTransition::appliesToStage($journeyTransition, 'stage-1'));
        $this->assertTrue(JourneyTransition::appliesToStage($journeyTransition, 'stage-2'));
        $this->assertFalse(JourneyTransition::appliesToStage($journeyTransition, null));
        $this->assertFalse(JourneyTransition::appliesToStage($enrollmentTransition, 'stage-1'));
    }

    public function testStageScopeRequiresItsConfiguredCurrentStage(): void
    {
        $transition = $this->entity([
            'scope' => JourneyTransition::SCOPE_STAGE,
            'fromStageId' => 'stage-1',
        ]);

        $this->assertTrue(JourneyTransition::appliesToStage($transition, 'stage-1'));
        $this->assertFalse(JourneyTransition::appliesToStage($transition, 'stage-2'));
    }

    public function testOrderingUsesPriorityThenStageSpecificityThenId(): void
    {
        $global = $this->entity([
            'scope' => JourneyTransition::SCOPE_JOURNEY,
            'priority' => 10,
        ], 'a-global');
        $stage = $this->entity([
            'scope' => JourneyTransition::SCOPE_STAGE,
            'fromStageId' => 'stage-1',
            'priority' => 10,
        ], 'z-stage');
        $earlier = $this->entity([
            'scope' => JourneyTransition::SCOPE_JOURNEY,
            'priority' => 5,
        ], 'earlier');

        $transitions = [$global, $stage, $earlier];
        usort($transitions, [JourneyTransition::class, 'compareForRecord']);

        $this->assertSame([$earlier, $stage, $global], $transitions);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function entity(array $attributes, string $id = 'transition'): Entity
    {
        $entity = $this->createStub(Entity::class);
        $entity->method('get')->willReturnCallback(
            static fn (string $name): mixed => $attributes[$name] ?? null,
        );
        $entity->method('getId')->willReturn($id);

        return $entity;
    }
}
