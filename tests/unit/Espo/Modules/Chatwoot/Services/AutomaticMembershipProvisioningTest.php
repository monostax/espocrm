<?php

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Error;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootAccountMembershipOrchestrator;
use Espo\Modules\Chatwoot\Services\ChatwootAccountUserMembershipService;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\ORM\TransactionManager;
use PHPUnit\Framework\TestCase;

class AutomaticMembershipProvisioningTest extends TestCase
{
    private ?Entity $identity = null;
    private ?Entity $membership = null;
    private bool $locked = false;
    private Entity $account;
    private Entity $user;
    private EntityManager $em;
    private ChatwootApiClient $api;
    private ChatwootAccountUserMembershipService $memberships;
    private TransactionManager $tm;

    private function entity(string $type, array $values): Entity
    {
        $attributes = [];
        foreach ($values as $key => $value) {
            $attributes[$key] = ['type' => is_array($value) ? 'jsonArray' : 'varchar'];
        }
        $attributes['userAccessToken'] = ['type' => 'varchar'];
        $entity = new CoreEntity($type, ['attributes' => $attributes]);
        $entity->set($values);
        return $entity;
    }

    protected function setUp(): void
    {
        $this->account = $this->entity('ChatwootAccount', [
            'id' => 'account', 'platformId' => 'platform', 'chatwootAccountId' => 9, 'teamsIds' => ['team'],
        ]);
        $this->user = $this->entity('User', [
            'id' => 'user', 'name' => 'Agent', 'emailAddress' => 'agent@example.com', 'teamsIds' => ['team'],
        ]);
        $platform = $this->entity('ChatwootPlatform', [
            'id' => 'platform', 'backendUrl' => 'https://chat.test', 'accessToken' => 'token',
        ]);
        $this->em = $this->createMock(EntityManager::class);
        $this->em->method('getEntityById')->willReturnMap([
            ['ChatwootPlatform', 'platform', $platform], ['User', 'user', $this->user],
        ]);
        $this->api = $this->createMock(ChatwootApiClient::class);
        $this->memberships = $this->createMock(ChatwootAccountUserMembershipService::class);
        $this->tm = $this->createMock(TransactionManager::class);
        $this->tm->method('start')->willReturnCallback(function () { $this->locked = false; });
        $this->em->method('getTransactionManager')->willReturn($this->tm);

        $lock = $this->createMock(RDBSelectBuilder::class);
        $lock->method('select')->with(['id'])->willReturnSelf();
        $lock->method('forUpdate')->willReturnCallback(function () use ($lock) {
            $this->locked = true;
            return $lock;
        });
        $lock->method('findOne')->willReturn($this->user);
        $users = $this->createMock(RDBRepository::class);
        $users->method('where')->with(['id' => 'user'])->willReturn($lock);

        $identities = $this->createMock(RDBSelectBuilder::class);
        $identities->method('where')->willReturnSelf();
        $identities->method('distinct')->willReturnSelf();
        $identities->method('limit')->willReturnSelf();
        $identities->method('find')->willReturnCallback(function () {
            $this->assertTrue($this->locked, 'Identity resolution must be serialized across jobs.');
            return new EntityCollection($this->identity ? [$this->identity] : []);
        });
        $identityRepository = $this->createMock(RDBRepository::class);
        $identityRepository->method('leftJoin')->with('conciergeForAccount')->willReturn($identities);
        $remoteLookup = $this->createMock(RDBSelectBuilder::class);
        $remoteLookup->method('findOne')->willReturn(null);
        $identityRepository->method('where')->willReturn($remoteLookup);

        $membershipLookup = $this->createMock(RDBSelectBuilder::class);
        $membershipLookup->method('findOne')->willReturnCallback(fn () => $this->membership);
        $membershipRepository = $this->createMock(RDBRepository::class);
        $membershipRepository->method('where')->willReturn($membershipLookup);
        $this->em->method('getRDBRepository')->willReturnMap([
            ['User', $users], ['ChatwootUser', $identityRepository],
            ['ChatwootAccountUserMembership', $membershipRepository],
        ]);
        $this->em->method('createEntity')->willReturnCallback(function ($type, $values, $options) {
            $this->assertSame('ChatwootUser', $type);
            $this->assertSame('user', $values['assignedUserId']);
            $this->assertSame('platform', $values['platformId']);
            $this->assertSame(['team'], $values['teamsIds']);
            $this->assertArrayNotHasKey('skipHooks', $options, 'Email and team savers must run.');
            return $this->identity = $this->entity($type, ['id' => 'identity'] + $values);
        });
    }

    private function service(): ChatwootAccountMembershipOrchestrator
    {
        return new ChatwootAccountMembershipOrchestrator(
            $this->em, $this->api, $this->memberships, $this->createMock(Log::class), $this->createMock(Acl::class),
        );
    }

    private function expectMembershipCreate(): void
    {
        $this->memberships->expects($this->once())->method('upsertMembership')
            ->with('account', 'identity', 'agent')->willReturnCallback(function () {
                return $this->membership = $this->entity('ChatwootAccountUserMembership', ['id' => 'membership', 'role' => 'agent']);
            });
        $this->api->expects($this->once())->method('attachUserToAccount')
            ->with('https://chat.test', 'token', 9, 93, 'agent')->willReturn([]);
    }

    public function testCreatesIdentityAndMembershipOnceAcrossRepeatedJobs(): void
    {
        $this->api->expects($this->once())->method('createUser')->willReturn(['id' => 93, 'created' => true]);
        $this->expectMembershipCreate();
        $this->tm->expects($this->exactly(2))->method('commit');
        $service = $this->service();
        $first = $service->ensureUserMembership($this->account, $this->user, null);
        $this->assertSame($first, $service->ensureUserMembership($this->account, $this->user, null));
        $this->assertSame('agent@example.com', $this->identity->get('emailAddress'));
        $this->assertNotEmpty($this->identity->get('password'));
    }

    public function testReusesExistingRemoteUserWithoutInventingStoredPassword(): void
    {
        $this->api->expects($this->once())->method('createUser')->willReturn(['id' => 93, 'existing' => true]);
        $this->api->expects($this->once())->method('fetchUserAccessToken')->willReturn('user-token');
        $this->api->expects($this->never())->method('updateUser');
        $this->expectMembershipCreate();
        $this->service()->ensureUserMembership($this->account, $this->user, null);
        $this->assertNull($this->identity->get('password'));
        $this->assertSame('user-token', $this->identity->get('userAccessToken'));
    }

    public function testExistingAdministratorRoleAndPasswordArePreservedOnEmailChange(): void
    {
        $this->identity = $this->entity('ChatwootUser', [
            'id' => 'identity', 'chatwootUserId' => 93, 'emailAddress' => 'old@example.com', 'password' => 'chosen',
        ]);
        $this->membership = $this->entity('ChatwootAccountUserMembership', ['id' => 'membership', 'role' => 'administrator']);
        $this->api->expects($this->never())->method('createUser');
        $this->api->expects($this->never())->method('attachUserToAccount');
        $this->api->expects($this->never())->method('detachUserFromAccount');
        $this->api->expects($this->once())->method('updateUser')
            ->with('https://chat.test', 'token', 93, ['email' => 'agent@example.com'])->willReturn([]);
        $this->service()->ensureUserMembership($this->account, $this->user, null);
        $this->assertSame('administrator', $this->membership->get('role'));
        $this->assertSame('chosen', $this->identity->get('password'));
    }

    public function testFailedMembershipWriteCompensatesOnlyNewlyCreatedRemoteUser(): void
    {
        $this->api->method('createUser')->willReturn(['id' => 93, 'created' => true]);
        $this->api->method('attachUserToAccount')->willReturn([]);
        $this->memberships->method('upsertMembership')->willThrowException(new \RuntimeException('write failed'));
        $this->tm->expects($this->once())->method('rollback');
        $this->api->expects($this->once())->method('detachUserFromAccount')->with('https://chat.test', 'token', 9, 93);
        $this->api->expects($this->once())->method('deleteUser')->with('https://chat.test', 'token', 93);
        $this->expectException(Error::class);
        $this->service()->ensureUserMembership($this->account, $this->user, null);
    }
}
