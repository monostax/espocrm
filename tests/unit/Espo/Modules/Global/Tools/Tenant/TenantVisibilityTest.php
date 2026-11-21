<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Tools\Tenant;

use Espo\Entities\User;
use Espo\Modules\Global\Tools\Tenant\TenantVisibility;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Monostax staff belong to many tenants. A tenant user who shares one team
 * with them must not see their other teams (other customers' names), and the
 * ACL layer must not open their feed or calendar. A team owned by no tenant
 * names no foreign workspace and must not hide anyone.
 *
 * World used below:
 *   tenant nowle = base team-nowle, other team-nowle-sales
 *   tenant ifv   = base team-ifv
 *   team-orphan  = owned by no tenant
 */
class TenantVisibilityTest extends TestCase
{
    private const TENANT_TEAMS = [
        'nowle' => ['team-nowle', 'team-nowle-sales'],
        'ifv' => ['team-ifv'],
    ];

    /** @var array<string, list<string>> user id => tenant ids */
    private array $tenantsByUser = [];

    private int $tenantTeamQueries = 0;

    private int $worldQueries = 0;

    /** @var array<string, array<string, mixed>> user id => attribute values */
    private array $attrs = [];

    private function visibility(): TenantVisibility
    {
        $resolver = $this->createMock(UserTenantResolver::class);
        $resolver->method('resolveTenantIds')->willReturnCallback(
            fn (User $user) => $this->tenantsByUser[$user->getId()] ?? [],
        );

        $test = $this;

        return new class($this->createMock(EntityManager::class), $resolver, $test) extends TenantVisibility {
            public function __construct(EntityManager $entityManager, UserTenantResolver $resolver, private TenantVisibilityTest $test)
            {
                parent::__construct($entityManager, $resolver);
            }

            protected function teamIdsOfTenants(array $tenantIds): array
            {
                return $this->test->teamsOf($tenantIds);
            }

            protected function tenantOwnedTeamIds(array $teamIds): array
            {
                return $this->test->owned($teamIds);
            }
        };
    }

    /** @internal */
    public function teamsOf(array $tenantIds): array
    {
        $this->worldQueries++;
        $ids = [];

        foreach ($tenantIds as $tenantId) {
            array_push($ids, ...(self::TENANT_TEAMS[$tenantId] ?? []));
        }

        return $ids;
    }

    /** @internal */
    public function owned(array $teamIds): array
    {
        $this->tenantTeamQueries++;
        $all = array_merge(...array_values(self::TENANT_TEAMS));

        return array_values(array_intersect($teamIds, $all));
    }

    /**
     * @param list<string> $teamIds
     * @param list<string> $tenantIds
     * @param array<string, mixed> $attributes
     */
    private function user(string $id, array $teamIds, array $tenantIds = [], bool $admin = false, array $attributes = []): User
    {
        $this->tenantsByUser[$id] = $tenantIds;

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('isAdmin')->willReturn($admin);
        $user->method('getTeamIdList')->willReturn($teamIds);

        $this->attrs[$id] = $attributes;
        $user->method('has')->willReturnCallback(fn (string $name) => array_key_exists($name, $this->attrs[$id]));
        $user->method('get')->willReturnCallback(fn (string $name) => $this->attrs[$id][$name] ?? null);
        $user->method('set')->willReturnCallback(function ($name, $value = null) use ($id, $user) {
            $this->attrs[$id][$name] = $value;

            return $user;
        });

        return $user;
    }

    public function testTenantUserDoesNotCoverStaffMemberOfAnotherTenant(): void
    {
        $viewer = $this->user('nowle-user', ['team-nowle'], ['nowle']);
        $staff = $this->user('staff', ['team-nowle', 'team-ifv'], ['nowle', 'ifv']);

        $this->assertFalse($this->visibility()->covers($viewer, $staff));
    }

    public function testStaffCoversUserWhoseOnlyTenantTheyShare(): void
    {
        $staff = $this->user('staff', ['team-nowle', 'team-ifv'], ['nowle', 'ifv']);
        $colleague = $this->user('nowle-user', ['team-nowle', 'team-nowle-sales'], ['nowle']);

        $this->assertTrue($this->visibility()->covers($staff, $colleague));
    }

    public function testSameTenantTeamsTheViewerIsNotInStayVisible(): void
    {
        $viewer = $this->user('nowle-user', ['team-nowle'], ['nowle']);
        $colleague = $this->user('nowle-seller', ['team-nowle', 'team-nowle-sales'], ['nowle']);

        $this->assertTrue($this->visibility()->covers($viewer, $colleague));
    }

    public function testTeamOwnedByNoTenantDoesNotHideAColleague(): void
    {
        $viewer = $this->user('nowle-user', ['team-nowle'], ['nowle']);
        $colleague = $this->user('nowle-admin', ['team-nowle', 'team-orphan'], ['nowle']);

        $this->assertTrue($this->visibility()->covers($viewer, $colleague));
    }

    public function testAdminAndSelfAlwaysCovered(): void
    {
        $admin = $this->user('admin', [], [], true);
        $staff = $this->user('staff', ['team-nowle', 'team-ifv'], ['nowle', 'ifv']);

        $visibility = $this->visibility();

        $this->assertTrue($visibility->covers($admin, $staff));
        $this->assertTrue($visibility->covers($staff, $staff));
    }

    public function testOutputHidesEveryTeamAndDefaultTeamOfUncoveredUser(): void
    {
        $viewer = $this->user('nowle-user', ['team-nowle'], ['nowle']);
        $staff = $this->user('staff', ['team-nowle', 'team-ifv'], ['nowle', 'ifv'], false, [
            'teamsIds' => ['team-nowle', 'team-ifv'],
            'teamsNames' => (object) ['team-nowle' => 'Nowle', 'team-ifv' => 'Instituto Ferraz Veloso'],
            'teamsColumns' => (object) ['team-nowle' => (object) ['role' => null], 'team-ifv' => (object) ['role' => null]],
            'defaultTeamId' => 'team-ifv',
            'defaultTeamName' => 'Instituto Ferraz Veloso',
        ]);

        $this->visibility()->hideTeamsFromOutput($viewer, $staff);

        $this->assertSame([], $this->attrs['staff']['teamsIds']);
        $this->assertEquals(new stdClass(), $this->attrs['staff']['teamsNames']);
        $this->assertEquals(new stdClass(), $this->attrs['staff']['teamsColumns']);
        $this->assertNull($this->attrs['staff']['defaultTeamId']);
        $this->assertNull($this->attrs['staff']['defaultTeamName']);
    }

    public function testOutputHidesForeignDefaultTeamWhenTeamsWereNotLoaded(): void
    {
        $viewer = $this->user('nowle-user', ['team-nowle'], ['nowle']);
        $staff = $this->user('staff', ['team-nowle', 'team-ifv'], ['nowle', 'ifv'], false, [
            'defaultTeamId' => 'team-ifv',
            'defaultTeamName' => 'Instituto Ferraz Veloso',
        ]);

        $this->visibility()->hideTeamsFromOutput($viewer, $staff);

        $this->assertNull($this->attrs['staff']['defaultTeamName']);
        $this->assertArrayNotHasKey('teamsIds', $this->attrs['staff']);
    }

    public function testOutputLeavesCoveredUserUntouched(): void
    {
        $viewer = $this->user('nowle-user', ['team-nowle'], ['nowle']);
        $names = (object) ['team-nowle' => 'Nowle', 'team-nowle-sales' => 'Nowle / Vendas'];
        $colleague = $this->user('nowle-seller', ['team-nowle', 'team-nowle-sales'], ['nowle'], false, [
            'teamsIds' => ['team-nowle', 'team-nowle-sales'],
            'teamsNames' => $names,
        ]);

        $this->visibility()->hideTeamsFromOutput($viewer, $colleague);

        $this->assertSame(['team-nowle', 'team-nowle-sales'], $this->attrs['nowle-seller']['teamsIds']);
        $this->assertSame($names, $this->attrs['nowle-seller']['teamsNames']);
    }

    public function testOwnProfileIsNeverFiltered(): void
    {
        $staff = $this->user('staff', ['team-nowle', 'team-ifv'], ['nowle', 'ifv'], false, [
            'teamsIds' => ['team-nowle', 'team-ifv'],
        ]);

        $this->visibility()->hideTeamsFromOutput($staff, $staff);

        $this->assertSame(['team-nowle', 'team-ifv'], $this->attrs['staff']['teamsIds']);
    }

    public function testHiddenTeamsAreAllStoredTeamsOfUncoveredUser(): void
    {
        $viewer = $this->user('nowle-user', ['team-nowle'], ['nowle']);

        $visibility = $this->visibility();

        $this->assertSame(
            ['team-nowle', 'team-ifv'],
            $visibility->teamsHiddenFrom($viewer, 'staff', ['team-nowle', 'team-ifv']),
        );
        $this->assertSame([], $visibility->teamsHiddenFrom($viewer, 'nowle-seller', ['team-nowle', 'team-nowle-sales']));
    }

    public function testColleagueInViewersOwnTeamsNeedsNoLookup(): void
    {
        $viewer = $this->user('nowle-user', ['team-nowle', 'team-nowle-sales'], ['nowle']);
        $colleague = $this->user('nowle-seller', ['team-nowle'], ['nowle']);

        $this->assertTrue($this->visibility()->covers($viewer, $colleague));
        $this->assertSame(0, $this->worldQueries);
        $this->assertSame(0, $this->tenantTeamQueries);
    }

    public function testTenantOwnershipIsLookedUpOncePerTeam(): void
    {
        $viewer = $this->user('nowle-user', ['team-nowle'], ['nowle']);
        $staff = $this->user('staff', ['team-nowle', 'team-ifv'], ['nowle', 'ifv']);

        $visibility = $this->visibility();

        $visibility->covers($viewer, $staff);
        $visibility->covers($viewer, $staff);

        $this->assertSame(1, $this->tenantTeamQueries);
    }
}
