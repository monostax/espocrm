<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Tools\Tenant;

use Espo\Core\Utils\Log;
use Espo\Modules\Global\Tools\Tenant\TenantAdminTeamProvisioner;
use Espo\Modules\Global\Tools\Tenant\TenantTeamProvisioner;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRelation;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Every Tenant must own a "{name} / Admin" Team carrying the SHARED
 * `tenant-admin` Role.
 *
 * The role must be the singleton static-id role, not a per-tenant copy: every
 * gate (TenantRoleAuth, CatalogAuth, AppParams\IsTenantAdmin) resolves it by
 * fixed id, so a per-tenant Role row would be unrecognised and its holders
 * would silently not be admins.
 *
 * The team must be registered under `otherUserTeams`, because that is what
 * TenantResolver / TeamTenantAccess match when deriving `tenantId` from teams.
 */
class TenantAdminTeamProvisionerTest extends TestCase
{
    /** @var list<array{0: string, 1: string}> */
    private array $relatedRoles = [];

    /** @var list<string> */
    private array $relatedOtherUserTeams = [];

    /** @var list<array<string, mixed>> */
    private array $createdTeams = [];

    /**
     * @param list<string> $existingRoleIds        Role rows that exist in the instance.
     * @param list<string> $tenantOtherTeamIds     Teams already linked as otherUserTeams.
     * @param array<string, list<string>> $teamRoles Team id => role ids it already holds.
     * @param array<string, string> $teamsByName    Team name => existing team id.
     */
    private function makeProvisioner(
        array $existingRoleIds = ['tenant-admin'],
        array $tenantOtherTeamIds = [],
        array $teamRoles = [],
        array $teamsByName = [],
    ): TenantAdminTeamProvisioner {
        $entityManager = $this->createMock(EntityManager::class);

        $entityManager->method('getEntityById')->willReturnCallback(
            function (string $entityType, string $id) use ($existingRoleIds): ?Entity {
                if ($entityType === 'Role') {
                    return in_array($id, $existingRoleIds, true) ? $this->entityStub($id) : null;
                }

                return $this->entityStub($id);
            },
        );

        $entityManager->method('createEntity')->willReturnCallback(
            function (string $entityType, array $data): Entity {
                $this->assertSame('Team', $entityType);
                $this->createdTeams[] = $data;

                return $this->entityStub('team-new');
            },
        );

        $entityManager->method('getRDBRepository')->willReturnCallback(
            fn (string $entityType): RDBRepository => $entityType === 'Tenant'
                ? $this->tenantRepository($tenantOtherTeamIds)
                : $this->teamRepository($tenantOtherTeamIds, $teamRoles, $teamsByName),
        );

        // Real TenantTeamProvisioner over the same mocked EntityManager: the
        // shared role-resolution and find-or-create logic is exercised for real
        // rather than stubbed away.
        return new TenantAdminTeamProvisioner(
            $entityManager,
            new TenantTeamProvisioner($entityManager),
            $this->createMock(Log::class),
        );
    }

    /**
     * @param list<string> $tenantOtherTeamIds
     */
    private function tenantRepository(array $tenantOtherTeamIds): RDBRepository
    {
        $repository = $this->createMock(RDBRepository::class);

        $relation = $this->createMock(RDBRelation::class);
        $relation->method('find')->willReturn($this->collection($tenantOtherTeamIds));
        $relation->method('count')->willReturn(count($tenantOtherTeamIds));
        $relation->method('relateById')->willReturnCallback(
            function (string $id): void {
                $this->relatedOtherUserTeams[] = $id;
            },
        );

        $repository->method('getRelation')->willReturnCallback(
            function (Entity $entity, string $link) use ($relation): RDBRelation {
                $this->assertSame('otherUserTeams', $link);

                return $relation;
            },
        );

        return $repository;
    }

    /**
     * @param list<string> $tenantOtherTeamIds
     * @param array<string, list<string>> $teamRoles
     * @param array<string, string> $teamsByName
     */
    private function teamRepository(
        array $tenantOtherTeamIds,
        array $teamRoles,
        array $teamsByName,
    ): RDBRepository {
        $repository = $this->createMock(RDBRepository::class);

        $repository->method('where')->willReturnCallback(
            function (array $clause) use ($tenantOtherTeamIds, $teamRoles, $teamsByName): RDBSelectBuilder {
                $builder = $this->createMock(RDBSelectBuilder::class);
                $builder->method('join')->willReturnSelf();

                if (array_key_exists('name', $clause)) {
                    // findOrCreateTeam(): reuse an exact-name match.
                    $id = $teamsByName[$clause['name']] ?? null;
                    $builder->method('findOne')->willReturn($id ? $this->entityStub($id) : null);

                    return $builder;
                }

                // findLinkedAdminTeamId(): which of the tenant's otherUserTeams
                // already holds the tenant-admin role.
                $candidateIds = (array) ($clause['id'] ?? []);

                $builder->method('where')->willReturnCallback(
                    function (array $roleClause) use ($builder, $candidateIds, $teamRoles, $tenantOtherTeamIds): RDBSelectBuilder {
                        $roleId = (string) ($roleClause['roles.id'] ?? '');

                        $match = null;

                        foreach ($candidateIds as $teamId) {
                            if (
                                in_array($teamId, $tenantOtherTeamIds, true)
                                && in_array($roleId, $teamRoles[$teamId] ?? [], true)
                            ) {
                                $match = $teamId;

                                break;
                            }
                        }

                        $inner = clone $builder;
                        $inner->method('findOne')->willReturn($match ? $this->entityStub($match) : null);

                        return $inner;
                    },
                );

                return $builder;
            },
        );

        $repository->method('getRelation')->willReturnCallback(
            function (Entity $entity, string $link) use ($teamRoles): RDBRelation {
                $this->assertSame('roles', $link);

                $relation = $this->createMock(RDBRelation::class);
                $relation->method('find')->willReturn(
                    $this->collection($teamRoles[$entity->getId()] ?? []),
                );
                $relation->method('relateById')->willReturnCallback(
                    function (string $roleId) use ($entity): void {
                        $this->relatedRoles[] = [(string) $entity->getId(), $roleId];
                    },
                );

                return $relation;
            },
        );

        return $repository;
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

    private function tenant(?string $name, string $id = 'tenant-1'): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn($id);
        $entity->method('get')->willReturnCallback(
            static fn (string $attr) => $attr === 'name' ? $name : null,
        );

        return $entity;
    }

    public function testProvisionsAdminTeamNamedAfterTheTenant(): void
    {
        $provisioner = $this->makeProvisioner();

        $teamId = $this->provision($provisioner, 'Acme');

        $this->assertSame('team-new', $teamId);
        $this->assertSame([['name' => 'Acme / Admin']], $this->createdTeams);
    }

    public function testLinksTheSharedTenantAdminRoleNotAPerTenantCopy(): void
    {
        $provisioner = $this->makeProvisioner();

        $this->provision($provisioner, 'Acme');

        $this->assertSame([['team-new', 'tenant-admin']], $this->relatedRoles);
    }

    /**
     * `otherUserTeams` is what the tenant resolvers match; a dedicated link
     * would make admin-team-scoped records resolve to no tenant at all.
     */
    public function testRegistersTheTeamAsAnOtherUserTeamOfTheTenant(): void
    {
        $provisioner = $this->makeProvisioner();

        $this->provision($provisioner, 'Acme');

        $this->assertSame(['team-new'], $this->relatedOtherUserTeams);
    }

    public function testIsIdempotentWhenTheAdminTeamAlreadyExists(): void
    {
        $provisioner = $this->makeProvisioner(
            tenantOtherTeamIds: ['team-admin'],
            teamRoles: ['team-admin' => ['tenant-admin']],
        );

        $teamId = $this->provision($provisioner, 'Acme');

        $this->assertSame('team-admin', $teamId);
        $this->assertSame([], $this->createdTeams);
        $this->assertSame([], $this->relatedOtherUserTeams);
        $this->assertSame([], $this->relatedRoles);
    }

    /**
     * Detection is by role, not by name — otherwise renaming a tenant would
     * provision a second admin team beside the first.
     */
    public function testRenamedTenantDoesNotGetASecondAdminTeam(): void
    {
        $provisioner = $this->makeProvisioner(
            tenantOtherTeamIds: ['team-admin'],
            teamRoles: ['team-admin' => ['tenant-admin']],
        );

        $teamId = $this->provision($provisioner, 'Acme Renamed');

        $this->assertSame('team-admin', $teamId);
        $this->assertSame([], $this->createdTeams);
    }

    /**
     * A non-admin secondary team must not be mistaken for the admin team.
     */
    public function testOtherUserTeamWithoutTheRoleDoesNotCountAsTheAdminTeam(): void
    {
        $provisioner = $this->makeProvisioner(
            tenantOtherTeamIds: ['team-sales'],
            teamRoles: ['team-sales' => ['tenant']],
        );

        $this->provision($provisioner, 'Acme');

        $this->assertSame([['name' => 'Acme / Admin']], $this->createdTeams);
        $this->assertSame(['team-new'], $this->relatedOtherUserTeams);
    }

    /**
     * Team.name has no unique constraint, so an exact-name match is reused
     * rather than duplicated.
     */
    public function testReusesAnExistingTeamWithTheSameName(): void
    {
        $provisioner = $this->makeProvisioner(
            teamsByName: ['Acme / Admin' => 'team-existing'],
        );

        $teamId = $this->provision($provisioner, 'Acme');

        $this->assertSame('team-existing', $teamId);
        $this->assertSame([], $this->createdTeams, 'Should not create a duplicate team.');
        $this->assertSame([['team-existing', 'tenant-admin']], $this->relatedRoles);
        $this->assertSame(['team-existing'], $this->relatedOtherUserTeams);
    }

    public function testDoesNotRelinkARoleTheTeamAlreadyHolds(): void
    {
        $provisioner = $this->makeProvisioner(
            teamsByName: ['Acme / Admin' => 'team-existing'],
            teamRoles: ['team-existing' => ['tenant-admin']],
        );

        $this->provision($provisioner, 'Acme');

        $this->assertSame([], $this->relatedRoles);
        $this->assertSame(['team-existing'], $this->relatedOtherUserTeams);
    }

    /**
     * Creating a role-less team would look provisioned while granting nothing,
     * and the backfill would then skip it forever.
     */
    public function testRefusesToProvisionWhenTheTenantAdminRoleIsNotSeeded(): void
    {
        $provisioner = $this->makeProvisioner(existingRoleIds: []);

        $this->assertNull($this->provision($provisioner, 'Acme'));
        $this->assertSame([], $this->createdTeams);
        $this->assertSame([], $this->relatedOtherUserTeams);
    }

    /**
     * UUID id mode stores these roles under md5 of the static id.
     */
    public function testResolvesTheRoleByItsHashedIdInUuidMode(): void
    {
        $hashed = md5('tenant-admin');

        $provisioner = $this->makeProvisioner(existingRoleIds: [$hashed]);

        $this->provision($provisioner, 'Acme');

        $this->assertSame([['team-new', $hashed]], $this->relatedRoles);
    }

    public function testSkipsTenantWithoutAName(): void
    {
        $provisioner = $this->makeProvisioner();

        $this->assertNull($this->provision($provisioner, null));
        $this->assertNull($this->provision($provisioner, '   '));
        $this->assertSame([], $this->createdTeams);
    }

    /**
     * Tenant.name and Team.name are both varchar(100); appending " / Admin" to a
     * maximal tenant name would overflow Team.name and fail the INSERT.
     */
    public function testAdminTeamNameIsClampedToTheTeamNameColumnLength(): void
    {
        $provisioner = $this->makeProvisioner();

        $longName = str_repeat('a', 100);
        $name = $provisioner->adminTeamName($longName);

        $this->assertSame(100, mb_strlen($name));
        $this->assertStringEndsWith(' / Admin', $name);
    }

    public function testAdminTeamNameIsNotClampedWhenItFits(): void
    {
        $provisioner = $this->makeProvisioner();

        $this->assertSame('Acme / Admin', $provisioner->adminTeamName('Acme'));
    }

    public function testProvisionedTeamNameRespectsTheLengthCap(): void
    {
        $provisioner = $this->makeProvisioner();

        $provisioner->ensureForTenant($this->tenant(str_repeat('b', 100)));

        $this->assertCount(1, $this->createdTeams);
        $this->assertSame(100, mb_strlen((string) $this->createdTeams[0]['name']));
    }

    public function testFindAdminTeamIdLocatesTheTeamByRole(): void
    {
        $provisioner = $this->makeProvisioner(
            tenantOtherTeamIds: ['team-sales', 'team-admin'],
            teamRoles: ['team-sales' => ['tenant'], 'team-admin' => ['tenant-admin']],
        );

        $this->assertSame('team-admin', $provisioner->findAdminTeamId($this->tenant('Acme')));
    }

    public function testFindAdminTeamIdReturnsNullWhenNotProvisioned(): void
    {
        $provisioner = $this->makeProvisioner(
            tenantOtherTeamIds: ['team-sales'],
            teamRoles: ['team-sales' => ['tenant']],
        );

        $this->assertNull($provisioner->findAdminTeamId($this->tenant('Acme')));
    }

    public function testFindAdminTeamIdReturnsNullWhenTheRoleIsNotSeeded(): void
    {
        $provisioner = $this->makeProvisioner(
            existingRoleIds: [],
            tenantOtherTeamIds: ['team-admin'],
            teamRoles: ['team-admin' => ['tenant-admin']],
        );

        $this->assertNull($provisioner->findAdminTeamId($this->tenant('Acme')));
    }

    private function provision(TenantAdminTeamProvisioner $provisioner, ?string $name): ?string
    {
        return $provisioner->ensureForTenant($this->tenant($name));
    }
}
