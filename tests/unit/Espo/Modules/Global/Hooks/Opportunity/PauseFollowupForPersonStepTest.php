<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Hooks\Opportunity;

use Espo\Core\ORM\Entity;
use Espo\Core\Utils\Metadata;
use Espo\Modules\Global\Hooks\Opportunity\PauseFollowupForPersonStep;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/** One next step at a time: a person's pending step pauses the AI follow-up. */
class PauseFollowupForPersonStepTest extends TestCase
{
    private array $activities = [];
    private int $lookups = 0;

    private function entity(string $type, array $values): Entity
    {
        $attributes = array_fill_keys(['id', 'status', 'nextActionId', 'nextActionType', 'followupTimer'], ['type' => 'varchar']);
        $entity = new Entity($type, ['attributes' => $attributes]);
        $entity->set($values);
        $entity->setAsNotNew();
        $entity->updateFetchedValues();
        return $entity;
    }

    private function hook(): PauseFollowupForPersonStep
    {
        $em = $this->createStub(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(function (string $type, string $id) {
            $this->lookups++;
            return $this->activities["$type:$id"] ?? null;
        });
        $metadata = $this->createStub(Metadata::class);
        $metadata->method('get')->willReturnCallback(fn (array $path) => match ($path) {
            ['entityDefs', 'Task', 'fields', 'status', 'notActualOptions'] => ['Completed', 'Canceled', 'Deferred'],
            ['scopes', 'Call', 'activityStatusList'], ['scopes', 'Meeting', 'activityStatusList'] => ['Planned'],
            default => null,
        });
        return new PauseFollowupForPersonStep($em, $metadata);
    }

    private function activity(string $type, string $id, string $status): void
    {
        $this->activities["$type:$id"] = $this->entity($type, ['id' => $id, 'status' => $status]);
    }

    private function save(Entity $opportunity): void
    {
        $this->hook()->beforeSave($opportunity, SaveOptions::fromAssoc([]));
    }

    public function testSelectingAPendingTaskPausesAnActiveFollowup(): void
    {
        $this->activity('Task', 't1', 'Not Started');
        $deal = $this->entity('Opportunity', ['followupTimer' => '2026-11-11 13:00:00']);
        $deal->set(['nextActionId' => 't1', 'nextActionType' => 'Task']);
        $this->save($deal);
        $this->assertNull($deal->get('followupTimer'));
    }

    public function testArmingAFollowupWhileAPersonStepIsPendingIsPaused(): void
    {
        $this->activity('Call', 'c1', 'Planned');
        $deal = $this->entity('Opportunity', ['nextActionId' => 'c1', 'nextActionType' => 'Call', 'followupTimer' => null]);
        $deal->set('followupTimer', '2026-11-11 13:00:00');
        $this->save($deal);
        $this->assertNull($deal->get('followupTimer'));
    }

    public function testAFinishedStepNoLongerBlocksTheFollowup(): void
    {
        $this->activity('Task', 't1', 'Completed');
        $this->activity('Meeting', 'm1', 'Held');
        foreach ([['t1', 'Task'], ['m1', 'Meeting']] as [$id, $type]) {
            $deal = $this->entity('Opportunity', ['nextActionId' => $id, 'nextActionType' => $type, 'followupTimer' => null]);
            $deal->set('followupTimer', '2026-11-11 13:00:00');
            $this->save($deal);
            $this->assertSame('2026-11-11 13:00:00', $deal->get('followupTimer'), $type);
        }
    }

    public function testHandingBackToTheAiClearsTheStepAndKeepsTheFollowup(): void
    {
        $this->activity('Task', 't1', 'Not Started');
        $deal = $this->entity('Opportunity', ['nextActionId' => 't1', 'nextActionType' => 'Task', 'followupTimer' => null]);
        $deal->set(['nextActionId' => null, 'nextActionType' => null, 'followupTimer' => '2026-11-11 13:00:00']);
        $this->save($deal);
        $this->assertSame('2026-11-11 13:00:00', $deal->get('followupTimer'));
    }

    public function testUnrelatedSavesDoNotLookUpTheStep(): void
    {
        $this->activity('Task', 't1', 'Not Started');
        $deal = $this->entity('Opportunity', ['nextActionId' => 't1', 'nextActionType' => 'Task', 'followupTimer' => '2026-11-11 13:00:00']);
        $deal->set('status', 'Open');
        $this->save($deal);
        $this->assertSame('2026-11-11 13:00:00', $deal->get('followupTimer'));
        $this->assertSame(0, $this->lookups);
    }

    public function testAMissingOrUnsupportedStepIsIgnored(): void
    {
        $deal = $this->entity('Opportunity', ['nextActionId' => 'gone', 'nextActionType' => 'Task', 'followupTimer' => null]);
        $deal->set('followupTimer', '2026-11-11 13:00:00');
        $this->save($deal);
        $this->assertSame('2026-11-11 13:00:00', $deal->get('followupTimer'));
    }
}
