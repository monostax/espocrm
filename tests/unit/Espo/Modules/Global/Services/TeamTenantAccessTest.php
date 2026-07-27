<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Services;

use Espo\Core\ApplicationState;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Utils\Log;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\Global\Services\TeamTenantAccess;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * `teams` is the only user-writable input that decides which tenant an
 * Automation/Journey runs as (AssignTenantFromTeam derives `tenantId` from it)
 * and which tenant's users can read the cascaded run/record rows. Assigning
 * another workspace's team must therefore be refused at save time.
 */
class TeamTenantAccessTest extends TestCase
{
    /**
     * @param array<string, list<string>> $baseTeamToTenants   team id => tenants owning it as base user team
     * @param array<string, list<string>> $otherTeamToTenants  team id => tenants owning it as other user team
     */
    private function makeAccess(
        User $actor,
        array $actorTenantIds,
        array $baseTeamToTenants = [],
        array $otherTeamToTenants = [],
        bool $isLogged = true,
        ?array $persistedTeams = null,
    ): TeamTenantAccess {
        $applicationState = $this->createMock(ApplicationState::class);
        $applicationState->method('isLogged')->willReturn($isLogged);
        $applicationState->method('getUser')->willReturn($actor);

        $userTenantResolver = $this->createMock(UserTenantResolver::class);
        $userTenantResolver->method('resolveTenantIds')->willReturn($actorTenantIds);

        $entityManager = $this->createMock(EntityManager::class);

        $entityManager->method('getEntityById')->willReturnCallback(
            function (string $entityType, string $id) use ($persistedTeams): ?Entity {
                // Non-Team lookups are the persisted-entity re-read in resolveTeamIds.
                if ($entityType !== Team::ENTITY_TYPE && $persistedTeams !== null) {
                    $stored = $this->createMock(CoreEntity::class);
                    $stored->method('getLinkMultipleIdList')->willReturn($persistedTeams);

                    return $stored;
                }

                return $this->entityStub($id);
            },
        );

        // Team -> tenant resolution now lives in TenantResolver (its own test covers
        // the base-user-team + other-user-teams SQL). Here it is stubbed from the
        // same fixture maps, so these cases keep asserting the authorisation logic.
        $tenantResolver = $this->createMock(TenantResolver::class);
        $tenantResolver->method('resolveAllFromTeamIds')->willReturnCallback(
            function (array $teamIds) use ($baseTeamToTenants, $otherTeamToTenants): array {
                $tenantIds = [];

                foreach ($teamIds as $teamId) {
                    foreach ($baseTeamToTenants[$teamId] ?? [] as $tenantId) {
                        $tenantIds[$tenantId] = true;
                    }

                    foreach ($otherTeamToTenants[$teamId] ?? [] as $tenantId) {
                        $tenantIds[$tenantId] = true;
                    }
                }

                return array_keys($tenantIds);
            },
        );

        return new TeamTenantAccess(
            $entityManager,
            $applicationState,
            $tenantResolver,
            $userTenantResolver,
            $this->createMock(Log::class),
        );
    }

    private function entityStub(string $id): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn($id);

        return $entity;
    }

    private function user(string $id, bool $isAdmin = false): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('isAdmin')->willReturn($isAdmin);

        return $user;
    }

    public function testForeignBaseTeamIsRejected(): void
    {
        // The core attack: naming tenant-b by borrowing its base user team.
        $access = $this->makeAccess(
            $this->user('tenant-admin-a'),
            actorTenantIds: ['tenant-a'],
            baseTeamToTenants: ['team-b' => ['tenant-b']],
        );

        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('another workspace');

        $access->assertCanAssignTeams(['team-b'], null, 'automation');
    }

    public function testForeignOtherUserTeamIsRejected(): void
    {
        // AssignTenantFromTeam only matches baseUserTeamId, so this team yields a
        // null tenant there — but it still exposes cascaded rows to tenant-b.
        $access = $this->makeAccess(
            $this->user('tenant-admin-a'),
            actorTenantIds: ['tenant-a'],
            otherTeamToTenants: ['team-b-sales' => ['tenant-b']],
        );

        $this->expectException(Forbidden::class);

        $access->assertCanAssignTeams(['team-b-sales'], null, 'journey');
    }

    public function testOwnTeamIsAllowed(): void
    {
        $access = $this->makeAccess(
            $this->user('tenant-admin-a'),
            actorTenantIds: ['tenant-a'],
            baseTeamToTenants: ['team-a' => ['tenant-a']],
        );

        $access->assertCanAssignTeams(['team-a'], 'tenant-a', 'automation');
        $this->addToAssertionCount(1);
    }

    public function testMixedOwnAndForeignTeamIsRejected(): void
    {
        // A valid own team must not launder a foreign one alongside it.
        $access = $this->makeAccess(
            $this->user('tenant-admin-a'),
            actorTenantIds: ['tenant-a'],
            baseTeamToTenants: ['team-a' => ['tenant-a'], 'team-b' => ['tenant-b']],
        );

        $this->expectException(Forbidden::class);

        $access->assertCanAssignTeams(['team-a', 'team-b'], 'tenant-a', 'automation');
    }

    public function testForeignEntityTenantIsRejectedEvenWithCleanTeams(): void
    {
        // tenantId is what the read predicates use; a stale foreign value must not
        // survive just because the teams on this save look harmless.
        $access = $this->makeAccess(
            $this->user('tenant-admin-a'),
            actorTenantIds: ['tenant-a'],
        );

        $this->expectException(Forbidden::class);

        $access->assertCanAssignTeams(['team-unowned'], 'tenant-b', 'automation');
    }

    public function testTeamWithoutAnyTenantIsAllowed(): void
    {
        // Single-tenant installs and functional teams resolve to no tenant. They
        // cannot name a foreign tenant, and the read paths already fail closed on
        // the resulting null tenantId.
        $access = $this->makeAccess(
            $this->user('user-a'),
            actorTenantIds: ['tenant-a'],
        );

        $access->assertCanAssignTeams(['functional-team'], null, 'journey');
        $this->addToAssertionCount(1);
    }

    public function testInstanceAdminMayAssignAnyTeam(): void
    {
        $access = $this->makeAccess(
            $this->user('admin-1', isAdmin: true),
            actorTenantIds: [],
            baseTeamToTenants: ['team-b' => ['tenant-b']],
        );

        $access->assertCanAssignTeams(['team-b'], 'tenant-b', 'automation');
        $this->addToAssertionCount(1);
    }

    public function testUnauthenticatedContextIsSkipped(): void
    {
        // Cron/CLI/rebuild have no actor to scope against and save with `silent`.
        $access = $this->makeAccess(
            $this->user('irrelevant'),
            actorTenantIds: [],
            baseTeamToTenants: ['team-b' => ['tenant-b']],
            isLogged: false,
        );

        $access->assertCanAssignTeams(['team-b'], 'tenant-b', 'automation');
        $this->addToAssertionCount(1);
    }

    public function testActorWithNoTenantCannotAssignATenantOwnedTeam(): void
    {
        $access = $this->makeAccess(
            $this->user('orphan-user'),
            actorTenantIds: [],
            baseTeamToTenants: ['team-b' => ['tenant-b']],
        );

        $this->expectException(Forbidden::class);

        $access->assertCanAssignTeams(['team-b'], null, 'automation');
    }

    public function testNoTeamsAndNoTenantIsANoOp(): void
    {
        $access = $this->makeAccess($this->user('user-a'), actorTenantIds: ['tenant-a']);

        $access->assertCanAssignTeams([], null, 'automation');
        $access->assertCanAssignTeams([], '', 'automation');
        $this->addToAssertionCount(1);
    }

    public function testResolveTeamIdsFallsBackToTeamsIdsAttribute(): void
    {
        $access = $this->makeAccess($this->user('user-a'), actorTenantIds: ['tenant-a']);

        $entity = $this->createMock(Entity::class);
        $entity->method('get')->with('teamsIds')->willReturn(['team-x', 'team-x', 'team-y']);

        $this->assertSame(['team-x', 'team-y'], $access->resolveTeamIds($entity));
    }

    /**
     * @param list<string> $teamIds
     */
    private function entityWithTeams(array $teamIds): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn('entity-1');
        $entity->method('get')->with('teamsIds')->willReturn($teamIds);

        return $entity;
    }

    public function testDeriveTenantIdFromBaseUserTeam(): void
    {
        $access = $this->makeAccess(
            $this->user('user-a'),
            actorTenantIds: ['tenant-a'],
            baseTeamToTenants: ['team-a' => ['tenant-a']],
        );

        $this->assertSame('tenant-a', $access->deriveTenantId($this->entityWithTeams(['team-a']), 'automation'));
    }

    public function testDeriveTenantIdFromOtherUserTeam(): void
    {
        // The regression this fixes: a tenant owns baseUserTeam AND otherUserTeams,
        // but derivation only matched the base team, so a legitimate other-user-team
        // assignment produced a null tenant and a permanently inert automation.
        $access = $this->makeAccess(
            $this->user('user-a'),
            actorTenantIds: ['tenant-a'],
            otherTeamToTenants: ['team-a-sales' => ['tenant-a']],
        );

        $this->assertSame(
            'tenant-a',
            $access->deriveTenantId($this->entityWithTeams(['team-a-sales']), 'automation'),
        );
    }

    public function testDeriveTenantIdRefusesAmbiguousTeams(): void
    {
        // A user belonging to two tenants can select teams from both. Persisting a
        // null tenant there would save a record that can never run.
        $access = $this->makeAccess(
            $this->user('user-ab'),
            actorTenantIds: ['tenant-a', 'tenant-b'],
            baseTeamToTenants: ['team-a' => ['tenant-a'], 'team-b' => ['tenant-b']],
        );

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('more than one workspace');

        $access->deriveTenantId($this->entityWithTeams(['team-a', 'team-b']), 'automation');
    }

    public function testDeriveTenantIdReturnsNullWithoutTeams(): void
    {
        $access = $this->makeAccess($this->user('user-a'), actorTenantIds: ['tenant-a']);

        $this->assertNull($access->deriveTenantId($this->entityWithTeams([]), 'automation'));
    }

    public function testDeriveTenantIdReturnsNullForTeamOutsideAnyTenant(): void
    {
        // Single-tenant installs have no Tenant rows to match; derivation stays null
        // rather than erroring.
        $access = $this->makeAccess($this->user('user-a'), actorTenantIds: []);

        $this->assertNull($access->deriveTenantId($this->entityWithTeams(['plain-team']), 'journey'));
    }

    public function testNonStrictAmbiguityReturnsNullInsteadOfThrowing(): void
    {
        // CustomFieldDef has a legitimate disambiguator (its linked group), so it
        // must be able to fall through rather than refuse the save.
        $access = $this->makeAccess(
            $this->user('user-ab'),
            actorTenantIds: ['tenant-a', 'tenant-b'],
            baseTeamToTenants: ['team-a' => ['tenant-a'], 'team-b' => ['tenant-b']],
        );

        $this->assertNull(
            $access->deriveTenantId(
                $this->entityWithTeams(['team-a', 'team-b']),
                'custom field',
                strictAmbiguity: false,
            ),
        );
    }

    public function testPersistedTeamsFallbackBackfillsTenantWhenPayloadOmitsTeams(): void
    {
        // Updates that do not touch `teams` still need to backfill a missing tenant,
        // which is why several modules re-read the stored teams.
        $access = $this->makeAccess(
            $this->user('user-a'),
            actorTenantIds: ['tenant-a'],
            baseTeamToTenants: ['stored-team' => ['tenant-a']],
            persistedTeams: ['stored-team'],
        );

        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn('entity-1');
        $entity->method('getEntityType')->willReturn('TrackingSource');
        $entity->method('isNew')->willReturn(false);
        $entity->method('hasId')->willReturn(true);
        $entity->method('get')->with('teamsIds')->willReturn([]);

        $this->assertSame(
            'tenant-a',
            $access->deriveTenantId($entity, 'tracking source', includePersistedTeams: true),
        );
    }

    public function testPersistedTeamsFallbackIsNotUsedUnlessRequested(): void
    {
        $access = $this->makeAccess(
            $this->user('user-a'),
            actorTenantIds: ['tenant-a'],
            baseTeamToTenants: ['stored-team' => ['tenant-a']],
            persistedTeams: ['stored-team'],
        );

        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn('entity-1');
        $entity->method('getEntityType')->willReturn('TrackingSource');
        $entity->method('isNew')->willReturn(false);
        $entity->method('hasId')->willReturn(true);
        $entity->method('get')->with('teamsIds')->willReturn([]);

        // The authorization path relies on this: it must never widen the team set
        // beyond what the save actually carried.
        $this->assertNull($access->deriveTenantId($entity, 'tracking source'));
    }
}
