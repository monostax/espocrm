<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureSimpleJourney;

use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureSimpleJourney\Hooks\SimpleJourney\ValidateOwnership;
use Espo\Modules\Global\Services\TeamTenantAccess;
use Espo\ORM\Repository\Option\SaveOptions;

class ValidateOwnershipTest extends TestCase
{
    private function access(): TeamTenantAccess
    {
        $access = $this->createMock(TeamTenantAccess::class);
        $access->method('resolveTeamIds')->willReturn(['team-1']);
        $access->method('deriveTenantId')->willReturn('tenant-1');
        $access->method('tenantIdsForTeams')->willReturn(['tenant-1' => true]);

        return $access;
    }

    public function testDerivesTenantAndChecksActorAuthorization(): void
    {
        $access = $this->access();
        $access->expects($this->once())->method('assertCanAssignTeams')->with(['team-1'], 'tenant-1', 'simple journey');
        $journey = $this->entity('SimpleJourney', ['teamsIds' => ['team-1']]);
        (new ValidateOwnership($access))->beforeSave($journey, SaveOptions::fromAssoc([]));
        $this->assertSame('tenant-1', $journey->get('tenantId'));
    }

    public function testRejectsForgedTenantEvenOnCreate(): void
    {
        $journey = $this->entity('SimpleJourney', ['tenantId' => 'tenant-2', 'teamsIds' => ['team-1']]);
        $this->expectException(BadRequest::class);
        (new ValidateOwnership($this->access()))->beforeSave($journey, SaveOptions::fromAssoc([]));
    }

    public function testCannotMoveJourneyToAnotherTenant(): void
    {
        $journey = $this->entity('SimpleJourney', ['tenantId' => 'tenant-2', 'teamsIds' => ['team-2']], true);
        $journey->set(['teamsIds' => ['team-1'], 'tenantId' => 'tenant-1']);
        $this->expectException(BadRequest::class);
        (new ValidateOwnership($this->access()))->beforeSave($journey, SaveOptions::fromAssoc([]));
    }

    public function testCannotClearTeamsOnExistingJourney(): void
    {
        $journey = $this->entity('SimpleJourney', ['tenantId' => 'tenant-1', 'teamsIds' => ['team-1']], true);
        $journey->set('teamsIds', []);
        $this->expectException(BadRequest::class);
        (new ValidateOwnership($this->access()))->beforeSave($journey, SaveOptions::fromAssoc([]));
    }

    public function testRejectsUnownedTeamAlongsideValidTeam(): void
    {
        $access = $this->createMock(TeamTenantAccess::class);
        $access->method('resolveTeamIds')->willReturn(['team-1', 'unowned']);
        $access->method('deriveTenantId')->willReturn('tenant-1');
        $access->method('tenantIdsForTeams')->willReturnCallback(
            fn ($ids) => $ids === ['team-1'] ? ['tenant-1' => true] : [],
        );
        $this->expectException(BadRequest::class);
        (new ValidateOwnership($access))->beforeSave($this->entity('SimpleJourney'), SaveOptions::fromAssoc([]));
    }
}
