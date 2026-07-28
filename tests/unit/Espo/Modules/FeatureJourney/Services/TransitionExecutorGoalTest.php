<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureJourney\Services;

use Espo\Modules\FeatureJourney\Entities\Journey;
use Espo\Modules\FeatureJourney\Entities\JourneyRecord;
use Espo\Modules\FeatureJourney\Entities\JourneyStage;
use Espo\Modules\FeatureJourney\Services\JourneyLifecycleEmitter;
use Espo\Modules\FeatureJourney\Services\TransitionExecutor;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class TransitionExecutorGoalTest extends TestCase
{
    public function testGoalSuccessPreservesAttributionAndEmitsBothLifecycles(): void
    {
        $recordState = [
            'status' => JourneyRecord::STATUS_ACTIVE,
            'claimedAt' => '2026-07-28 10:00:00',
            'targetType' => 'Contact',
            'targetId' => 'contact-1',
        ];
        $journeyState = [
            'name' => 'Nurture',
            'activeCount' => 1,
            'completedCount' => 0,
            'goalCount' => 0,
            'goalEventCodes' => ['demo_booked'],
        ];
        $stageState = ['stageType' => JourneyStage::TYPE_SUCCESS];

        $record = $this->statefulEntity('record-1', $recordState);
        $journey = $this->statefulEntity('journey-1', $journeyState);
        $stage = $this->statefulEntity('stage-success', $stageState);
        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getEntityById')->willReturnCallback(
            static fn (string $entityType, string $id): ?Entity =>
                $entityType === Journey::ENTITY_TYPE && $id === 'journey-1' ? $journey : null,
        );

        $events = [];
        $emitter = $this->createMock(JourneyLifecycleEmitter::class);
        $emitter->expects($this->exactly(2))
            ->method('emit')
            ->willReturnCallback(
                static function (string $tenantId, string $code, array $options) use (&$events): void {
                    $events[] = [$tenantId, $code, $options];
                },
            );

        $executor = $this->executor($entityManager, $emitter);
        $this->invokeTerminalHandler(
            $executor,
            $record,
            $journey,
            $stage,
            true,
            ['code' => 'demo_booked'],
        );

        $this->assertSame(JourneyRecord::STATUS_COMPLETED, $recordState['status']);
        $this->assertNull($recordState['claimedAt']);
        $this->assertSame('goal', $recordState['exitReason']);
        $this->assertSame(0, $journeyState['activeCount']);
        $this->assertSame(1, $journeyState['completedCount']);
        $this->assertSame(1, $journeyState['goalCount']);
        $this->assertSame([
            JourneyLifecycleEmitter::CODE_COMPLETED,
            JourneyLifecycleEmitter::CODE_GOAL_REACHED,
        ], array_column($events, 1));
        $this->assertSame('demo_booked', $events[1][2]['properties']['goalCode']);
    }

    public function testOrdinarySuccessDoesNotCountAsGoal(): void
    {
        $recordState = [
            'status' => JourneyRecord::STATUS_ACTIVE,
            'claimedAt' => '2026-07-28 10:00:00',
            'targetType' => 'Contact',
            'targetId' => 'contact-1',
        ];
        $journeyState = [
            'name' => 'Nurture',
            'activeCount' => 1,
            'completedCount' => 0,
            'goalCount' => 0,
        ];
        $stageState = ['stageType' => JourneyStage::TYPE_SUCCESS];

        $record = $this->statefulEntity('record-1', $recordState);
        $journey = $this->statefulEntity('journey-1', $journeyState);
        $stage = $this->statefulEntity('stage-success', $stageState);
        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getEntityById')->willReturn($journey);

        $emitter = $this->createMock(JourneyLifecycleEmitter::class);
        $emitter->expects($this->once())
            ->method('emit')
            ->with('tenant-1', JourneyLifecycleEmitter::CODE_COMPLETED, $this->isType('array'));

        $executor = $this->executor($entityManager, $emitter);
        $this->invokeTerminalHandler($executor, $record, $journey, $stage, false, null);

        $this->assertSame(JourneyRecord::STATUS_COMPLETED, $recordState['status']);
        $this->assertNull($recordState['claimedAt']);
        $this->assertSame('success-stage', $recordState['exitReason']);
        $this->assertSame(0, $journeyState['activeCount']);
        $this->assertSame(1, $journeyState['completedCount']);
        $this->assertSame(0, $journeyState['goalCount']);
    }

    private function executor(
        EntityManager $entityManager,
        JourneyLifecycleEmitter $emitter,
    ): TransitionExecutor {
        $reflection = new ReflectionClass(TransitionExecutor::class);
        /** @var TransitionExecutor $executor */
        $executor = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('entityManager')->setValue($executor, $entityManager);
        $reflection->getProperty('lifecycleEmitter')->setValue($executor, $emitter);

        return $executor;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function statefulEntity(string $id, array &$state): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn($id);
        $entity->method('get')->willReturnCallback(
            static fn (string $name): mixed => $state[$name] ?? null,
        );
        $entity->method('set')->willReturnCallback(
            static function (mixed $name, mixed $value = null) use (&$state, &$entity): Entity {
                if (is_array($name)) {
                    $state = array_replace($state, $name);
                } else {
                    $state[(string) $name] = $value;
                }

                return $entity;
            },
        );

        return $entity;
    }

    /**
     * @param array<string, mixed>|null $signal
     */
    private function invokeTerminalHandler(
        TransitionExecutor $executor,
        Entity $record,
        Entity $journey,
        Entity $stage,
        bool $goalMatched,
        ?array $signal,
    ): void {
        $method = (new ReflectionClass(TransitionExecutor::class))->getMethod('handleTerminalStage');
        $method->invoke(
            $executor,
            $record,
            $journey,
            $stage,
            'tenant-1',
            $goalMatched,
            $signal,
        );
    }
}
