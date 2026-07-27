<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Tools\Tenant;

use Espo\Entities\User;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRelation;
use Espo\ORM\Repository\RDBRepository;
use PHPUnit\Framework\TestCase;

/**
 * A user may belong to more than one tenant (workspace) by design, so
 * membership is a SET, and it is the UNION of two mechanisms that each grant
 * real access:
 *
 *   1. the explicit `tenantUser` relation
 *   2. Team membership → Tenant.baseUserTeam / Tenant.otherUserTeams
 *
 * The previous implementation consulted the explicit links first and used teams
 * only as a fallback when that set was empty, letting the explicit set SHADOW
 * the derived one. A user explicitly linked to Tenant A who was also in Tenant
 * B's team resolved to [A] alone — while Espo's team ACL genuinely hands them
 * B's records. These tests pin the union.
 */
class UserTenantResolverTest extends TestCase
{
    /**
     * @param list<string> $explicitTenantIds
     * @param list<string> $teamIds
     * @param list<string> $teamDerivedTenantIds
     */
    private function makeResolver(
        array $explicitTenantIds,
        array $teamIds,
        array $teamDerivedTenantIds,
    ): UserTenantResolver {
        $entityManager = $this->createMock(EntityManager::class);

        $relation = $this->createMock(RDBRelation::class);
        $relation->method('find')->willReturn($this->collection($explicitTenantIds));

        $repository = $this->createMock(RDBRepository::class);
        $repository->method('getRelation')->willReturnCallback(
            function (Entity $entity, string $link) use ($relation): RDBRelation {
                $this->assertSame('tenants', $link);

                return $relation;
            },
        );

        $entityManager->method('getRDBRepository')->willReturn($repository);

        $tenantResolver = $this->createMock(TenantResolver::class);
        $tenantResolver->method('resolveAllFromTeamIds')->willReturnCallback(
            function (array $passedTeamIds) use ($teamIds, $teamDerivedTenantIds): array {
                $this->assertSame($teamIds, $passedTeamIds, 'Team ids must always be consulted.');

                return $teamDerivedTenantIds;
            },
        );

        return new UserTenantResolver($entityManager, $tenantResolver);
    }

    /**
     * @param list<string> $ids
     */
    private function collection(array $ids): EntityCollection
    {
        return new EntityCollection(array_map(
            function (string $id): Entity {
                $entity = $this->createMock(Entity::class);
                $entity->method('getId')->willReturn($id);

                return $entity;
            },
            $ids,
        ));
    }

    /**
     * @param list<string> $teamIds
     */
    private function user(array $teamIds): User
    {
        $user = $this->createMock(User::class);
        $user->method('getTeamIdList')->willReturn($teamIds);

        return $user;
    }

    /**
     * The regression this guards: teams must be consulted even when the
     * explicit relation is non-empty.
     */
    public function testExplicitLinksDoNotShadowTeamDerivedTenants(): void
    {
        $resolver = $this->makeResolver(
            explicitTenantIds: ['tenant-a'],
            teamIds: ['team-of-b'],
            teamDerivedTenantIds: ['tenant-b'],
        );

        $ids = $resolver->resolveTenantIds($this->user(['team-of-b']));

        sort($ids);

        $this->assertSame(['tenant-a', 'tenant-b'], $ids);
    }

    public function testResolvesFromExplicitLinksAlone(): void
    {
        $resolver = $this->makeResolver(['tenant-a'], [], []);

        $this->assertSame(['tenant-a'], $resolver->resolveTenantIds($this->user([])));
    }

    public function testResolvesFromTeamsAlone(): void
    {
        $resolver = $this->makeResolver([], ['team-1'], ['tenant-b']);

        $this->assertSame(['tenant-b'], $resolver->resolveTenantIds($this->user(['team-1'])));
    }

    public function testDeduplicatesWhenBothMechanismsNameTheSameTenant(): void
    {
        $resolver = $this->makeResolver(['tenant-a'], ['team-1'], ['tenant-a']);

        $this->assertSame(['tenant-a'], $resolver->resolveTenantIds($this->user(['team-1'])));
    }

    /**
     * Multi-workspace users are intentional, not an error.
     */
    public function testSupportsAUserInSeveralTenants(): void
    {
        $resolver = $this->makeResolver([], ['t1', 't2'], ['tenant-a', 'tenant-b']);

        $ids = $resolver->resolveTenantIds($this->user(['t1', 't2']));

        sort($ids);

        $this->assertSame(['tenant-a', 'tenant-b'], $ids);
    }

    public function testUserWithNoMembershipResolvesToNothing(): void
    {
        $resolver = $this->makeResolver([], [], []);

        $this->assertSame([], $resolver->resolveTenantIds($this->user([])));
    }

    public function testCanActForTenant(): void
    {
        $resolver = $this->makeResolver(['tenant-a'], ['team-1'], ['tenant-b']);
        $user = $this->user(['team-1']);

        $this->assertTrue($resolver->canActForTenant($user, 'tenant-a'));
        $this->assertTrue($resolver->canActForTenant($user, 'tenant-b'));
        $this->assertFalse($resolver->canActForTenant($user, 'tenant-c'));
        $this->assertFalse($resolver->canActForTenant($user, ''));
    }

    public function testResolveTenantIdSetIsKeyedForMembershipTests(): void
    {
        $resolver = $this->makeResolver(['tenant-a'], ['team-1'], ['tenant-b']);

        $set = $resolver->resolveTenantIdSet($this->user(['team-1']));

        $this->assertArrayHasKey('tenant-a', $set);
        $this->assertArrayHasKey('tenant-b', $set);
        $this->assertTrue($set['tenant-a']);
    }

    /**
     * The explicit-link query is deliberately NOT wrapped in a try/catch: a
     * failing membership query must not silently degrade an authorisation
     * decision to the team-derived subset.
     */
    public function testMembershipQueryFailurePropagatesRatherThanDegrading(): void
    {
        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getRDBRepository')
            ->willThrowException(new \RuntimeException('db down'));

        $tenantResolver = $this->createMock(TenantResolver::class);
        $tenantResolver->method('resolveAllFromTeamIds')->willReturn(['tenant-b']);

        $resolver = new UserTenantResolver($entityManager, $tenantResolver);

        $this->expectException(\RuntimeException::class);

        $resolver->resolveTenantIds($this->user(['team-1']));
    }
}
