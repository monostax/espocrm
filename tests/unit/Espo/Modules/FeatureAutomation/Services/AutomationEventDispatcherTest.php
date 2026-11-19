<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureAutomation\Services;

use Espo\Core\Utils\Log;
use Espo\Modules\FeatureAutomation\Entities\Automation;
use Espo\Modules\FeatureAutomation\Services\ActionReceiptStore;
use Espo\Modules\FeatureAutomation\Services\AutomationEventDispatcher;
use Espo\Modules\FeatureAutomation\Services\AutomationRunner;
use Espo\Modules\FeatureAutomation\Services\AutomationTriggerFilterEvaluator;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

class AutomationEventDispatcherTest extends TestCase
{
    /** @var list<string> */
    private array $started = [];

    public function testSignalOnlyStartsAutomationsOfTheContactsWorkspace(): void
    {
        $dispatcher = $this->dispatcher([
            $this->automation('own', 'tenantA'),
            $this->automation('other', 'tenantB'),
            $this->automation('instance', null),
            $this->automation('crossTenant', 'tenantB', crossTenant: true),
        ], subjectTenant: 'tenantA');

        $dispatcher->dispatchSignal('enrichment.found', 'Contact', 'contact1', ['enrichment' => ['index' => 0]]);

        $this->assertSame(['own', 'instance', 'crossTenant'], $this->started);
    }

    public function testEntityChangeOnlyStartsAutomationsOfTheRecordsWorkspace(): void
    {
        $dispatcher = $this->dispatcher([
            $this->automation('own', 'tenantA', trigger: Automation::TRIGGER_ENTITY_CHANGE),
            $this->automation('other', 'tenantB', trigger: Automation::TRIGGER_ENTITY_CHANGE),
        ], subjectTenant: 'tenantA');

        $dispatcher->dispatchEntityChange('Contact', 'contact1', 'create');

        $this->assertSame(['own'], $this->started);
    }

    public function testSignalCodesStillFilter(): void
    {
        $dispatcher = $this->dispatcher([
            $this->automation('listens', 'tenantA', codes: ['enrichment.found']),
            $this->automation('deaf', 'tenantA', codes: ['something.else']),
        ], subjectTenant: 'tenantA');

        $dispatcher->dispatchSignal('enrichment.found', 'Contact', 'contact1');

        $this->assertSame(['listens'], $this->started);
        $this->assertTrue($dispatcher->hasSignalListeners('enrichment.found'));
        $this->assertFalse($dispatcher->hasSignalListeners('nobody.listens'));
    }

    public function testEmptyCodeListListensToEverySignal(): void
    {
        $dispatcher = $this->dispatcher([$this->automation('all', 'tenantA', codes: [])], subjectTenant: 'tenantA');

        $this->assertTrue($dispatcher->hasSignalListeners('enrichment.found'));
    }

    /**
     * @param list<Automation> $automations
     */
    private function dispatcher(array $automations, string $subjectTenant): AutomationEventDispatcher
    {
        $this->started = [];

        $builder = $this->createMock(RDBSelectBuilder::class);
        $builder->method('where')->willReturnSelf();
        $builder->method('find')->willReturnCallback(fn () => new EntityCollection($automations));

        $repository = $this->createMock(RDBRepository::class);
        $repository->method('where')->willReturnCallback(function (array $where) use ($automations) {
            $builder = $this->createMock(RDBSelectBuilder::class);
            $builder->method('find')->willReturn(new EntityCollection(array_values(array_filter(
                $automations,
                fn (Automation $a) => $a->get('triggerType') === $where['triggerType'],
            ))));

            return $builder;
        });
        $repository->method('select')->willReturn($builder);

        $subject = $this->createMock(Entity::class);
        $subject->method('hasAttribute')->with('tenantId')->willReturn(true);
        $subject->method('get')->with('tenantId')->willReturn($subjectTenant);

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getRDBRepository')->willReturn($repository);
        $entityManager->expects($this->atMost(1))->method('getEntityById')->willReturn($subject);

        $runner = $this->createMock(AutomationRunner::class);
        $runner->method('startRun')->willReturnCallback(function (Entity $automation) {
            $this->started[] = $automation->getId();

            return $this->createMock(Entity::class);
        });

        $filters = $this->createMock(AutomationTriggerFilterEvaluator::class);
        $filters->method('matches')->willReturn(true);

        return new AutomationEventDispatcher(
            $entityManager,
            $runner,
            $this->createMock(ActionReceiptStore::class),
            $filters,
            $this->createMock(Log::class),
        );
    }

    private function automation(
        string $id,
        ?string $tenantId,
        string $trigger = Automation::TRIGGER_SIGNAL,
        array $codes = ['enrichment.found'],
        bool $crossTenant = false,
    ): Automation {
        $values = [
            'tenantId' => $tenantId,
            'triggerType' => $trigger,
            'signalCodes' => $codes,
            'crossTenant' => $crossTenant,
            'subjectEntityType' => 'Contact',
            'triggerDebouncePeriod' => '',
        ];

        $automation = $this->createMock(Automation::class);
        $automation->method('getId')->willReturn($id);
        $automation->method('get')->willReturnCallback(fn (string $name) => $values[$name] ?? null);

        return $automation;
    }
}
