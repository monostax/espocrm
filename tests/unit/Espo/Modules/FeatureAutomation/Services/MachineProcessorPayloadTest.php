<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureAutomation\Services;

use Espo\Core\Utils\Log;
use Espo\Modules\FeatureAutomation\Services\AutomationActionRunner;
use Espo\Modules\FeatureAutomation\Services\JoinCoordinator;
use Espo\Modules\FeatureAutomation\Services\MachineProcessor;
use Espo\Modules\FeatureAutomation\Services\WakeAtResolver;
use Espo\Modules\FeatureJourney\Services\PeriodParser;
use Espo\Modules\FeatureJourney\Services\RestrictedFormulaRunner;
use Espo\ORM\Entity;
use PHPUnit\Framework\TestCase;

/**
 * Actions save the item payload and the action runner returns the saved copy; the machine
 * must keep those writes when a state's actions succeed, while its own bookkeeping wins.
 */
class MachineProcessorPayloadTest extends TestCase
{
    /** @var list<array<string, mixed>> payload each runActions() call received */
    private array $seen = [];

    public function testOnEnterWritesSurviveAFinalStateAndMachineKeysWin(): void
    {
        $result = $this->process(
            ['initial' => 'Verify', 'states' => [['id' => 'Verify', 'type' => 'final', 'onEnter' => [['type' => 'x']]]], 'transitions' => []],
            ['_trigger' => ['entityId' => 'c1']],
            [fn (array $p) => $p + [
                'verification' => ['whatsapp_registration' => 'registered'],
                'channelIdentity' => ['phone' => 'added'],
                '_wakeAt' => '2020-01-01 00:00:00',
                '_state' => 'Stale',
            ]],
        );

        $this->assertSame('Done', $result['status']);
        $this->assertSame(['whatsapp_registration' => 'registered'], $result['payload']['verification']);
        $this->assertSame(['phone' => 'added'], $result['payload']['channelIdentity']);
        $this->assertSame('Verify', $result['payload']['_state']);
        $this->assertArrayNotHasKey('_wakeAt', $result['payload']);
        $this->assertTrue($result['payload']['_onEnterDone']);
        $this->assertSame(['entityId' => 'c1'], $result['payload']['_trigger']);
    }

    public function testWritesCarryAcrossATransitionIntoTheNextState(): void
    {
        $result = $this->process(
            [
                'initial' => 'A',
                'states' => [
                    ['id' => 'A', 'type' => 'normal', 'onEnter' => [['type' => 'x']]],
                    ['id' => 'B', 'type' => 'final', 'onEnter' => [['type' => 'x']]],
                ],
                'transitions' => [['from' => 'A', 'to' => 'B']],
            ],
            [],
            [
                fn (array $p) => $p + ['vars' => ['fromA' => 1]],
                fn (array $p) => $p + ['report' => ['fromB' => 2]],
            ],
        );

        $this->assertSame(['fromA' => 1], $this->seen[1]['vars']);
        $this->assertSame(['fromA' => 1], $result['payload']['vars']);
        $this->assertSame(['fromB' => 2], $result['payload']['report']);
        $this->assertSame('B', $result['payload']['_state']);
    }

    public function testActionOwnedUnderscoreKeysReachALaterJoin(): void
    {
        $joins = $this->createMock(JoinCoordinator::class);
        $joins->expects($this->atLeastOnce())->method('evaluate')
            ->willReturnCallback(function (array $payload, string $mode) {
                $this->assertSame([['runId' => 'child1']], $payload['_pendingJoins'] ?? null);

                return ['satisfied' => true, 'payload' => $payload];
            });

        $result = $this->process(
            [
                'initial' => 'Spawn',
                'states' => [
                    ['id' => 'Spawn', 'type' => 'normal', 'onEnter' => [['type' => 'startChildAutomation']]],
                    ['id' => 'Await', 'type' => 'join', 'joinMode' => 'waitAll'],
                    ['id' => 'Done', 'type' => 'final'],
                ],
                'transitions' => [['from' => 'Spawn', 'to' => 'Await'], ['from' => 'Await', 'to' => 'Done']],
            ],
            [],
            [fn (array $p) => $p + ['_pendingJoins' => [['runId' => 'child1']]]],
            $joins,
        );

        $this->assertSame('Done', $result['status']);
    }

    public function testNothingChangesWhenEveryActionWasSkipped(): void
    {
        $result = $this->process(
            ['initial' => 'S', 'states' => [['id' => 'S', 'type' => 'final', 'onEnter' => [['type' => 'x']]]], 'transitions' => []],
            ['keep' => 'me'],
            [fn (array $p) => $p],
        );

        $this->assertSame('me', $result['payload']['keep']);
        $this->assertSame('S', $result['payload']['_state']);
    }

    /**
     * @param array<string, mixed> $def
     * @param array<string, mixed> $payload
     * @param list<callable(array): array> $writes what each onEnter call leaves in the item payload
     */
    private function process(array $def, array $payload, array $writes, ?JoinCoordinator $joins = null): array
    {
        $this->seen = [];
        $runner = $this->createMock(AutomationActionRunner::class);
        $runner->method('runActions')->willReturnCallback(function (array $actions, $a, $r, $i, $t, $tenant, array $in) use (&$writes) {
            $this->seen[] = $in;
            $write = array_shift($writes) ?? fn (array $p) => $p;

            return ['ok' => true, 'log' => [], 'payload' => $write($in)];
        });

        $processor = new MachineProcessor(
            $runner,
            $joins ?? $this->createMock(JoinCoordinator::class),
            $this->createMock(PeriodParser::class),
            $this->createMock(WakeAtResolver::class),
            $this->createMock(RestrictedFormulaRunner::class),
            $this->createMock(Log::class),
        );

        $entity = $this->createMock(Entity::class);

        return $processor->process($def, $entity, $entity, $entity, $entity, 'tenant1', $payload);
    }
}
