<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Classes\RecordHooks;

use Espo\Core\Field\Link;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Record\CreateParams;
use Espo\Entities\User;
use Espo\Modules\Global\Classes\RecordHooks\FillTeamAndTenantOnCreate;
use Espo\Modules\Global\Tools\Tenant\TenantFromTeamsSync;
use Espo\ORM\Defs;
use Espo\ORM\Defs\EntityDefs;
use Espo\ORM\Defs\FieldDefs;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * API and MCP creates on tenant-isolated entities: the hook fills only what is
 * missing (default team when teams are required and empty, tenant from teams)
 * and never changes a create that already names its teams or tenant.
 */
class FillTeamAndTenantOnCreateTest extends TestCase
{
    private int $tenantSyncCalls = 0;

    private function hook(
        ?string $defaultTeamId = 'team-a',
        bool $teamsRequired = true,
        bool $portal = false,
    ): FillTeamAndTenantOnCreate {
        $user = $this->createMock(User::class);
        $user->method('isPortal')->willReturn($portal);
        $user->method('getDefaultTeam')->willReturn($defaultTeamId ? Link::create($defaultTeamId) : null);

        $field = FieldDefs::fromRaw(['type' => 'linkMultiple', 'required' => $teamsRequired], 'teams');

        $entityDefs = $this->createMock(EntityDefs::class);
        $entityDefs->method('tryGetField')->willReturnCallback(fn (string $name) => $name === 'teams' ? $field : null);

        $defs = $this->createMock(Defs::class);
        $defs->method('getEntity')->willReturn($entityDefs);

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getDefs')->willReturn($defs);

        $sync = $this->createMock(TenantFromTeamsSync::class);
        $sync->method('applyBeforeSave')->willReturnCallback(function (CoreEntity $entity) {
            $this->tenantSyncCalls++;
            // Same shape as production: team-a belongs to tenant-a.
            if (in_array('team-a', $entity->getLinkMultipleIdList('teams'), true)) {
                $entity->set('tenantId', 'tenant-a');
            }
        });

        return new FillTeamAndTenantOnCreate($user, $entityManager, $sync);
    }

    /**
     * @param list<string> $teamIds
     */
    private function entity(array $teamIds = [], ?string $tenantId = null): CoreEntity
    {
        $entity = new CoreEntity('Contact', ['attributes' => [
            'id' => ['type' => 'id'],
            'tenantId' => ['type' => 'foreignId'],
            'teamsIds' => ['type' => 'jsonArray', 'notStorable' => true],
            'teamsNames' => ['type' => 'jsonObject', 'notStorable' => true],
        ]]);

        $entity->setMultiple(['teamsIds' => $teamIds, 'tenantId' => $tenantId]);

        return $entity;
    }

    public function testFillsDefaultTeamAndTenantWhenBothMissing(): void
    {
        $entity = $this->entity();

        $this->hook()->process($entity, CreateParams::create());

        $this->assertSame(['team-a'], $entity->getLinkMultipleIdList('teams'));
        $this->assertSame('tenant-a', $entity->get('tenantId'));
    }

    public function testKeepsExplicitTeams(): void
    {
        $entity = $this->entity(['team-b']);

        $this->hook()->process($entity, CreateParams::create());

        $this->assertSame(['team-b'], $entity->getLinkMultipleIdList('teams'));
        $this->assertSame(1, $this->tenantSyncCalls, 'The tenant still comes from the given teams.');
    }

    public function testKeepsExplicitTenant(): void
    {
        $entity = $this->entity([], 'tenant-z');

        $this->hook()->process($entity, CreateParams::create());

        $this->assertSame('tenant-z', $entity->get('tenantId'));
        $this->assertSame(0, $this->tenantSyncCalls);
    }

    public function testOptionalTeamsAreLeftEmpty(): void
    {
        $entity = $this->entity();

        $this->hook(teamsRequired: false)->process($entity, CreateParams::create());

        $this->assertSame([], $entity->getLinkMultipleIdList('teams'));
    }

    public function testUserWithoutDefaultTeamGetsNothingAdded(): void
    {
        $entity = $this->entity();

        $this->hook(defaultTeamId: null)->process($entity, CreateParams::create());

        $this->assertSame([], $entity->getLinkMultipleIdList('teams'));
        $this->assertNull($entity->get('tenantId'));
    }

    public function testPortalUsersAreSkipped(): void
    {
        $entity = $this->entity();

        $this->hook(portal: true)->process($entity, CreateParams::create());

        $this->assertSame([], $entity->getLinkMultipleIdList('teams'));
        $this->assertSame(0, $this->tenantSyncCalls);
    }
}
