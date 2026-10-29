<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureJourney;

use Espo\Core\Acl\ScopeData;
use Espo\Core\AclManager;
use Espo\Entities\User;
use Espo\Modules\FeatureJourney\Classes\Acl\JourneyRecord\AccessChecker;
use Espo\Modules\Global\Rebuild\SeedRole;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class JourneyRecordAccessCheckerTest extends TestCase
{
    private AclManager $aclManager;
    private EntityManager $entityManager;
    private User $user;
    private AccessChecker $checker;

    protected function setUp(): void
    {
        $this->aclManager = $this->createMock(AclManager::class);
        $this->entityManager = $this->createMock(EntityManager::class);
        $this->user = $this->createMock(User::class);
        $this->checker = new AccessChecker($this->aclManager, $this->entityManager);
    }

    private function data(string $create = 'yes'): ScopeData
    {
        return ScopeData::fromRaw((object) [
            'create' => $create,
            'read' => 'team',
            'edit' => 'no',
            'delete' => 'no',
        ]);
    }

    public function testSeededTenantRolesCanEnrollWithoutGrantingJourneyAuthoringAccess(): void
    {
        $seed = (new ReflectionClass(SeedRole::class))->newInstanceWithoutConstructor();
        $definitions = (new ReflectionMethod(SeedRole::class, 'getRoleDefinitions'))->invoke($seed);
        $roles = array_column($definitions, null, 'staticId');

        foreach (['tenant', 'tenant-admin', 'tenant-user-api'] as $id) {
            $role = $roles[$id];
            self::assertSame('yes', $role['data']['JourneyRecord']['create'], $id);
            self::assertSame('team', $role['data']['JourneyRecord']['read'], $id);
            self::assertSame('no', $role['data']['JourneyRecord']['delete'], $id);
            self::assertSame($id === 'tenant-admin' ? 'team' : 'no', $role['data']['JourneyRecord']['edit'], $id);

            foreach (['Journey', 'JourneyStage', 'JourneyStageAction', 'JourneyTransition'] as $scope) {
                self::assertSame($id === 'tenant-admin' ? 'yes' : 'no', $role['data'][$scope]['create'], "$id:$scope");
                self::assertSame('team', $role['data'][$scope]['read'], "$id:$scope");
                foreach (['edit', 'delete'] as $action) {
                    self::assertSame($id === 'tenant-admin' ? 'team' : 'no', $role['data'][$scope][$action], "$id:$scope:$action");
                }
            }

            foreach (['create', 'edit', 'delete'] as $action) {
                self::assertSame('no', $role['data']['JourneyRecordLog'][$action], "$id:JourneyRecordLog:$action");
            }

            $manager = $this->createMock(AclManager::class);
            $manager->method('checkScope')->willReturnCallback(
                fn (User $user, string $scope, string $action): bool =>
                    ($role['data'][$scope][$action] ?? 'no') !== 'no'
            );
            $checker = new AccessChecker($manager, $this->entityManager);
            self::assertTrue($checker->checkCreate($this->user, ScopeData::fromRaw((object) $role['data']['JourneyRecord'])), $id);
        }
    }

    public function testEnrollmentScopeRequiresJourneyReadAccessNotEditAccess(): void
    {
        $this->aclManager->expects($this->once())->method('checkScope')
            ->with($this->user, 'Journey', 'read')->willReturn(true);

        self::assertTrue($this->checker->checkCreate($this->user, $this->data()));
    }

    public function testEnrollmentScopeHonorsDeniedCreateAccess(): void
    {
        $this->aclManager->expects($this->never())->method('checkScope');

        self::assertFalse($this->checker->checkCreate($this->user, $this->data('no')));
    }

    public function testEnrollmentScopeRejectsUnreadableJourneys(): void
    {
        $this->aclManager->expects($this->once())->method('checkScope')
            ->with($this->user, 'Journey', 'read')->willReturn(false);

        self::assertFalse($this->checker->checkCreate($this->user, $this->data()));
    }

    public function testBooleanGrantStillRequiresJourneyReadAccess(): void
    {
        $this->aclManager->expects($this->exactly(2))->method('checkScope')
            ->with($this->user, 'Journey', 'read')->willReturn(true, false);

        self::assertTrue($this->checker->checkCreate($this->user, ScopeData::fromRaw(true)));
        self::assertFalse($this->checker->checkCreate($this->user, ScopeData::fromRaw(true)));
    }

    public function testDisabledEnrollmentScopeIsDenied(): void
    {
        $this->aclManager->expects($this->never())->method('checkScope');

        self::assertFalse($this->checker->checkCreate($this->user, ScopeData::fromRaw(false)));
    }

    public function testDirectRecordCreationStillRequiresParentJourneyEditAccess(): void
    {
        $record = $this->createMock(Entity::class);
        $record->method('get')->with('journeyId')->willReturn('journey-a');
        $journey = $this->createMock(Entity::class);
        $this->entityManager->expects($this->once())->method('getEntityById')
            ->with('Journey', 'journey-a')->willReturn($journey);
        $this->aclManager->expects($this->once())->method('checkEntity')
            ->with($this->user, $journey, 'edit')->willReturn(false);

        self::assertFalse($this->checker->checkEntityCreate($this->user, $record, $this->data()));
    }

    public function testDirectRecordCreationRejectsMissingParentJourney(): void
    {
        $record = $this->createMock(Entity::class);
        $record->method('get')->with('journeyId')->willReturn('missing');
        $this->entityManager->method('getEntityById')->willReturn(null);
        $this->aclManager->expects($this->never())->method('checkEntity');

        self::assertFalse($this->checker->checkEntityCreate($this->user, $record, $this->data()));
    }

    public function testEnrollmentGrantDoesNotAllowEditingOrDeletingRecords(): void
    {
        $this->aclManager->expects($this->exactly(2))->method('checkScope')
            ->with($this->user, 'Journey', 'edit')->willReturn(false);

        self::assertFalse($this->checker->checkEdit($this->user, $this->data()));
        self::assertFalse($this->checker->checkDelete($this->user, $this->data()));
    }
}
