<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Hooks\Tenant;

use Espo\Core\Utils\Log;
use Espo\Modules\Global\Hooks\Tenant\RenameTeamsOnTenantRename;
use Espo\Modules\Global\Tools\Tenant\TenantAdminTeamProvisioner;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * Renaming a Tenant should carry over to its provisioned Teams, but must not
 * clobber a name an admin deliberately chose. A team is therefore only renamed
 * while it still carries the name derived from the OLD tenant name.
 */
class RenameTeamsOnTenantRenameTest extends TestCase
{
    /** @var array<string, string> */
    private array $teamNames = [];

    /** @var list<array{0: string, 1: string}> */
    private array $saved = [];

    /**
     * @param array<string, string> $teamNames team id => current name
     */
    private function makeHook(array $teamNames, ?string $adminTeamId): RenameTeamsOnTenantRename
    {
        $this->teamNames = $teamNames;

        $entityManager = $this->createMock(EntityManager::class);

        $entityManager->method('getEntityById')->willReturnCallback(
            function (string $entityType, string $id): ?Entity {
                if (!array_key_exists($id, $this->teamNames)) {
                    return null;
                }

                $team = $this->createMock(Entity::class);
                $team->method('getId')->willReturn($id);
                $team->method('get')->willReturnCallback(
                    fn (string $attr) => $attr === 'name' ? $this->teamNames[$id] : null,
                );
                // Entity::set() is declared `: static`; a void callback makes
                // PHPUnit return null, which raises a TypeError that the hook's
                // catch-all would swallow — silently skipping the save.
                $team->method('set')->willReturnCallback(
                    function (string $attr, $value) use ($id, &$team): Entity {
                        if ($attr === 'name') {
                            $this->teamNames[$id] = (string) $value;
                        }

                        return $team;
                    },
                );

                return $team;
            },
        );

        $entityManager->method('saveEntity')->willReturnCallback(
            function (Entity $entity): void {
                $this->saved[] = [(string) $entity->getId(), $this->teamNames[$entity->getId()]];
            },
        );

        $provisioner = $this->createMock(TenantAdminTeamProvisioner::class);
        $provisioner->method('findAdminTeamId')->willReturn($adminTeamId);
        $provisioner->method('adminTeamName')->willReturnCallback(
            static fn (string $name): string => $name . ' / Admin',
        );

        return new RenameTeamsOnTenantRename($entityManager, $provisioner, $this->createMock(Log::class));
    }

    private function tenant(
        ?string $newName,
        ?string $oldName,
        ?string $baseTeamId = 'team-base',
        bool $isNew = false,
    ): Entity {
        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn('tenant-1');
        $entity->method('isNew')->willReturn($isNew);
        $entity->method('isAttributeChanged')->willReturnCallback(
            static fn (string $attr): bool => $attr === 'name' && $newName !== $oldName,
        );
        $entity->method('get')->willReturnCallback(
            static fn (string $attr) => match ($attr) {
                'name' => $newName,
                'baseUserTeamId' => $baseTeamId,
                default => null,
            },
        );
        $entity->method('getFetched')->willReturnCallback(
            static fn (string $attr) => $attr === 'name' ? $oldName : null,
        );

        return $entity;
    }

    public function testRenamesBothTeams(): void
    {
        $hook = $this->makeHook(
            ['team-base' => 'Acme', 'team-admin' => 'Acme / Admin'],
            'team-admin',
        );

        $hook->afterSave($this->tenant('Globex', 'Acme'), []);

        $this->assertSame('Globex', $this->teamNames['team-base']);
        $this->assertSame('Globex / Admin', $this->teamNames['team-admin']);
        $this->assertSame(
            [['team-base', 'Globex'], ['team-admin', 'Globex / Admin']],
            $this->saved,
        );
    }

    public function testLeavesACustomisedBaseTeamNameAlone(): void
    {
        $hook = $this->makeHook(
            ['team-base' => 'Acme Sales Floor', 'team-admin' => 'Acme / Admin'],
            'team-admin',
        );

        $hook->afterSave($this->tenant('Globex', 'Acme'), []);

        $this->assertSame('Acme Sales Floor', $this->teamNames['team-base']);
        $this->assertSame('Globex / Admin', $this->teamNames['team-admin']);
        $this->assertSame([['team-admin', 'Globex / Admin']], $this->saved);
    }

    public function testLeavesACustomisedAdminTeamNameAlone(): void
    {
        $hook = $this->makeHook(
            ['team-base' => 'Acme', 'team-admin' => 'Acme Owners'],
            'team-admin',
        );

        $hook->afterSave($this->tenant('Globex', 'Acme'), []);

        $this->assertSame('Globex', $this->teamNames['team-base']);
        $this->assertSame('Acme Owners', $this->teamNames['team-admin']);
        $this->assertSame([['team-base', 'Globex']], $this->saved);
    }

    public function testNoOpWhenNameDidNotChange(): void
    {
        $hook = $this->makeHook(['team-base' => 'Acme'], null);

        $hook->afterSave($this->tenant('Acme', 'Acme'), []);

        $this->assertSame([], $this->saved);
    }

    public function testNoOpOnCreateSoItCannotRaceTheProvisioningHooks(): void
    {
        $hook = $this->makeHook(['team-base' => 'Acme'], null);

        $hook->afterSave($this->tenant('Globex', 'Acme', isNew: true), []);

        $this->assertSame([], $this->saved);
    }

    public function testSkipsWhenNoAdminTeamIsProvisioned(): void
    {
        $hook = $this->makeHook(['team-base' => 'Acme'], null);

        $hook->afterSave($this->tenant('Globex', 'Acme'), []);

        $this->assertSame([['team-base', 'Globex']], $this->saved);
    }

    public function testSkipsWhenTenantHasNoBaseTeam(): void
    {
        $hook = $this->makeHook(['team-admin' => 'Acme / Admin'], 'team-admin');

        $hook->afterSave($this->tenant('Globex', 'Acme', baseTeamId: null), []);

        $this->assertSame([['team-admin', 'Globex / Admin']], $this->saved);
    }

    public function testToleratesAMissingTeamRow(): void
    {
        $hook = $this->makeHook([], 'team-admin');

        $hook->afterSave($this->tenant('Globex', 'Acme'), []);

        $this->assertSame([], $this->saved);
    }

    public function testOptOutOptionIsRespected(): void
    {
        $hook = $this->makeHook(['team-base' => 'Acme'], null);

        $hook->afterSave($this->tenant('Globex', 'Acme'), ['skipTenantTeamRename' => true]);

        $this->assertSame([], $this->saved);
    }

    public function testIgnoresABlankNewName(): void
    {
        $hook = $this->makeHook(['team-base' => 'Acme'], null);

        $hook->afterSave($this->tenant('   ', 'Acme'), []);

        $this->assertSame([], $this->saved);
    }

    /**
     * A rename is cosmetic and must never fail the Tenant save.
     */
    public function testSwallowsTeamSaveFailures(): void
    {
        $this->teamNames = ['team-base' => 'Acme'];

        $entityManager = $this->createMock(EntityManager::class);

        $entityManager->method('getEntityById')->willReturnCallback(
            function (string $entityType, string $id): Entity {
                $team = $this->createMock(Entity::class);
                $team->method('getId')->willReturn($id);
                $team->method('get')->willReturn('Acme');
                $team->method('set')->willReturnCallback(
                    fn (): Entity => $team,
                );

                return $team;
            },
        );

        $entityManager->method('saveEntity')->willThrowException(new \RuntimeException('db down'));

        $provisioner = $this->createMock(TenantAdminTeamProvisioner::class);
        $provisioner->method('findAdminTeamId')->willReturn(null);

        $hook = new RenameTeamsOnTenantRename(
            $entityManager,
            $provisioner,
            $this->createMock(Log::class),
        );

        $hook->afterSave($this->tenant('Globex', 'Acme'), []);

        $this->addToAssertionCount(1);
    }
}
