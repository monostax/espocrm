<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureInitiative;

use Espo\Core\Acl;
use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\AclManager;
use Espo\Core\Select\SelectBuilder as RecordSelectBuilder;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\Modules\FeatureInitiative\Classes\Acl\AccessChecker;
use Espo\Modules\FeatureInitiative\Classes\Acl\OwnershipChecker;
use Espo\Modules\FeatureInitiative\Classes\Select\AccessibleInitiativeType;
use Espo\Modules\FeatureInitiative\Classes\Select\AccessibleInitiative;
use Espo\Modules\Global\Tools\Acl\TeamsAccess;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\SelectBuilder;

class AccessTest extends TestCase
{
    public function testAllRoleStillCannotReadAnotherTeamsInitiativeType(): void
    {
        $base = $this->createMock(DefaultAccessChecker::class);
        $base->method('checkEntityRead')->willReturn(true);
        $teams = $this->createMock(TeamsAccess::class);
        $teams->method('userSharesTeam')->willReturn(false);
        $checker = new AccessChecker(
            $base, $teams, $this->createMock(EntityManager::class), $this->createMock(AclManager::class),
        );
        $this->assertFalse($checker->checkEntityRead(
            $this->createMock(User::class),
            $this->entity('InitiativeType', ['tenantId' => 'tenant-1']),
            ScopeData::fromRaw((object) ['read' => 'all']),
        ));
    }

    public function testTeamMembershipDoesNotOverrideDeniedScope(): void
    {
        $base = $this->createMock(DefaultAccessChecker::class);
        $base->method('checkEntityRead')->willReturn(false);
        $teams = $this->createMock(TeamsAccess::class);
        $teams->expects($this->never())->method('userSharesTeam');
        $checker = new AccessChecker(
            $base, $teams, $this->createMock(EntityManager::class), $this->createMock(AclManager::class),
        );
        $this->assertFalse($checker->checkEntityRead(
            $this->createMock(User::class), $this->entity('InitiativeType'), ScopeData::fromRaw(false),
        ));
    }

    public function testRecordOperatorsNeedReadNotEditPermissionOnConfiguration(): void
    {
        $base = $this->createMock(DefaultAccessChecker::class);
        $base->method('checkEntityEdit')->willReturn(true);
        $type = $this->entity('InitiativeType', ['id' => 'type-1']);
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($type);
        $manager = $this->createMock(AclManager::class);
        $user = $this->createMock(User::class);
        $manager->expects($this->once())->method('checkEntity')->with($user, $type, 'read')->willReturn(true);
        $checker = new AccessChecker($base, $this->createMock(TeamsAccess::class), $em, $manager);
        $this->assertTrue($checker->checkEntityEdit(
            $user, $this->entity('Initiative', ['initiativeTypeId' => 'type-1']),
            ScopeData::fromRaw((object) ['edit' => 'team']),
        ));
    }

    public function testChildTeamOwnershipIsResolvedFromLiveInitiativeType(): void
    {
        $type = $this->entity('InitiativeType', ['id' => 'type-1', 'tenantId' => 'tenant-1']);
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($type);
        $teams = $this->createMock(TeamsAccess::class);
        $user = $this->createMock(User::class);
        $teams->expects($this->exactly(2))->method('userSharesTeam')->with($user, $type)->willReturn(true, false);
        $checker = new OwnershipChecker($em, $teams);
        $record = $this->entity('Initiative', ['initiativeTypeId' => 'type-1']);
        $this->assertTrue($checker->checkTeam($user, $record));
        $this->assertFalse($checker->checkTeam($user, $record));
    }

    public function testMissingInitiativeTypeFailsClosed(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn(null);
        $checker = new OwnershipChecker($em, $this->createMock(TeamsAccess::class));
        $this->assertFalse($checker->checkTeam(
            $this->createMock(User::class), $this->entity('InitiativeStage', ['initiativeTypeId' => 'missing']),
        ));
    }

    public function testMandatoryListFilterScopesByInitiativeTypeTeams(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getTeamIdList')->willReturn(['team-1']);
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturn(true);
        $query = SelectBuilder::create()->from('Initiative');
        (new AccessibleInitiativeType('Initiative', $user, $acl))->apply($query);
        $raw = $query->build()->getRaw();
        $this->assertArrayHasKey('initiativeTypeId=s', $raw['whereClause']);
        $typeQuery = $raw['whereClause']['initiativeTypeId=s']->getRaw();
        $this->assertSame('InitiativeType', $typeQuery['from']);
        $this->assertFalse($typeQuery['whereClause']['deleted']);
        $teamQuery = $typeQuery['whereClause']['id=s']->getRaw();
        $this->assertSame(['team-1'], $teamQuery['whereClause']['teamId']);
        $this->assertSame('InitiativeType', $teamQuery['whereClause']['entityType']);
        $this->assertFalse($teamQuery['whereClause']['deleted']);
    }

    public function testUsersWithoutTeamsGetNoListResults(): void
    {
        $query = SelectBuilder::create()->from('InitiativeStage');
        (new AccessibleInitiativeType('InitiativeStage', $this->createMock(User::class), $this->createMock(Acl::class)))
            ->apply($query);
        $this->assertSame(['id' => null], $query->build()->getRaw()['whereClause']);
    }

    public function testInstanceAdminHasNoMandatoryRestriction(): void
    {
        $user = $this->createMock(User::class);
        $user->method('isAdmin')->willReturn(true);
        $query = SelectBuilder::create()->from('InitiativeType');
        (new AccessibleInitiativeType('InitiativeType', $user, $this->createMock(Acl::class)))->apply($query);
        $this->assertNull($query->build()->getWhere());
    }

    public function testParentLinksListUsesStrictSourceRecordAcl(): void
    {
        $user = $this->createMock(User::class);
        $builder = $this->createMock(RecordSelectBuilder::class);
        $builder->expects($this->once())->method('from')->with('Initiative')->willReturnSelf();
        $builder->expects($this->once())->method('forUser')->with($user)->willReturnSelf();
        $builder->expects($this->once())->method('withStrictAccessControl')->willReturnSelf();
        $builder->method('buildQueryBuilder')->willReturn(
            SelectBuilder::create()->from('Initiative')->where(['id' => ['readable-record']]),
        );
        $factory = $this->createMock(SelectBuilderFactory::class);
        $factory->method('create')->willReturn($builder);
        $query = SelectBuilder::create()->from('InitiativeRelation');
        (new AccessibleInitiative($user, $factory))->apply($query);
        $sourceQuery = $query->build()->getRaw()['whereClause']['initiativeId=s']->getRaw();
        $this->assertSame(['readable-record'], $sourceQuery['whereClause']['id']);
        $this->assertSame(['id'], $sourceQuery['select']);
    }

    public function testDeletingParentLinkRequiresEditAccessToSourceRecord(): void
    {
        $base = $this->createMock(DefaultAccessChecker::class);
        $base->method('checkEntityDelete')->willReturn(true);
        $source = $this->entity('Initiative', ['id' => 'source']);
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->with('Initiative', 'source')->willReturn($source);
        $user = $this->createMock(User::class);
        $manager = $this->createMock(AclManager::class);
        $manager->expects($this->once())->method('checkEntity')->with($user, $source, 'edit')->willReturn(false);
        $checker = new AccessChecker($base, $this->createMock(TeamsAccess::class), $em, $manager);
        $this->assertFalse($checker->checkEntityDelete(
            $user, $this->entity('InitiativeRelation', ['initiativeId' => 'source']),
            ScopeData::fromRaw((object) ['delete' => 'all']),
        ));
    }
}
