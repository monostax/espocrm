<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureJourney\Hooks;

use Espo\Modules\FeatureJourney\Hooks\JourneyRecord\RemoveFromManualAudienceOnExit;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\ORM\Repository\RDBRelation;
use Espo\ORM\Repository\RDBRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RemoveFromManualAudienceOnExitTest extends TestCase
{
    public static function exitCases(): array
    {
        return [
            'manual opportunity exit' => ['Opportunity', 'manualOpportunities', 'manual'],
            'automatic Exit stage' => ['Opportunity', 'manualOpportunities', 'exit-stage'],
            'automatic SLA exit' => ['Opportunity', 'manualOpportunities', 'max-duration-sla'],
            'manual contact exit' => ['Contact', 'manualContacts', 'manual'],
        ];
    }

    #[DataProvider('exitCases')]
    public function testExitRemovesOnlyThisJourneysManualMembership(
        string $targetType,
        string $relationName,
        string $reason,
    ): void {
        $record = $this->record('Exited', true, $targetType, $reason);
        $journey = $this->createMock(Entity::class);
        $relation = $this->createMock(RDBRelation::class);
        $relation->expects($this->once())->method('unrelateById')->with('target1');
        $repository = $this->createMock(RDBRepository::class);
        $repository->expects($this->once())->method('getRelation')->with($journey, $relationName)
            ->willReturn($relation);
        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->expects($this->once())->method('getEntityById')->with('Journey', 'journey1')
            ->willReturn($journey);
        $entityManager->expects($this->once())->method('getRDBRepository')->with('Journey')
            ->willReturn($repository);

        (new RemoveFromManualAudienceOnExit($entityManager))->afterSave(
            $record,
            SaveOptions::fromAssoc(['silent' => true, 'skipJourneyDispatch' => true]),
        );
    }

    public static function ignoredCases(): array
    {
        return [
            'active enrollment' => ['Active', true, 'Opportunity'],
            'paused enrollment' => ['Paused', true, 'Opportunity'],
            'processing enrollment' => ['Processing', true, 'Opportunity'],
            'failed enrollment' => ['Failed', true, 'Opportunity'],
            'completed enrollment' => ['Completed', true, 'Opportunity'],
            'editing an old exit after audience re-add' => ['Exited', false, 'Opportunity'],
            'shared lead audience' => ['Exited', true, 'Lead'],
            'shared account audience' => ['Exited', true, 'Account'],
        ];
    }

    #[DataProvider('ignoredCases')]
    public function testUnrelatedSavesDoNotRemoveAudience(string $status, bool $changed, string $targetType): void
    {
        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->expects($this->never())->method('getEntityById');
        $entityManager->expects($this->never())->method('getRDBRepository');

        (new RemoveFromManualAudienceOnExit($entityManager))->afterSave(
            $this->record($status, $changed, $targetType),
            SaveOptions::fromAssoc([]),
        );
    }

    public function testMissingJourneyIsIgnored(): void
    {
        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getEntityById')->willReturn(null);
        $entityManager->expects($this->never())->method('getRDBRepository');

        (new RemoveFromManualAudienceOnExit($entityManager))->afterSave(
            $this->record('Exited', true, 'Opportunity'),
            SaveOptions::fromAssoc([]),
        );
    }

    private function record(string $status, bool $changed, string $targetType, string $reason = 'manual'): Entity
    {
        $record = $this->createMock(Entity::class);
        $record->method('get')->willReturnMap([
            ['status', $status],
            ['targetType', $targetType],
            ['journeyId', 'journey1'],
            ['targetId', 'target1'],
            ['exitReason', $reason],
        ]);
        $record->method('isAttributeChanged')->with('status')->willReturn($changed);

        return $record;
    }
}
