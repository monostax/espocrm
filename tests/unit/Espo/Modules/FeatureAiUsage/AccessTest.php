<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureAiUsage;

use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\FeatureAiUsage\Services\Access;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

class AccessTest extends TestCase
{
    public function testAdminOfTenantACannotUseMembershipInTenantBToReadItsBilling(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getTeamIdList')->willReturn(['admin-a', 'member-b']);
        $memberships = $this->createMock(UserTenantResolver::class);
        $memberships->method('resolveTenantIds')->willReturn(['a', 'b']);
        $resolver = $this->createMock(TenantResolver::class);
        $resolver->expects($this->once())->method('resolveAllFromTeamIds')->with(['admin-a'])->willReturn(['a']);
        $team = $this->createMock(Entity::class);
        $team->method('getId')->willReturn('admin-a');
        $builder = $this->createMock(RDBSelectBuilder::class);
        foreach (['where', 'join', 'distinct'] as $method) $builder->method($method)->willReturnSelf();
        $builder->method('find')->willReturn(new EntityCollection([$team]));
        $repo = $this->createMock(RDBRepository::class);
        $repo->method('select')->willReturn($builder);
        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->with('Team')->willReturn($repo);
        $em->expects($this->never())->method('getEntityById');
        $access = new Access($user, $em, $resolver, $memberships);
        $this->expectException(Forbidden::class);
        $access->assertTenant('b');
    }

    public function testDirectSeededAdminRoleAppliesOnlyToMemberships(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getLinkMultipleIdList')->with('roles')->willReturn([md5('tenant-admin')]);
        $memberships = $this->createMock(UserTenantResolver::class);
        $memberships->method('resolveTenantIds')->willReturn(['a', 'b']);
        $access = new Access($user, $this->createMock(EntityManager::class), $this->createMock(TenantResolver::class), $memberships);
        $this->assertSame(['a', 'b'], $access->tenantIds());
        $this->expectException(Forbidden::class);
        $access->assertTenant('foreign');
    }

    public function testRegularMembersDoNotReceiveBillingAccess(): void
    {
        $user = $this->createMock(User::class);
        $memberships = $this->createMock(UserTenantResolver::class);
        $memberships->method('resolveTenantIds')->willReturn(['a']);
        $access = new Access($user, $this->createMock(EntityManager::class), $this->createMock(TenantResolver::class), $memberships);
        $this->assertSame([], $access->tenantIds());
    }

    public function testPortalAndApiUsersAreRejectedEvenIfAdminFlagIsSet(): void
    {
        foreach (['isPortal', 'isApi'] as $type) {
            $user = $this->createMock(User::class);
            $user->method($type)->willReturn(true);
            $user->method('isAdmin')->willReturn(true);
            $memberships = $this->createMock(UserTenantResolver::class);
            $memberships->expects($this->never())->method('resolveTenantIds');
            $access = new Access($user, $this->createMock(EntityManager::class), $this->createMock(TenantResolver::class), $memberships);
            $this->assertSame([], $access->tenantIds());
        }
    }
}
