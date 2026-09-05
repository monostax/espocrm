<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureSimpleJourney;

use Espo\Core\Acl;
use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\AclManager;
use Espo\Entities\User;
use Espo\Modules\FeatureSimpleJourney\Classes\Acl\AccessChecker;
use Espo\Modules\FeatureSimpleJourney\Classes\Acl\OwnershipChecker;
use Espo\Modules\FeatureSimpleJourney\Classes\Select\AccessibleJourney;
use Espo\Modules\Global\Tools\Acl\TeamsAccess;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\SelectBuilder;

class AccessTest extends TestCase
{
    public function testAllRoleStillCannotReadAnotherTeamsJourney(): void
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
            $this->entity('SimpleJourney', ['tenantId' => 'tenant-1']),
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
            $this->createMock(User::class), $this->entity('SimpleJourney'), ScopeData::fromRaw(false),
        ));
    }

    public function testRecordOperatorsNeedReadNotEditPermissionOnConfiguration(): void
    {
        $base = $this->createMock(DefaultAccessChecker::class);
        $base->method('checkEntityEdit')->willReturn(true);
        $journey = $this->entity('SimpleJourney', ['id' => 'journey-1']);
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($journey);
        $manager = $this->createMock(AclManager::class);
        $user = $this->createMock(User::class);
        $manager->expects($this->once())->method('checkEntity')->with($user, $journey, 'read')->willReturn(true);
        $checker = new AccessChecker($base, $this->createMock(TeamsAccess::class), $em, $manager);
        $this->assertTrue($checker->checkEntityEdit(
            $user, $this->entity('SimpleJourneyRecord', ['journeyId' => 'journey-1']),
            ScopeData::fromRaw((object) ['edit' => 'team']),
        ));
    }

    public function testChildTeamOwnershipIsResolvedFromLiveJourney(): void
    {
        $journey = $this->entity('SimpleJourney', ['id' => 'journey-1', 'tenantId' => 'tenant-1']);
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($journey);
        $teams = $this->createMock(TeamsAccess::class);
        $user = $this->createMock(User::class);
        $teams->expects($this->exactly(2))->method('userSharesTeam')->with($user, $journey)->willReturn(true, false);
        $checker = new OwnershipChecker($em, $teams);
        $record = $this->entity('SimpleJourneyRecord', ['journeyId' => 'journey-1']);
        $this->assertTrue($checker->checkTeam($user, $record));
        $this->assertFalse($checker->checkTeam($user, $record));
    }

    public function testMissingJourneyFailsClosed(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn(null);
        $checker = new OwnershipChecker($em, $this->createMock(TeamsAccess::class));
        $this->assertFalse($checker->checkTeam(
            $this->createMock(User::class), $this->entity('SimpleJourneyStage', ['journeyId' => 'missing']),
        ));
    }

    public function testMandatoryListFilterScopesByJourneyTeams(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getTeamIdList')->willReturn(['team-1']);
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturn(true);
        $query = SelectBuilder::create()->from('SimpleJourneyRecord');
        (new AccessibleJourney('SimpleJourneyRecord', $user, $acl))->apply($query);
        $raw = $query->build()->getRaw();
        $this->assertArrayHasKey('journeyId=s', $raw['whereClause']);
        $journeyQuery = $raw['whereClause']['journeyId=s']->getRaw();
        $this->assertSame('SimpleJourney', $journeyQuery['from']);
        $this->assertFalse($journeyQuery['whereClause']['deleted']);
        $teamQuery = $journeyQuery['whereClause']['id=s']->getRaw();
        $this->assertSame(['team-1'], $teamQuery['whereClause']['teamId']);
        $this->assertSame('SimpleJourney', $teamQuery['whereClause']['entityType']);
        $this->assertFalse($teamQuery['whereClause']['deleted']);
    }

    public function testUsersWithoutTeamsGetNoListResults(): void
    {
        $query = SelectBuilder::create()->from('SimpleJourneyStage');
        (new AccessibleJourney('SimpleJourneyStage', $this->createMock(User::class), $this->createMock(Acl::class)))
            ->apply($query);
        $this->assertSame(['id' => null], $query->build()->getRaw()['whereClause']);
    }

    public function testInstanceAdminHasNoMandatoryRestriction(): void
    {
        $user = $this->createMock(User::class);
        $user->method('isAdmin')->willReturn(true);
        $query = SelectBuilder::create()->from('SimpleJourney');
        (new AccessibleJourney('SimpleJourney', $user, $this->createMock(Acl::class)))->apply($query);
        $this->assertNull($query->build()->getWhere());
    }
}
