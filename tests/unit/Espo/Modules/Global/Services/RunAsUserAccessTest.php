<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Services;

use Espo\Core\ApplicationState;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\Global\Services\RunAsUserAccess;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * An Espo admin bypasses ACL entirely, so delegating an automation/journey to an
 * admin identity grants instance-wide reads. Only an instance admin may do that;
 * a tenant-admin borrowing an admin run-as user is a privilege escalation.
 */
class RunAsUserAccessTest extends TestCase
{
    private function user(
        string $id,
        bool $isAdmin = false,
        bool $isSuperAdmin = false,
        bool $isRegular = true,
    ): User {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('isActive')->willReturn(true);
        $user->method('isSystem')->willReturn(false);
        $user->method('isPortal')->willReturn(false);
        $user->method('isRegular')->willReturn($isRegular);
        $user->method('isApi')->willReturn(false);
        $user->method('isAdmin')->willReturn($isAdmin);
        $user->method('isSuperAdmin')->willReturn($isSuperAdmin);
        $user->method('getTeamIdList')->willReturn(['team-1']);

        return $user;
    }

    private function makeAccess(User $actor, User $target): RunAsUserAccess
    {
        $applicationState = $this->createMock(ApplicationState::class);
        $applicationState->method('isLogged')->willReturn(true);
        $applicationState->method('getUser')->willReturn($actor);

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getEntityById')->willReturn($target);

        // Tenant membership now comes from UserTenantResolver (union of the
        // explicit tenantUser links and team-derived tenants). These tests cover
        // delegation eligibility, not membership, so an empty set is fine.
        $userTenantResolver = $this->createMock(UserTenantResolver::class);
        $userTenantResolver->method('resolveTenantIds')->willReturn([]);

        return new RunAsUserAccess($entityManager, $applicationState, $userTenantResolver);
    }

    public function testNonAdminSetterCannotDelegateToAdminUser(): void
    {
        $actor = $this->user('tenant-admin-1');
        $target = $this->user('admin-9', isAdmin: true, isRegular: false);

        $access = $this->makeAccess($actor, $target);

        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('may not be an administrator');

        $access->assertCanSet('admin-9', 'tenant-1');
    }

    public function testNonAdminSetterCannotDelegateToSuperAdminUser(): void
    {
        $actor = $this->user('tenant-admin-1');
        $target = $this->user('super-9', isSuperAdmin: true, isRegular: false);

        $access = $this->makeAccess($actor, $target);

        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('may not be an administrator');

        $access->assertCanSet('super-9', 'tenant-1');
    }

    public function testInstanceAdminMayStillDelegateToAdminUser(): void
    {
        $actor = $this->user('admin-1', isAdmin: true, isRegular: false);
        $target = $this->user('admin-9', isAdmin: true, isRegular: false);

        $access = $this->makeAccess($actor, $target);

        // No exception: instance admins retain the cross-tenant capability.
        $access->assertCanSet('admin-9', 'tenant-1');
        $this->addToAssertionCount(1);
    }

    public function testAdminUserIsNeverATenantMemberForDelegation(): void
    {
        $actor = $this->user('tenant-admin-1');
        $target = $this->user('admin-9', isAdmin: true, isRegular: false);

        $access = $this->makeAccess($actor, $target);

        $this->assertFalse($access->userBelongsToTenant('admin-9', 'tenant-1'));
    }

    public function testEmptyRunAsUserIsNoOp(): void
    {
        $actor = $this->user('tenant-admin-1');
        $target = $this->user('someone');

        $access = $this->makeAccess($actor, $target);

        $access->assertCanSet(null, 'tenant-1');
        $access->assertCanSet('', 'tenant-1');
        $this->addToAssertionCount(1);
    }
}
