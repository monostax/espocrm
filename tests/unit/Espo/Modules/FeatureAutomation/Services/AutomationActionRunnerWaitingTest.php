<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureAutomation\Services;

use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\Modules\FeatureAutomation\Classes\AutomationActions\ActionWaiting;
use Espo\Modules\FeatureAutomation\Services\ActionReceiptStore;
use Espo\Modules\FeatureAutomation\Services\AutomationActionRunner;
use Espo\Modules\FeatureAutomation\Services\JoinCoordinator;
use Espo\Modules\FeatureAutomation\Services\WakeAtResolver;
use Espo\Modules\FeatureJourney\Classes\JourneyActions\Action;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\PeriodParser;
use Espo\Modules\FeatureJourney\Services\RestrictedFormulaRunner;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class AutomationActionRunnerWaitingTest extends TestCase
{
    private array $payload = [];
    /** @var list<string> */
    private array $ran = [];

    public function testWaitingActionParksTheItemAndStopsTheList(): void
    {
        $receipts = $this->createMock(ActionReceiptStore::class);
        $receipts->method('tryClaim')->willReturn(true);
        $receipts->expects($this->once())->method('release')->with('auto1', 'key1', 'action');

        $result = $this->runner($receipts)->runActions(
            [
                ['type' => 'first'],
                ['type' => 'waits', 'idempotencyKey' => 'key1'],
                ['type' => 'never'],
            ],
            $this->entity('auto1'),
            $this->entity('run1'),
            $this->item(),
            $this->entity('contact1', 'Contact'),
            'tenant1',
            ['seed' => true],
        );

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['waiting']);
        $this->assertSame('2030-01-01 00:00:00', $result['wakeAt']);
        $this->assertSame('2030-01-01 00:00:00', $result['payload']['_wakeAt']);
        $this->assertSame('2030-01-01 00:00:00', $this->payload['_wakeAt']);
        $this->assertTrue($this->payload['startedSomething']);
        $this->assertSame(['first', 'waits'], $this->ran);
        $this->assertSame('waiting_action', $result['log'][1]['status']);
        $this->assertSame('verification_pending', $result['log'][1]['reason']);
    }

    private function runner(ActionReceiptStore $receipts): AutomationActionRunner
    {
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturnCallback(
            fn (array $path) => $path[0] === 'app' && $path[1] === 'automationActionTypes' ? 'TestAction_' . $path[3] : null
        );

        $factory = $this->createMock(InjectableFactory::class);
        $factory->method('create')->willReturnCallback(function (string $class) {
            $type = substr($class, strlen('TestAction_'));

            return new class ($type, $this) implements Action {
                public function __construct(private string $type, private AutomationActionRunnerWaitingTest $test) {}

                public function run(ActionContext $context): void
                {
                    $this->test->ran($this->type, $context);
                }
            };
        });

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getEntityById')->willReturnCallback(fn () => $this->item());

        return new AutomationActionRunner(
            $entityManager,
            $metadata,
            $factory,
            $this->createMock(TenantGuard::class),
            $this->createMock(RestrictedFormulaRunner::class),
            $receipts,
            $this->createMock(JoinCoordinator::class),
            $this->createMock(PeriodParser::class),
            $this->createMock(WakeAtResolver::class),
            $this->createMock(Log::class),
        );
    }

    /** Called by the test actions. */
    public function ran(string $type, ActionContext $context): void
    {
        $this->ran[] = $type;

        if ($type === 'waits') {
            $context->record->set('payload', $this->payload + ['startedSomething' => true]);
            throw new ActionWaiting('2030-01-01 00:00:00', 'verification_pending');
        }
    }

    // TestAction_* classes only need to "exist" for the runner's class_exists() check.
    public static function setUpBeforeClass(): void
    {
        foreach (['first', 'waits', 'never'] as $type) {
            if (!class_exists('TestAction_' . $type)) {
                eval('class TestAction_' . $type . ' {}');
            }
        }
    }

    private function item(): Entity
    {
        $item = $this->createMock(Entity::class);
        $item->method('getId')->willReturn('item1');
        $item->method('hasId')->willReturn(true);
        $item->method('getEntityType')->willReturn('AutomationRunItem');
        $item->method('get')->willReturnCallback(fn (string $name) => $name === 'payload' ? $this->payload : null);
        $item->method('set')->willReturnCallback(function ($name, $value = null) use (&$item) {
            if ($name === 'payload') {
                $this->payload = $value;
            }

            return $item;
        });

        return $item;
    }

    private function entity(string $id, string $type = 'Automation'): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn($id);
        $entity->method('getEntityType')->willReturn($type);

        return $entity;
    }
}
