<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Tools\Tenant;

use Espo\Core\Acl\Permission;
use Espo\Core\Acl\Table;
use Espo\Entities\User;
use Espo\Modules\FeatureSelfService\Tools\Plans\SeatAclManager;
use Espo\Modules\Global\Tools\Tenant\TenantAclManager;
use Espo\Modules\Global\Tools\Tenant\TenantVisibility;
use PHPUnit\Framework\TestCase;

/**
 * GET /User/{id}/stream returns the target's whole feed, filtered with the
 * TARGET's access. With `user` permission at team level, anyone sharing one
 * team with a multi-tenant staff member could read every tenant's deals in it.
 * The same holds for the calendar. Both must also require coverage.
 */
class TenantAclManagerTest extends TestCase
{
    private function manager(bool $covers, string $level = Table::LEVEL_YES): TenantAclManager
    {
        $visibility = $this->createMock(TenantVisibility::class);
        $visibility->method('covers')->willReturn($covers);
        $visibility->method('coversUserId')->willReturn($covers);

        $manager = $this->getMockBuilder(TenantAclManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPermissionLevel', 'getTenantVisibility'])
            ->getMock();

        $manager->method('getPermissionLevel')->willReturn($level);
        $manager->method('getTenantVisibility')->willReturn($visibility);

        return $manager;
    }

    private function user(string $id): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }

    public function testFeedAndCalendarOfUncoveredUserAreRefused(): void
    {
        $manager = $this->manager(false);
        $viewer = $this->user('nowle-user');
        $staff = $this->user('staff');

        $this->assertFalse($manager->checkUserPermission($viewer, $staff, Permission::USER));
        $this->assertFalse($manager->checkUserPermission($viewer, 'staff', Permission::USER_CALENDAR));
    }

    public function testCoveredUserKeepsRoleBehaviour(): void
    {
        $manager = $this->manager(true);

        $this->assertTrue($manager->checkUserPermission($this->user('a'), $this->user('b'), Permission::USER));
    }

    public function testRoleRefusalIsNotOverridden(): void
    {
        $manager = $this->manager(true, Table::LEVEL_NO);

        $this->assertFalse($manager->checkUserPermission($this->user('a'), $this->user('b'), Permission::USER));
    }

    public function testStaffStaysAssignableAndMentionable(): void
    {
        $manager = $this->manager(false);
        $viewer = $this->user('nowle-user');
        $staff = $this->user('staff');

        $this->assertTrue($manager->checkUserPermission($viewer, $staff, Permission::ASSIGNMENT));
        $this->assertTrue($manager->checkUserPermission($viewer, $staff, Permission::MENTION));
        $this->assertTrue($manager->checkUserPermission($viewer, $staff, Permission::MESSAGE));
    }

    public function testProductionAclManagerInheritsTheRule(): void
    {
        $this->assertTrue(is_subclass_of(SeatAclManager::class, TenantAclManager::class));
    }
}
