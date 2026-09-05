<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureSimpleJourney;

use Espo\Core\ApplicationState;
use Espo\Entities\User;
use Espo\Modules\FeatureSimpleJourney\Services\Defaults;
use Espo\Modules\FeatureSimpleJourney\Services\JourneyAccess;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\EntityManager;

class DefaultsTest extends TestCase
{
    private function defaults(array $tenantIds, ?string $defaultTeam = null, bool $logged = true): Defaults
    {
        $user = $this->createMock(User::class);
        $user->method('get')->willReturnCallback(fn ($field) => $field === 'defaultTeamId' ? $defaultTeam : null);
        $state = $this->createMock(ApplicationState::class);
        $state->method('isLogged')->willReturn($logged);
        $state->method('getUser')->willReturn($user);
        $users = $this->createMock(UserTenantResolver::class);
        $users->method('resolveTenantIds')->willReturn($tenantIds);
        $teams = $this->createMock(TenantResolver::class);
        $teams->method('resolveUniqueFromTeamIds')->willReturnCallback(
            fn ($ids) => $ids === ['team-1'] ? 'tenant-1' : null,
        );
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($this->entity('Tenant', [
            'id' => 'tenant-1', 'name' => 'Workspace 1', 'baseUserTeamId' => 'team-1',
        ]));

        return new Defaults($state, $users, $teams, $em, $this->createMock(JourneyAccess::class));
    }

    public function testPrefillsLoggedInUserTenantAndBaseTeam(): void
    {
        $journey = $this->entity('SimpleJourney');
        $this->defaults(['tenant-1'])->process($journey);
        $this->assertSame('tenant-1', $journey->get('tenantId'));
        $this->assertSame('Workspace 1', $journey->get('tenantName'));
        $this->assertSame(['team-1'], $journey->get('teamsIds'));
    }

    public function testDoesNotGuessAmongMultipleTenantsWithoutDefaultTeam(): void
    {
        $journey = $this->entity('SimpleJourney');
        $this->defaults(['tenant-1', 'tenant-2'])->process($journey);
        $this->assertNull($journey->get('tenantId'));
    }

    public function testDefaultTeamDisambiguatesLoggedInUserTenants(): void
    {
        $journey = $this->entity('SimpleJourney');
        $this->defaults(['tenant-1', 'tenant-2'], 'team-1')->process($journey);
        $this->assertSame('tenant-1', $journey->get('tenantId'));
    }

    public function testExplicitTenantAndTeamsAreNotOverwritten(): void
    {
        $journey = $this->entity('SimpleJourney', ['tenantId' => 'tenant-2', 'teamsIds' => ['team-2']]);
        $this->defaults(['tenant-1'])->process($journey);
        $this->assertSame('tenant-2', $journey->get('tenantId'));
        $this->assertSame(['team-2'], $journey->get('teamsIds'));
    }

    public function testDoesNotReplaceExplicitlyEmptyTeams(): void
    {
        $journey = $this->entity('SimpleJourney', ['teamsIds' => []]);
        $this->defaults(['tenant-1'])->process($journey);
        $this->assertSame([], $journey->get('teamsIds'));
    }

    public function testBackgroundCreatesCanDeriveTenantFromExplicitTeams(): void
    {
        $journey = $this->entity('SimpleJourney', ['teamsIds' => ['team-1']]);
        $this->defaults([], null, false)->process($journey);
        $this->assertSame('tenant-1', $journey->get('tenantId'));
    }

    public function testExistingRecordsAreNotDefaultedAgain(): void
    {
        $journey = $this->entity('SimpleJourney', [], true);
        $this->defaults(['tenant-1'])->process($journey);
        $this->assertNull($journey->get('tenantId'));
    }
}
