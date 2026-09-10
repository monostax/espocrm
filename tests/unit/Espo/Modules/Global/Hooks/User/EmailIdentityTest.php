<?php

namespace tests\unit\Espo\Modules\Global\Hooks\User;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\ORM\Helper;
use Espo\Entities\User;
use Espo\Modules\Global\Hooks\User\EmailIdentity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

class EmailIdentityTest extends TestCase
{
    private function user(array $values, bool $new = true): User
    {
        $attributes = array_fill_keys([
            'id', 'type', 'userName', 'emailAddress', 'emailAddressData', 'password', 'isActive',
        ], ['type' => 'varchar']);
        $attributes['emailAddressData'] = ['type' => 'jsonArray'];
        $attributes['isActive'] = ['type' => 'bool'];
        $user = new User('User', ['attributes' => $attributes], $this->createMock(EntityManager::class), $this->createMock(Helper::class));
        $user->set(['type' => 'regular', 'isActive' => true] + $values);
        if (!$new) {
            $user->setAsFetched();
        }
        return $user;
    }

    private function hook(array $others = [], ?string $excludedId = null): EmailIdentity
    {
        $select = $this->createMock(RDBSelectBuilder::class);
        $select->method('find')->willReturn(new EntityCollection($others));
        $repository = $this->createMock(RDBRepository::class);
        $repository->method('where')->willReturnCallback(function ($where) use ($select, $excludedId) {
            $this->assertArrayHasKey('OR', $where);
            if ($excludedId) {
                $this->assertSame($excludedId, $where['id!=']);
            }
            return $select;
        });
        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->with('User')->willReturn($repository);
        return new EmailIdentity($em);
    }

    public function testCreationDerivesUsernameAndSupportsLongPlusAddress(): void
    {
        $email = str_repeat('a', 55) . '+agent@example.com';
        $user = $this->user(['emailAddress' => '  ' . strtoupper($email) . ' ', 'userName' => 'ignored']);
        $this->hook()->process($user);
        $this->assertSame($email, $user->get('userName'));
        $this->assertSame($email, $user->get('emailAddress'));
    }

    public function testPrimaryDataWinsOverStaleScalarAndPreservesEmailFlags(): void
    {
        $user = $this->user(['id' => 'u', 'userName' => 'old@example.com', 'emailAddress' => 'old@example.com'], false);
        $user->set('emailAddressData', [
            (object) ['emailAddress' => 'secondary@example.com', 'primary' => false],
            (object) ['emailAddress' => ' NEW+Agent@Example.com ', 'primary' => true, 'optOut' => true],
        ]);
        $hook = $this->hook([], 'u');
        $hook->process($user);
        $hook->beforeSave($user, []);
        $this->assertSame('new+agent@example.com', $user->get('userName'));
        $this->assertSame('new+agent@example.com', $user->get('emailAddress'));
        $this->assertTrue($user->get('emailAddressData')[1]->optOut);
    }

    public function testDataWithoutPrimaryUsesFirstEntryLikeEmailSaver(): void
    {
        $user = $this->user(['emailAddressData' => [['emailAddress' => ' FIRST@example.com ']]]);
        $this->hook()->process($user);
        $this->assertSame('first@example.com', $user->get('userName'));
        $this->assertTrue($user->get('emailAddressData')[0]->primary);
    }

    public function testScalarEmailUpdateWinsOverUnchangedLoadedData(): void
    {
        $user = $this->user([
            'id' => 'u', 'userName' => 'old@example.com', 'emailAddress' => 'old@example.com',
            'emailAddressData' => [(object) ['emailAddress' => 'old@example.com', 'primary' => true]],
        ], false);
        $user->set('emailAddress', 'new@example.com');
        $this->hook([], 'u')->process($user);
        $this->assertSame('new@example.com', $user->get('userName'));
    }

    public function testIndependentUsernameChangeCannotBreakIdentity(): void
    {
        $user = $this->user(['userName' => 'a@example.com', 'emailAddress' => 'a@example.com'], false);
        $user->set('userName', 'different');
        $this->hook()->process($user);
        $this->assertSame('a@example.com', $user->get('userName'));
    }

    public function testDuplicateLegacyPrimaryEmailIsRejected(): void
    {
        $other = $this->user(['emailAddress' => 'a@example.com', 'userName' => 'legacy'], false);
        $other->set('isActive', false);
        $this->expectException(Conflict::class);
        $this->hook([$other])->process($this->user(['emailAddress' => 'A@example.com']));
    }

    public function testExistingUsernameCannotBeClaimedByAnotherEmail(): void
    {
        $other = $this->user(['emailAddress' => 'other@example.com', 'userName' => 'a@example.com'], false);
        $this->expectException(Conflict::class);
        $this->hook([$other])->process($this->user(['emailAddress' => 'a@example.com']));
    }

    public function testSecondaryAddressDoesNotReserveLogin(): void
    {
        $other = $this->user(['emailAddress' => 'other@example.com', 'userName' => 'legacy'], false);
        $user = $this->user(['emailAddress' => 'a@example.com']);
        $this->hook([$other])->process($user);
        $this->assertSame('a@example.com', $user->get('userName'));
    }

    public function testMissingEmailOnCreateIsRejected(): void
    {
        $this->expectException(BadRequest::class);
        $this->hook()->process($this->user(['userName' => 'username-only']));
    }

    public function testClearingEmailDataCannotLeaveAnOldLogin(): void
    {
        $user = $this->user(['userName' => 'a@example.com', 'emailAddress' => 'a@example.com'], false);
        $user->set('emailAddressData', []);
        $this->expectException(BadRequest::class);
        $this->hook()->process($user);
    }

    public function testSystemAndApiUsersKeepMachineUsernames(): void
    {
        foreach (['system', 'api'] as $type) {
            $user = $this->user(['userName' => 'machine']);
            $user->set('type', $type);
            $this->hook()->process($user);
            $this->assertSame('machine', $user->get('userName'));
        }
    }

    public function testLegacyNoEmailUserCanBeDeactivated(): void
    {
        $user = $this->user(['userName' => 'bootstrap'], false);
        $user->set('isActive', false);
        $this->hook()->process($user);
        $this->assertSame('bootstrap', $user->get('userName'));
        $this->assertFalse($user->isActive());
    }
}
