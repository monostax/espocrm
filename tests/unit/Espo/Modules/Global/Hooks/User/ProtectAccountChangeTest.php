<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Hooks\User;

use Espo\Core\ApplicationState;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\Global\Hooks\User\ProtectAccountChange;
use Espo\Modules\Global\Tools\Tenant\TenantAdminScope;
use Espo\ORM\EntityManager;
use Espo\Repositories\EmailAddress;
use PHPUnit\Framework\TestCase;

/**
 * The base `tenant` role lets every member edit users in their teams.
 * - The email is the login and password recovery mails it, so a member who
 *   sets the owner's address to their own owns the owner's account.
 * - Deactivating locks a person out; only their workspace admins may do it.
 */
class ProtectAccountChangeTest extends TestCase
{
    private function hook(string $actorId, bool $actorIsAdmin = false, bool $administers = false): ProtectAccountChange
    {
        $actor = $this->createMock(User::class);
        $actor->method('getId')->willReturn($actorId);
        $actor->method('isAdmin')->willReturn($actorIsAdmin);

        $state = $this->createMock(ApplicationState::class);
        $state->method('isLogged')->willReturn(true);
        $state->method('getUser')->willReturn($actor);

        $addresses = $this->createMock(EmailAddress::class);
        $addresses->method('getEmailAddressData')->willReturn([(object) ['emailAddress' => 'owner@studio.com', 'primary' => true]]);

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getRepository')->with('EmailAddress')->willReturn($addresses);

        $scope = $this->createMock(TenantAdminScope::class);
        $scope->method('administers')->willReturn($administers);

        return new ProtectAccountChange($state, $entityManager, $scope);
    }

    /**
     * @param array<string, mixed> $changed attribute => new value
     */
    private function owner(array $changed, bool $isRegular = true): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('owner');
        $user->method('isNew')->willReturn(false);
        $user->method('isRegular')->willReturn($isRegular);
        $user->method('isAdmin')->willReturn(false);
        $user->method('isAttributeChanged')->willReturnCallback(fn (string $name) => array_key_exists($name, $changed));
        $user->method('get')->willReturnCallback(fn (string $name) => $changed[$name] ?? null);

        return $user;
    }

    public function testMemberCannotReaddressTheOwner(): void
    {
        $this->expectException(Forbidden::class);

        $this->hook('member')->beforeSave($this->owner(['emailAddress' => 'member@gmail.com']), []);
    }

    public function testWorkspaceAdminCannotReaddressSomeoneEither(): void
    {
        $this->expectException(Forbidden::class);

        $this->hook('tenant-admin', administers: true)->beforeSave($this->owner(['emailAddress' => 'x@gmail.com']), []);
    }

    public function testMemberCannotAddASecondaryAddressToTheOwner(): void
    {
        $this->expectException(Forbidden::class);

        $this->hook('member')->beforeSave($this->owner(['emailAddressData' => [
            (object) ['emailAddress' => 'owner@studio.com', 'primary' => true],
            (object) ['emailAddress' => 'member@gmail.com', 'primary' => false],
        ]]), []);
    }

    public function testMemberCannotDeactivateTheOwner(): void
    {
        $this->expectException(Forbidden::class);

        $this->hook('member')->beforeSave($this->owner(['isActive' => false]), []);
    }

    public function testWorkspaceAdminMayDeactivate(): void
    {
        $this->hook('tenant-admin', administers: true)->beforeSave($this->owner(['isActive' => false]), []);
        $this->hook('member')->beforeSave(
            $this->owner(['isActive' => false]),
            [ProtectAccountChange::WORKSPACE_ADMIN_VERIFIED => true],
        );
        $this->addToAssertionCount(1);
    }

    public function testUnchangedAddressesOtherEditsAndAiIdentitiesPass(): void
    {
        $this->hook('member')->beforeSave($this->owner(['firstName' => 'Bia']), []);
        $this->hook('member')->beforeSave($this->owner(['emailAddress' => 'Owner@Studio.com']), []);
        $this->hook('member')->beforeSave($this->owner(['isActive' => false], isRegular: false), []);
        $this->addToAssertionCount(1);
    }

    public function testOwnAccountAndInstanceAdminPass(): void
    {
        $this->hook('owner')->beforeSave($this->owner(['emailAddress' => 'new@studio.com']), []);
        $this->hook('admin', true)->beforeSave($this->owner(['emailAddress' => 'new@studio.com', 'isActive' => false]), []);
        $this->addToAssertionCount(1);
    }
}
