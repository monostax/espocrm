<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Tools\Team;

use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Tools\Team\TenantTeamMirror;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\TenantTeamProvisioner;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRelation;
use Espo\ORM\Repository\RDBRepository;
use PHPUnit\Framework\TestCase;

/**
 * Every ChatwootTeam owns one CRM Team "{tenantName} / {teamName}", found by a
 * key built from the ChatwootTeam record id and registered under the Tenant's
 * `otherUserTeams` (what the team->tenant resolvers match).
 */
class TenantTeamMirrorTest extends TestCase
{
    /** @var list<array{0: string, 1: string}> key, name passed to ensureTeam() */
    private array $ensured = [];

    /** @var list<string> */
    private array $relatedOtherUserTeams = [];

    /** @var list<string> names written to the CRM team */
    private array $renamedTo = [];

    /** @var list<string> */
    private array $removedTeamIds = [];

    /** @var list<string> */
    private array $unrelatedTenantIds = [];

    /**
     * @param ?string $existingTeamName      Name of the already-mirrored CRM team, null when none exists.
     * @param ?string $accountTenantId       ChatwootAccount.tenantId.
     * @param ?string $tenantFromTeams       What TenantResolver derives from the account's teams.
     * @param bool $alreadyOtherUserTeam     Whether the Tenant already lists the CRM team.
     */
    private function makeMirror(
        ?string $existingTeamName = null,
        ?string $accountTenantId = 'tenant-1',
        ?string $tenantFromTeams = null,
        bool $alreadyOtherUserTeam = false,
        string $tenantName = 'Acme',
    ): TenantTeamMirror {
        $team = $this->createMock(Entity::class);
        $team->method('getId')->willReturn('team-1');
        $team->method('get')->willReturnCallback(
            // A team created by ensureTeam() carries the name it was created with.
            fn (string $attr) => $attr === 'name'
                ? ($this->renamedTo ? end($this->renamedTo) : ($existingTeamName ?? $this->ensured[0][1] ?? null))
                : null,
        );
        $team->method('set')->willReturnCallback(function (string $attr, $value) use ($team): Entity {
            $this->assertSame('name', $attr);
            $this->renamedTo[] = $value;

            return $team;
        });

        $provisioner = $this->createMock(TenantTeamProvisioner::class);
        $provisioner->method('ensureTeam')->willReturnCallback(
            function (string $key, string $name) use ($team): Entity {
                $this->ensured[] = [$key, $name];

                return $team;
            },
        );
        $provisioner->method('findTeam')->willReturn($existingTeamName === null ? null : $team);

        $resolver = $this->createMock(TenantResolver::class);
        $resolver->method('resolveUniqueFromTeamIds')->willReturn($tenantFromTeams);

        $account = $this->createMock(CoreEntity::class);
        $account->method('get')->willReturnCallback(
            static fn (string $attr) => $attr === 'tenantId' ? $accountTenantId : null,
        );
        $account->method('getLinkMultipleIdList')->willReturn(['team-base']);

        $tenant = $this->createMock(Entity::class);
        $tenant->method('getId')->willReturn('tenant-1');
        $tenant->method('get')->willReturnCallback(
            static fn (string $attr) => $attr === 'name' ? $tenantName : null,
        );

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getEntityById')->willReturnCallback(
            fn (string $entityType, string $id): ?Entity => match ($entityType) {
                'ChatwootAccount' => $account,
                'Tenant' => $id === 'tenant-1' ? $tenant : null,
                default => null,
            },
        );
        $entityManager->method('removeEntity')->willReturnCallback(function (Entity $entity): void {
            $this->removedTeamIds[] = (string) $entity->getId();
        });

        $otherUserTeams = $this->createMock(RDBRelation::class);
        $otherUserTeams->method('isRelatedById')->willReturn($alreadyOtherUserTeam);
        $otherUserTeams->method('relateById')->willReturnCallback(function (string $id): void {
            $this->relatedOtherUserTeams[] = $id;
        });

        $tenantsOfTeam = $this->createMock(RDBRelation::class);
        $tenantsOfTeam->method('find')->willReturn(new EntityCollection([$tenant]));
        $tenantsOfTeam->method('unrelate')->willReturnCallback(function (Entity $entity): void {
            $this->unrelatedTenantIds[] = (string) $entity->getId();
        });

        $repository = $this->createMock(RDBRepository::class);
        $repository->method('getRelation')->willReturnCallback(
            function (Entity $entity, string $link) use ($otherUserTeams, $tenantsOfTeam): RDBRelation {
                return match ($link) {
                    'otherUserTeams' => $otherUserTeams,
                    'tenantsAsOtherUserTeam' => $tenantsOfTeam,
                };
            },
        );
        $entityManager->method('getRDBRepository')->willReturn($repository);

        return new TenantTeamMirror($entityManager, $provisioner, $resolver, $this->createMock(Log::class));
    }

    private function chatwootTeam(string $name = 'Comercial', string $id = 'ct-1'): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn($id);
        $entity->method('get')->willReturnCallback(
            static fn (string $attr) => match ($attr) {
                'name' => $name,
                'accountId' => 'account-1',
                default => null,
            },
        );

        return $entity;
    }

    public function testMirrorsTheChatwootTeamAsTenantSlashTeamName(): void
    {
        $teamId = $this->makeMirror()->ensureFor($this->chatwootTeam('Comercial'));

        $this->assertSame('team-1', $teamId);
        $this->assertSame([['chatwoot-team:ct-1', 'Acme / Comercial']], $this->ensured);
    }

    public function testRegistersTheTeamAsAnOtherUserTeamOfTheTenant(): void
    {
        $this->makeMirror()->ensureFor($this->chatwootTeam());

        $this->assertSame(['team-1'], $this->relatedOtherUserTeams);
    }

    public function testIsIdempotentWhenThePairIsAlreadyInPlace(): void
    {
        $mirror = $this->makeMirror(existingTeamName: 'Acme / Comercial', alreadyOtherUserTeam: true);

        $this->assertSame('team-1', $mirror->ensureFor($this->chatwootTeam()));
        $this->assertSame([], $this->renamedTo);
        $this->assertSame([], $this->relatedOtherUserTeams);
    }

    /**
     * Covers both a renamed Chatwoot team and a renamed Tenant: the CRM team
     * name is derived, so it follows.
     */
    public function testRenamesTheCrmTeamWhenTheDerivedNameChanged(): void
    {
        $mirror = $this->makeMirror(existingTeamName: 'Acme / Vendas', alreadyOtherUserTeam: true);

        $mirror->ensureFor($this->chatwootTeam('Comercial'));

        $this->assertSame(['Acme / Comercial'], $this->renamedTo);
    }

    public function testFallsBackToTheSingleTenantOfTheAccountTeams(): void
    {
        $mirror = $this->makeMirror(accountTenantId: null, tenantFromTeams: 'tenant-1');

        $this->assertSame('team-1', $mirror->ensureFor($this->chatwootTeam()));
    }

    /**
     * No tenant, or more than one: refuse rather than hand some tenant a team
     * that is not theirs.
     */
    public function testMirrorsNothingWhenTheTenantCannotBeTold(): void
    {
        $mirror = $this->makeMirror(accountTenantId: null, tenantFromTeams: null);

        $this->assertNull($mirror->ensureFor($this->chatwootTeam()));
        $this->assertSame([], $this->ensured);
        $this->assertSame([], $this->relatedOtherUserTeams);
    }

    public function testMirrorsNothingForANamelessChatwootTeam(): void
    {
        $this->assertNull($this->makeMirror()->ensureFor($this->chatwootTeam('  ')));
        $this->assertSame([], $this->ensured);
    }

    public function testRemovesTheCrmTeamOfADeletedChatwootTeam(): void
    {
        $this->makeMirror(existingTeamName: 'Acme / Comercial')->removeFor($this->chatwootTeam());

        $this->assertSame(['tenant-1'], $this->unrelatedTenantIds);
        $this->assertSame(['team-1'], $this->removedTeamIds);
    }

    public function testRemovingIsANoOpWhenNothingWasMirrored(): void
    {
        $this->makeMirror()->removeFor($this->chatwootTeam());

        $this->assertSame([], $this->removedTeamIds);
    }

    /**
     * Team.name is varchar(100); ChatwootTeam.name allows 255.
     */
    public function testClampsTheNameToTheTeamNameColumn(): void
    {
        $mirror = $this->makeMirror();

        $this->assertSame('Acme / Comercial', $mirror->teamName('Acme', 'Comercial'));

        $longTenant = str_repeat('T', 100);
        $name = $mirror->teamName($longTenant, 'Comercial');
        $this->assertSame(100, mb_strlen($name));
        $this->assertStringEndsWith(' / Comercial', $name);

        $name = $mirror->teamName($longTenant, str_repeat('c', 255));
        $this->assertSame(100, mb_strlen($name));
        $this->assertStringStartsWith(str_repeat('T', 30) . ' / c', $name);
    }
}
