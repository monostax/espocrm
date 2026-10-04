<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureInitiative;

use Espo\Core\Exceptions\BadRequest;
use Espo\Entities\User;
use Espo\Modules\FeatureInitiative\Hooks\Initiative\ValidateProgress;
use Espo\Modules\FeatureInitiative\Services\InitiativeTypeAccess;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\Attributes\DataProvider;

class ValidateProgressTest extends TestCase
{
    private BaseEntity $initiativeType;
    private BaseEntity $stage;
    private EntityManager $entityManager;
    private InitiativeTypeAccess $access;
    private UserTenantResolver $users;

    protected function setUp(): void
    {
        $this->initiativeType = $this->entity('InitiativeType', ['id' => 'type-1', 'tenantId' => 'tenant-1', 'isActive' => true]);
        $this->stage = $this->entity('InitiativeStage', ['id' => 'stage-1', 'initiativeTypeId' => 'type-1', 'isActive' => true, 'category' => 'Open']);
        $this->entityManager = $this->createMock(EntityManager::class);
        $this->access = $this->createMock(InitiativeTypeAccess::class);
        $this->access->method('requireParent')->willReturn($this->initiativeType);
        $this->users = $this->createMock(UserTenantResolver::class);
    }

    private function record(array $values = [], bool $existing = false): BaseEntity
    {
        return $this->entity('Initiative', array_merge([
            'initiativeTypeId' => 'type-1', 'stageId' => 'stage-1', 'status' => 'Open',
        ], $values), $existing);
    }

    private function save(BaseEntity $record): void
    {
        (new ValidateProgress($this->entityManager, $this->access, $this->users))
            ->beforeSave($record, SaveOptions::fromAssoc([]));
    }

    public static function statuses(): array
    {
        return array_map(fn ($status) => [$status], ['Open', 'In Progress', 'Paused', 'Completed', 'Canceled']);
    }

    #[DataProvider('statuses')]
    public function testStatusComesFromStageCategoryAndCannotBeOverridden(string $status): void
    {
        $this->stage->set('category', $status);
        $this->entityManager->method('getEntityById')->willReturn($this->stage);
        $record = $this->record([], true);
        $record->set('status', 'Manual override');
        $this->save($record);
        $this->assertSame($status, $record->get('status'));
        $this->assertSame('stage-1', $record->get('stageId'));
        $this->assertNull($this->stage->get('status'));
    }

    #[DataProvider('statuses')]
    public function testNewRecordDerivesStatusFromStage(string $status): void
    {
        $this->stage->set('category', $status);
        $this->entityManager->method('getEntityById')->willReturn($this->stage);
        $record = $this->record(['status' => null]);
        $this->save($record);
        $this->assertSame($status, $record->get('status'));
    }

    public function testStageMoveDerivesStatusEvenIfStatusWasExplicitlyUpdated(): void
    {
        $this->stage->set('category', 'In Progress');
        $this->entityManager->method('getEntityById')->willReturn($this->stage);
        $record = $this->record(['stageId' => 'old-stage', 'status' => 'Completed'], true);
        $record->set(['stageId' => 'stage-1', 'status' => 'Canceled']);
        $this->save($record);
        $this->assertSame('In Progress', $record->get('status'));
    }

    public function testUnrelatedUpdateRefreshesStaleStatusFromStage(): void
    {
        $this->entityManager->method('getEntityById')->willReturn($this->stage);
        $record = $this->record(['status' => 'Completed'], true);
        $record->set('name', 'Renamed');
        $this->save($record);
        $this->assertSame('Open', $record->get('status'));
    }

    public function testInvalidManualStatusIsReplacedByCategory(): void
    {
        $this->entityManager->method('getEntityById')->willReturn($this->stage);
        $record = $this->record(['status' => 'Invalid']);
        $this->save($record);
        $this->assertSame('Open', $record->get('status'));
    }

    public function testMissingStageIsRejected(): void
    {
        $this->entityManager->method('getEntityById')->willReturn(null);
        $this->expectException(BadRequest::class);
        $this->save($this->record());
    }

    public function testStageFromAnotherInitiativeTypeIsRejected(): void
    {
        $this->stage->set('initiativeTypeId', 'type-2');
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

    public function testInactiveInitiativeTypeRejectsEntry(): void
    {
        $this->initiativeType->set('isActive', false);
        $this->entityManager->method('getEntityById')->willReturn($this->stage);
        $this->expectException(BadRequest::class);
        $this->save($this->record());
    }

    public function testInactiveStageAndInitiativeTypeStillAllowExistingWork(): void
    {
        $this->initiativeType->set('isActive', false);
        $this->stage->set('isActive', false);
        $this->entityManager->method('getEntityById')->willReturn($this->stage);
        $record = $this->record([], true);
        $record->set('name', 'Renamed');
        $this->save($record);
        $this->assertSame('Open', $record->get('status'));
    }

    public function testForeignAssigneeIsRejected(): void
    {
        $user = $this->createMock(User::class);
        $this->entityManager->method('getEntityById')->willReturnMap([
            ['InitiativeStage', 'stage-1', $this->stage], ['User', 'user-1', $user],
        ]);
        $this->users->method('resolveTenantIds')->willReturn(['tenant-2']);
        $this->expectException(BadRequest::class);
        $this->save($this->record(['assignedUserId' => 'user-1']));
    }
}
