<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Tools\Tenant;

use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

/**
 * A team may be attached to a Tenant either as `baseUserTeam` or as one of
 * `otherUserTeams`. Resolvers that only matched `baseUserTeamId` silently
 * returned "no tenant" for anything scoped to a tenant's secondary team, and
 * `resolveFromTeamIds` returned an arbitrary first match when teams spanned
 * several tenants — which, on write paths, misfiles a record across the
 * tenancy boundary unrecoverably.
 */
class TenantResolverTest extends TestCase
{
    /**
     * @param array<string, list<string>> $baseTeamToTenants  team id => tenants owning it as baseUserTeam
     * @param array<string, list<string>> $otherTeamToTenants team id => tenants owning it as otherUserTeam
     */
    private function makeResolver(array $baseTeamToTenants, array $otherTeamToTenants): TenantResolver
    {
        $entityManager = $this->createMock(EntityManager::class);

        $entityManager->method('getRDBRepository')->willReturnCallback(
            function (string $entityType) use ($baseTeamToTenants, $otherTeamToTenants): RDBRepository {
                $this->assertSame('Tenant', $entityType);

                $repository = $this->createMock(RDBRepository::class);

                $repository->method('select')->willReturnCallback(
                    fn (): RDBSelectBuilder => $this->makeBuilder($baseTeamToTenants, $otherTeamToTenants),
                );

                // resolveFromTeamId() calls where() directly on the repository.
                $repository->method('where')->willReturnCallback(
                    fn (array $clause): RDBSelectBuilder => $this
                        ->makeBuilder($baseTeamToTenants, $otherTeamToTenants)
                        ->where($clause),
                );

                return $repository;
            },
        );

        return new TenantResolver($entityManager);
    }

    /**
     * @param array<string, list<string>> $baseTeamToTenants
     * @param array<string, list<string>> $otherTeamToTenants
     */
    private function makeBuilder(array $baseTeamToTenants, array $otherTeamToTenants): RDBSelectBuilder
    {
        $builder = $this->createMock(RDBSelectBuilder::class);

        /** @var list<string> $resolved */
        $resolved = [];

        $builder->method('select')->willReturnSelf();
        $builder->method('join')->willReturnSelf();

        $builder->method('where')->willReturnCallback(
            function (array $clause) use ($builder, &$resolved, $baseTeamToTenants, $otherTeamToTenants): RDBSelectBuilder {
                if (array_key_exists('baseUserTeamId', $clause)) {
                    $map = $baseTeamToTenants;
                    $teamIds = (array) $clause['baseUserTeamId'];
                } elseif (array_key_exists('otherUserTeamsMiddle.teamId', $clause)) {
                    $map = $otherTeamToTenants;
                    $teamIds = (array) $clause['otherUserTeamsMiddle.teamId'];
                } else {
                    $this->fail('Unexpected where clause: ' . json_encode(array_keys($clause)));
                }

                $found = [];

                foreach ($teamIds as $teamId) {
                    foreach ($map[$teamId] ?? [] as $tenantId) {
                        $found[$tenantId] = true;
                    }
                }

                $resolved = array_keys($found);

                return $builder;
            },
        );

        // NB: must be a closure with an explicit by-reference binding — an arrow
        // function would capture $resolved by value at definition time (empty).
        $builder->method('find')->willReturnCallback(
            function () use (&$resolved): EntityCollection {
                return $this->collection($resolved);
            },
        );

        $builder->method('findOne')->willReturnCallback(
            function () use (&$resolved): ?Entity {
                return isset($resolved[0]) ? $this->entityStub($resolved[0]) : null;
            },
        );

        return $builder;
    }

    /**
     * @param list<string> $ids
     */
    private function collection(array $ids): EntityCollection
    {
        return new EntityCollection(array_map(fn (string $id): Entity => $this->entityStub($id), $ids));
    }

    private function entityStub(string $id): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn($id);

        return $entity;
    }

    public function testResolvesTenantFromBaseUserTeam(): void
    {
        $resolver = $this->makeResolver(['team-a' => ['tenant-1']], []);

        $this->assertSame('tenant-1', $resolver->resolveUniqueFromTeamIds(['team-a']));
        $this->assertSame(['tenant-1'], $resolver->resolveAllFromTeamIds(['team-a']));
    }

    /**
     * The bug this guards: matching only `baseUserTeamId` reported "no tenant"
     * for a record scoped to a tenant's secondary team.
     */
    public function testResolvesTenantFromOtherUserTeamNotOnlyBaseUserTeam(): void
    {
        $resolver = $this->makeResolver([], ['team-secondary' => ['tenant-1']]);

        $this->assertSame('tenant-1', $resolver->resolveUniqueFromTeamIds(['team-secondary']));
        $this->assertSame(['tenant-1'], $resolver->resolveAllFromTeamIds(['team-secondary']));
    }

    /**
     * One tenant owning one team as base and another as secondary is NOT
     * ambiguous — it is a single tenant and must still resolve.
     */
    public function testSameTenantViaBaseAndOtherTeamIsNotAmbiguous(): void
    {
        $resolver = $this->makeResolver(
            ['team-base' => ['tenant-1']],
            ['team-secondary' => ['tenant-1']],
        );

        $this->assertSame(['tenant-1'], $resolver->resolveAllFromTeamIds(['team-base', 'team-secondary']));
        $this->assertSame('tenant-1', $resolver->resolveUniqueFromTeamIds(['team-base', 'team-secondary']));
    }

    public function testAmbiguousTeamsRefuseToResolveToASingleTenant(): void
    {
        $resolver = $this->makeResolver(
            ['team-a' => ['tenant-1'], 'team-b' => ['tenant-2']],
            [],
        );

        $all = $resolver->resolveAllFromTeamIds(['team-a', 'team-b']);

        sort($all);

        $this->assertSame(['tenant-1', 'tenant-2'], $all);
        $this->assertNull(
            $resolver->resolveUniqueFromTeamIds(['team-a', 'team-b']),
            'Ambiguity must be a refusal, not an arbitrary pick.',
        );
    }

    public function testAmbiguityIsDetectedAcrossBaseAndOtherUserTeams(): void
    {
        $resolver = $this->makeResolver(
            ['team-a' => ['tenant-1']],
            ['team-b' => ['tenant-2']],
        );

        $this->assertNull($resolver->resolveUniqueFromTeamIds(['team-a', 'team-b']));
    }

    public function testUnknownTeamsResolveToNothing(): void
    {
        $resolver = $this->makeResolver([], []);

        $this->assertSame([], $resolver->resolveAllFromTeamIds(['team-unknown']));
        $this->assertNull($resolver->resolveUniqueFromTeamIds(['team-unknown']));
    }

    public function testBlankAndDuplicateTeamIdsAreIgnored(): void
    {
        $resolver = $this->makeResolver(['team-a' => ['tenant-1']], []);

        $this->assertSame([], $resolver->resolveAllFromTeamIds(['', '   ']));
        $this->assertNull($resolver->resolveUniqueFromTeamIds([]));
        $this->assertSame(
            ['tenant-1'],
            $resolver->resolveAllFromTeamIds(['team-a', ' team-a ', 'team-a']),
        );
    }
}
