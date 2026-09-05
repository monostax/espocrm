<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureSimpleJourney;

use Espo\Core\Exceptions\BadRequest;
use Espo\Entities\User;
use Espo\Modules\FeatureSimpleJourney\Hooks\SimpleJourneyRecord\ValidateProgress;
use Espo\Modules\FeatureSimpleJourney\Services\JourneyAccess;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\Attributes\DataProvider;

class ValidateProgressTest extends TestCase
{
    private BaseEntity $journey;
    private BaseEntity $stage;
    private EntityManager $entityManager;
    private JourneyAccess $access;
    private UserTenantResolver $users;

    protected function setUp(): void
    {
        $this->journey = $this->entity('SimpleJourney', ['id' => 'journey-1', 'tenantId' => 'tenant-1', 'isActive' => true]);
        $this->stage = $this->entity('SimpleJourneyStage', ['id' => 'stage-1', 'journeyId' => 'journey-1', 'isActive' => true]);
        $this->entityManager = $this->createMock(EntityManager::class);
        $this->access = $this->createMock(JourneyAccess::class);
        $this->access->method('requireParent')->willReturn($this->journey);
        $this->users = $this->createMock(UserTenantResolver::class);
    }

    private function record(array $values = [], bool $existing = false): BaseEntity
    {
        return $this->entity('SimpleJourneyRecord', array_merge([
            'journeyId' => 'journey-1', 'stageId' => 'stage-1', 'status' => 'To Do',
        ], $values), $existing);
    }

    private function save(BaseEntity $record): void
    {
        (new ValidateProgress($this->entityManager, $this->access, $this->users))
            ->beforeSave($record, SaveOptions::fromAssoc([]));
    }

    public static function statuses(): array
    {
        return array_map(fn ($status) => [$status], ['On Hold', 'To Do', 'Doing', 'Done']);
    }

    #[DataProvider('statuses')]
    public function testStatusIsPerRecordAndDoesNotMoveStage(string $status): void
    {
        $this->entityManager->method('getEntityById')->willReturn($this->stage);
        $record = $this->record([], true);
        $record->set('status', $status);
        $this->save($record);
        $this->assertSame($status, $record->get('status'));
        $this->assertSame('stage-1', $record->get('stageId'));
        $this->assertNull($this->stage->get('status'));
    }

    public function testNewRecordDefaultsToToDo(): void
    {
        $this->entityManager->method('getEntityById')->willReturn($this->stage);
        $record = $this->record(['status' => null]);
        $this->save($record);
        $this->assertSame('To Do', $record->get('status'));
    }

    public function testStageMoveResetsStatusEvenIfStatusWasExplicitlyUpdated(): void
    {
        $this->entityManager->method('getEntityById')->willReturn($this->stage);
        $record = $this->record(['stageId' => 'old-stage', 'status' => 'Done'], true);
        $record->set(['stageId' => 'stage-1', 'status' => 'Doing']);
        $this->save($record);
        $this->assertSame('To Do', $record->get('status'));
    }

    public function testUnrelatedUpdatePreservesDone(): void
    {
        $this->entityManager->method('getEntityById')->willReturn($this->stage);
        $record = $this->record(['status' => 'Done'], true);
        $record->set('name', 'Renamed');
        $this->save($record);
        $this->assertSame('Done', $record->get('status'));
    }

    public function testInvalidStatusIsRejected(): void
    {
        $this->entityManager->method('getEntityById')->willReturn($this->stage);
        $this->expectException(BadRequest::class);
        $this->save($this->record(['status' => 'Completed']));
    }

    public function testMissingStageIsRejected(): void
    {
        $this->entityManager->method('getEntityById')->willReturn(null);
        $this->expectException(BadRequest::class);
        $this->save($this->record());
    }

    public function testStageFromAnotherJourneyIsRejected(): void
    {
        $this->stage->set('journeyId', 'journey-2');
        $this->entityManager->method('getEntityById')->willReturn($this->stage);
        $this->expectException(BadRequest::class);
        $this->save($this->record());
    }

    public function testInactiveStageRejectsEntry(): void
    {
        $this->stage->set('isActive', false);
        $this->entityManager->method('getEntityById')->willReturn($this->stage);
        $this->expectException(BadRequest::class);
        $this->save($this->record());
    }

    public function testInactiveJourneyRejectsEntry(): void
    {
        $this->journey->set('isActive', false);
        $this->entityManager->method('getEntityById')->willReturn($this->stage);
        $this->expectException(BadRequest::class);
        $this->save($this->record());
    }

    public function testArchivedStageAndJourneyStillAllowExistingWork(): void
    {
        $this->journey->set('isActive', false);
        $this->stage->set('isActive', false);
        $this->entityManager->method('getEntityById')->willReturn($this->stage);
        $record = $this->record([], true);
        $record->set('status', 'Done');
        $this->save($record);
        $this->assertSame('Done', $record->get('status'));
    }

    public function testForeignAssigneeIsRejected(): void
    {
        $user = $this->createMock(User::class);
        $this->entityManager->method('getEntityById')->willReturnMap([
            ['SimpleJourneyStage', 'stage-1', $this->stage], ['User', 'user-1', $user],
        ]);
        $this->users->method('resolveTenantIds')->willReturn(['tenant-2']);
        $this->expectException(BadRequest::class);
        $this->save($this->record(['assignedUserId' => 'user-1']));
    }
}
