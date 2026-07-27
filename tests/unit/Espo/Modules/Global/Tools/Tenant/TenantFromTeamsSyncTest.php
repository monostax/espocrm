<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Tools\Tenant;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Modules\Global\Tools\Tenant\TenantFromTeamsSync;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use PHPUnit\Framework\TestCase;

/**
 * `tenantId` is the multi-tenancy boundary and is derived from team membership.
 * Deriving it by taking an arbitrary first match silently misfiles a record
 * into one of several tenants, which is indistinguishable from correct after
 * the fact — so ambiguity must be refused. The ownership team (e.g.
 * Funnel.teamId) must still take precedence over the broader `teams` ACL list.
 */
class TenantFromTeamsSyncTest extends TestCase
{
    /**
     * @param array<string, list<string>> $tenantsPerTierKey Serialized tier => tenants it resolves to.
     */
    private function makeSync(array $tenantsPerTierKey): TenantFromTeamsSync
    {
        $resolver = $this->createMock(TenantResolver::class);

        $resolver->method('resolveAllFromTeamIds')->willReturnCallback(
            function (array $teamIds) use ($tenantsPerTierKey): array {
                return $tenantsPerTierKey[implode(',', $teamIds)] ?? [];
            },
        );

        return new TenantFromTeamsSync($resolver);
    }

    /**
     * @param list<string> $teamIds
     */
    private function entity(?string $tenantId, array $teamIds, bool $hasTeamsField = true): CoreEntity
    {
        $entity = $this->createMock(CoreEntity::class);

        // `set` is deliberately NOT configured here: each test asserts on it,
        // and PHPUnit lets the first registered matcher handle the call, so a
        // stub here would swallow those expectations. applyBeforeSave() reads
        // tenantId once up front and never reads back what it wrote.
        $entity->method('get')->willReturnCallback(
            static fn (string $attr) => $attr === 'tenantId' ? $tenantId : null,
        );

        $entity->method('hasLinkMultipleField')->willReturn($hasTeamsField);
        $entity->method('getLinkMultipleIdList')->willReturn($teamIds);

        return $entity;
    }

    public function testDerivesTenantFromEntityTeams(): void
    {
        $sync = $this->makeSync(['team-a' => ['tenant-1']]);
        $entity = $this->entity(null, ['team-a']);

        $entity->expects($this->once())->method('set')->with('tenantId', 'tenant-1');

        $sync->applyBeforeSave($entity, 'Funnel');
    }

    /**
     * The preferred (ownership) team decides the tenant even when the broader
     * `teams` ACL list reaches another tenant. Folding both tiers into one list
     * would turn this legitimate save into an ambiguity error.
     */
    public function testPreferredTeamWinsOverBroaderTeamsList(): void
    {
        $sync = $this->makeSync([
            'team-owner' => ['tenant-1'],
            'team-owner,team-shared' => ['tenant-1', 'tenant-2'],
            'team-shared' => ['tenant-2'],
        ]);

        $entity = $this->entity(null, ['team-shared']);

        $entity->expects($this->once())->method('set')->with('tenantId', 'tenant-1');

        $sync->applyBeforeSave($entity, 'Funnel', ['team-owner']);
    }

    public function testFallsBackToEntityTeamsWhenPreferredTeamHasNoTenant(): void
    {
        $sync = $this->makeSync([
            'team-functional' => [],
            'team-a' => ['tenant-1'],
        ]);

        $entity = $this->entity(null, ['team-a']);

        $entity->expects($this->once())->method('set')->with('tenantId', 'tenant-1');

        $sync->applyBeforeSave($entity, 'Funnel', ['team-functional']);
    }

    public function testAmbiguousTeamsWithinATierAreRefused(): void
    {
        $sync = $this->makeSync(['team-a,team-b' => ['tenant-1', 'tenant-2']]);
        $entity = $this->entity(null, ['team-a', 'team-b']);

        $entity->expects($this->never())->method('set');

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('more than one Tenant');

        $sync->applyBeforeSave($entity, 'Funnel');
    }

    public function testAmbiguousPreferredTierIsRefusedRatherThanFallingThrough(): void
    {
        $sync = $this->makeSync([
            'team-x,team-y' => ['tenant-1', 'tenant-2'],
            'team-a' => ['tenant-3'],
        ]);

        $entity = $this->entity(null, ['team-a']);

        $this->expectException(BadRequest::class);

        $sync->applyBeforeSave($entity, 'Funnel', ['team-x', 'team-y']);
    }

    public function testTeamsThatResolveToNoTenantAreRefused(): void
    {
        $sync = $this->makeSync(['team-orphan' => []]);
        $entity = $this->entity(null, ['team-orphan']);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Cannot determine tenant');

        $sync->applyBeforeSave($entity, 'Funnel');
    }

    public function testExistingTenantIsNeverOverwritten(): void
    {
        $sync = $this->makeSync(['team-a' => ['tenant-2']]);
        $entity = $this->entity('tenant-1', ['team-a']);

        $entity->expects($this->never())->method('set');

        $sync->applyBeforeSave($entity, 'Funnel');
    }

    /**
     * No teams at all is left to the `required` field validators, not turned
     * into a confusing tenant-derivation error.
     */
    public function testNoTeamsIsANoOp(): void
    {
        $sync = $this->makeSync([]);
        $entity = $this->entity(null, []);

        $entity->expects($this->never())->method('set');

        $sync->applyBeforeSave($entity, 'Funnel');
    }
}
