<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureJourney\Services;

use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\JourneyCatchUp;
use Espo\ORM\Entity;
use PHPUnit\Framework\TestCase;

class JourneyCatchUpTest extends TestCase
{
    private const NEW = '+5511987650001';
    private const SECOND = '+5511987650002';
    private const OLD = '+5511987650003';
    private const WRONG = '+5511987650004';

    /** D0 → D2 → D3, paused at D3 with one wrong number. */
    private function startedAtD3(): array
    {
        return JourneyCatchUp::start(['excluded' => [self::WRONG]], 'd3', '2026-11-20 12:00:00',
            ['d0', 'd2'], self::NEW, 'joao');
    }

    /** Walk one stage the way TransitionExecutor does. */
    private function enter(array $s, string $stage, string $type = 'Normal'): array
    {
        return JourneyCatchUp::afterEnter(JourneyCatchUp::beforeEnter($s, $stage, $type), $stage);
    }

    public function testNotCatchingUpLeavesEveryoneIn(): void
    {
        self::assertFalse(JourneyCatchUp::active([]));
        self::assertNull(JourneyCatchUp::lane(['excluded' => [self::WRONG]], 'd0', 'OnEnter'));
    }

    public function testAddedNumberWalksTheStagesTheOthersGotThenEveryoneContinues(): void
    {
        $s = $this->startedAtD3();
        self::assertSame(['d0', 'd2', 'd3'], $s['catchUp']['visited']);

        foreach (['d0', 'd2'] as $stage) {
            self::assertSame([self::NEW => 'joao'], JourneyCatchUp::lane($s, $stage, 'OnEnter'));
            $s = $this->enter($s, $stage);
            self::assertTrue(JourneyCatchUp::active($s), "still catching up after {$stage}");
        }

        self::assertSame([self::NEW => 'joao'], JourneyCatchUp::lane($s, 'd3', 'OnEnter'));
        $s = $this->enter($s, 'd3');
        self::assertFalse(JourneyCatchUp::active($s), 'reaching the stage the others wait at ends the catch-up');
        self::assertSame([self::WRONG], $s['excluded']);
        self::assertNull(JourneyCatchUp::lane($s, 'd4', 'OnEnter'));
    }

    public function testAStageTheCompanyNeverGotGoesToEveryone(): void
    {
        $s = JourneyCatchUp::beforeEnter($this->startedAtD3(), 'branch', 'Normal');
        self::assertFalse(JourneyCatchUp::active($s));
    }

    public function testTerminalStagesEndTheCatchUpBeforeTheirActions(): void
    {
        self::assertFalse(JourneyCatchUp::active(JourneyCatchUp::beforeEnter($this->startedAtD3(), 'd0', 'Exit')));
        self::assertFalse(JourneyCatchUp::active(JourneyCatchUp::beforeEnter($this->startedAtD3(), 'd0', 'Success')));
    }

    public function testCaughtUpAtTheEntryStageEndsRightAway(): void
    {
        $s = JourneyCatchUp::start([], 'd0', '2026-11-23 16:45:47', ['d0'], self::NEW, 'joao');
        self::assertSame([self::NEW => 'joao'], JourneyCatchUp::lane($s, 'd0', 'OnEnter'));
        self::assertFalse(JourneyCatchUp::active($this->enter($s, 'd0')));
    }

    public function testSecondNumberRestartsAndTheFirstWaitsAtTheStageItReached(): void
    {
        $s = $this->enter($this->startedAtD3(), 'd0');
        $s = JourneyCatchUp::start($s, 'd2', '2026-11-25 12:00:00', ['d0'], self::SECOND, 'maria');

        self::assertSame('d3', $s['catchUp']['until'], 'the others still wait at the first stop');
        self::assertSame('2026-11-20 12:00:00', $s['catchUp']['untilEnteredAt']);
        self::assertSame([self::SECOND => 'maria'], JourneyCatchUp::lane($s, 'd0', 'OnEnter'));
        $s = $this->enter($s, 'd0');
        self::assertSame([self::SECOND => 'maria'], JourneyCatchUp::lane($s, 'd0', 'OnExit'), 'only who entered in this pass leaves it');
        self::assertSame([self::NEW => 'joao', self::SECOND => 'maria'], JourneyCatchUp::lane($s, 'd2', 'OnEnter'));
    }

    public function testWrongAddedNumberLeavesNobodyToCatchUp(): void
    {
        $s = JourneyCatchUp::drop($this->startedAtD3(), self::NEW);
        self::assertTrue(JourneyCatchUp::abandoned($s));
        self::assertSame([], JourneyCatchUp::lane($s, 'd0', 'OnEnter'));
        self::assertFalse(JourneyCatchUp::abandoned($this->startedAtD3()));
    }

    public function testActionContextSendsOnlyToTheAddedNumberWhileCatchingUp(): void
    {
        $joao = $this->entity('Contact', 'joao');
        $maria = $this->entity('Contact', 'maria');
        $ctx = $this->context($this->startedAtD3(), 'd0');

        self::assertSame([$joao], $ctx->recipients([$joao, $maria]));
        self::assertSame([self::NEW], $ctx->phones([self::OLD, self::WRONG, self::NEW]));

        $after = $this->context(['excluded' => [self::WRONG]], 'd4');
        self::assertSame([$joao, $maria], $after->recipients([$joao, $maria]));
        self::assertSame([self::OLD, self::NEW], $after->phones([self::OLD, self::WRONG, self::NEW]));
    }

    private function context(array $state, string $stageId): ActionContext
    {
        $record = $this->entity('JourneyRecord', 'record', ['replyReviewState' => json_decode(json_encode($state))]);

        return new ActionContext(
            $this->entity('Account', 'account'),
            $record,
            $this->entity('JourneyStage', $stageId),
            $this->entity('Journey', 'journey'),
            'OnEnter',
            [],
            'tenant',
        );
    }

    private function entity(string $type, string $id, array $values = []): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('getEntityType')->willReturn($type);
        $entity->method('getId')->willReturn($id);
        $entity->method('hasId')->willReturn(true);
        $entity->method('get')->willReturnCallback(static fn (string $name) => $values[$name] ?? null);

        return $entity;
    }
}
