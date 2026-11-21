<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Classes\Acl\User;

use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\AclManager;
use Espo\Entities\User;
use Espo\Modules\Global\Classes\Acl\User\AccessChecker;
use Espo\Modules\Global\Tools\Tenant\TenantAdminScope;
use Espo\Modules\Global\Tools\Tenant\TenantVisibility;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * Tenant admins may manage users in their teams. A member of several
 * workspaces (Monostax staff) shares a team with them, but deactivating,
 * deleting or re-addressing that account changes it in every workspace, and
 * re-addressing is a takeover (the email is the login, password recovery
 * mails it). Edit and delete therefore also require coverage. Bulk update
 * checks edit per record, so it skips such users too.
 */
class AccessCheckerTest extends TestCase
{
    private function checker(bool $covers, bool $administers = true): AccessChecker
    {
        $defaults = $this->createMock(DefaultAccessChecker::class);
        $defaults->method('checkEdit')->willReturn(true);
        $defaults->method('checkDelete')->willReturn(true);
        $defaults->method('checkEntityEdit')->willReturn(true);

        $visibility = $this->createMock(TenantVisibility::class);
        $visibility->method('covers')->willReturn($covers);

        $scope = $this->createMock(TenantAdminScope::class);
        $scope->method('administers')->willReturn($administers);

        return new class(
            $defaults,
            $this->createMock(AclManager::class),
            $this->createMock(EntityManager::class),
            $visibility,
            $scope,
        ) extends AccessChecker {
            public function __construct(
                DefaultAccessChecker $defaults,
                AclManager $aclManager,
                EntityManager $entityManager,
                private TenantVisibility $visibility,
                private TenantAdminScope $scope,
            ) {
                parent::__construct($defaults, $aclManager, $entityManager);
            }

            protected function tenantVisibility(): TenantVisibility
            {
                return $this->visibility;
            }

            protected function tenantAdminScope(): TenantAdminScope
            {
                return $this->scope;
            }
        };
    }

    /**
     * @param list<string> $teamIds
     */
    private function user(string $id, array $teamIds): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getLinkMultipleIdList')->willReturn($teamIds);
        $user->method('isEffectiveAdmin')->willReturn(false);
        $user->method('isAdmin')->willReturn(false);
        $user->method('isSuperAdmin')->willReturn(false);
        $user->method('isSystem')->willReturn(false);

        return $user;
    }

    public function testTenantAdminCannotEditOrDeleteStaffOfOtherWorkspaces(): void
    {
        $checker = $this->checker(false);
        $tenantAdmin = $this->user('nowle-admin', ['team-nowle']);
        $staff = $this->user('staff', ['team-nowle', 'team-ifv']);
        $data = $this->createMock(ScopeData::class);

        $this->assertFalse($checker->checkEntityEdit($tenantAdmin, $staff, $data));
        $this->assertFalse($checker->checkEntityDelete($tenantAdmin, $staff, $data));
    }

    public function testTenantAdminStillManagesTheirOwnUsers(): void
    {
        $checker = $this->checker(true);
        $tenantAdmin = $this->user('nowle-admin', ['team-nowle']);
        $colleague = $this->user('nowle-user', ['team-nowle']);
        $data = $this->createMock(ScopeData::class);

        $this->assertTrue($checker->checkEntityEdit($tenantAdmin, $colleague, $data));
        $this->assertTrue($checker->checkEntityDelete($tenantAdmin, $colleague, $data));
    }

    public function testPlainMemberCanEditButNotDeleteAColleague(): void
    {
        $checker = $this->checker(true, administers: false);
        $member = $this->user('nowle-user', ['team-nowle']);
        $owner = $this->user('nowle-owner', ['team-nowle']);
        $data = $this->createMock(ScopeData::class);

        $this->assertTrue($checker->checkEntityEdit($member, $owner, $data));
        $this->assertFalse($checker->checkEntityDelete($member, $owner, $data));
    }

    public function testStaffCanStillEditThemselves(): void
    {
        $checker = $this->checker(false);
        $staff = $this->user('staff', ['team-nowle', 'team-ifv']);

        $this->assertTrue($checker->checkEntityEdit($staff, $staff, $this->createMock(ScopeData::class)));
    }
}
