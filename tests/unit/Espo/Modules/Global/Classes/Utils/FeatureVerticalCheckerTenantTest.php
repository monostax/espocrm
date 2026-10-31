<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Classes\Utils;

use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Utils\Metadata;
use Espo\Modules\Global\Classes\Utils\FeatureVerticalChecker;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

/**
 * A workspace's feature packs are the add-on roles on its base user team and
 * other user teams, independent of which user asks.
 */
class FeatureVerticalCheckerTenantTest extends TestCase
{
    private const VERTICALS = [
        'b2b' => ['roleStaticId' => 'tenant-b2b'],
        'calls' => ['roleStaticId' => 'tenant-pack-calls', 'pack' => true],
        'meetings' => ['roleStaticId' => 'tenant-pack-meetings', 'pack' => true],
    ];

    public function testPacksComeFromBaseAndOtherUserTeamRoles(): void
    {
        $checker = $this->makeChecker(
            tenant: $this->tenant('team-base', ['team-other']),
            teamRoles: [
                'team-base' => ['tenant', 'tenant-b2b', 'tenant-pack-calls'],
                'team-other' => ['tenant-admin', 'tenant-pack-calls'],
            ],
            expectedTeamIds: ['team-other', 'team-base'],
        );

        $this->assertSame(['b2b', 'calls'], $checker->getTenantVerticals('t1'));
    }

    public function testRolesThatAreNotPacksAreIgnored(): void
    {
        $checker = $this->makeChecker(
            tenant: $this->tenant('team-base', []),
            teamRoles: ['team-base' => ['tenant', 'tenant-admin']],
            expectedTeamIds: ['team-base'],
        );

        $this->assertSame([], $checker->getTenantVerticals('t1'));
    }

    public function testUnknownTenantHasNoPacks(): void
    {
        $checker = $this->makeChecker(tenant: null, teamRoles: [], expectedTeamIds: null);

        $this->assertSame([], $checker->getTenantVerticals('missing'));
    }

    public function testTenantWithoutTeamsDoesNotQueryTeams(): void
    {
        $checker = $this->makeChecker(
            tenant: $this->tenant(null, []),
            teamRoles: [],
            expectedTeamIds: null,
        );

        $this->assertSame([], $checker->getTenantVerticals('t1'));
    }

    /**
     * @param array<string, list<string>> $teamRoles
     * @param ?list<string> $expectedTeamIds null when the team query must not run
     */
    private function makeChecker(?Entity $tenant, array $teamRoles, ?array $expectedTeamIds): FeatureVerticalChecker
    {
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturnCallback(
            fn (array $key) => $key === ['app', 'featureVerticals'] ? self::VERTICALS : null,
        );

        $builder = $this->createMock(RDBSelectBuilder::class);
        $builder->method('find')->willReturn(new EntityCollection(array_map(
            function (string $teamId, array $roles): Entity {
                $team = $this->createMock(CoreEntity::class);
                $team->method('getLinkMultipleIdList')->with('roles')->willReturn($roles);

                return $team;
            },
            array_keys($teamRoles),
            array_values($teamRoles),
        )));

        $teamRepository = $this->createMock(RDBRepository::class);

        if ($expectedTeamIds === null) {
            $teamRepository->expects($this->never())->method('where');
        } else {
            $teamRepository->expects($this->once())->method('where')
                ->with(['id' => $expectedTeamIds])
                ->willReturn($builder);
        }

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getEntityById')->with('Tenant')->willReturn($tenant);
        $entityManager->method('getRDBRepository')->with('Team')->willReturn($teamRepository);

        return new FeatureVerticalChecker($entityManager, $metadata);
    }

    /**
     * @param list<string> $otherTeamIds
     */
    private function tenant(?string $baseTeamId, array $otherTeamIds): Entity
    {
        $tenant = $this->createMock(CoreEntity::class);
        $tenant->method('getLinkMultipleIdList')->with('otherUserTeams')->willReturn($otherTeamIds);
        $tenant->method('get')->with('baseUserTeamId')->willReturn($baseTeamId);

        return $tenant;
    }
}
