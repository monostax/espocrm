<?php

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ChatwootAgentIdentity;
use Espo\Modules\Chatwoot\Services\ChatwootAccountUserMembershipService;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\ORM\TransactionManager;
use PHPUnit\Framework\TestCase;

class ChatwootAgentIdentityTest extends TestCase
{
    private EntityManager $em;
    private ChatwootApiClient $api;
    private ChatwootAccountUserMembershipService $memberships;
    private CoreEntity $account;
    private CoreEntity $platform;
    private CoreEntity $tenant;
    private ?User $user = null;
    private ?Entity $identity = null;
    private bool $locked = false;
    private array $created = [];

    private function entity(string $type, array $values): CoreEntity
    {
        $attributes = [];
        foreach ($values as $key => $value) {
            $attributes[$key] = ['type' => is_array($value) ? 'jsonArray' : 'varchar'];
        }
        $entity = $type === 'User' ? new User($type, ['attributes' => $attributes], $this->em,
            $this->createMock(\Espo\Core\ORM\Helper::class)) :
            new CoreEntity($type, ['attributes' => $attributes]);
        $entity->set($values);
        return $entity;
    }

    protected function setUp(): void
    {
        $this->account = $this->entity('ChatwootAccount', [
            'id' => 'account', 'platformId' => 'platform', 'chatwootAccountId' => 9,
            'teamsIds' => ['team', 'extra-team'], 'tenantId' => 'tenant', 'status' => 'active', 'apiKey' => 'account-key',
        ]);
        $this->platform = $this->entity('ChatwootPlatform', [
            'id' => 'platform', 'frontendUrl' => 'https://chat.test', 'backendUrl' => 'http://chatwoot',
        ]);
        $this->tenant = $this->entity('Tenant', ['id' => 'tenant', 'baseUserTeamId' => 'team']);
        $this->em = $this->createMock(EntityManager::class);
        $this->api = $this->createMock(ChatwootApiClient::class);
        $this->api->expects($this->never())->method('createUser');
        $this->api->expects($this->never())->method('updateUser');
        $this->memberships = $this->createMock(ChatwootAccountUserMembershipService::class);

        $tm = $this->createMock(TransactionManager::class);
        $tm->method('run')->willReturnCallback(function ($callback) {
            $this->locked = false;
            return $callback();
        });
        $this->em->method('getTransactionManager')->willReturn($tm);
        $this->em->method('getEntityById')->willReturnMap([['Tenant', 'tenant', $this->tenant]]);

        $platformSelect = $this->createMock(RDBSelectBuilder::class);
        $platformSelect->method('select')->with(['id'])->willReturnSelf();
        $platformSelect->method('forUpdate')->willReturnCallback(function () use ($platformSelect) {
            $this->locked = true;
            return $platformSelect;
        });
        $platformSelect->method('findOne')->willReturn($this->platform);
        $platformRepo = $this->createMock(RDBRepository::class);
        $platformRepo->method('where')->with(['id' => 'platform'])->willReturn($platformSelect);
        $platformRepo->method('find')->willReturn(new EntityCollection([$this->platform]));

        $users = $this->createMock(RDBRepository::class);
        $users->method('where')->willReturnCallback(function ($where) {
            $select = $this->createMock(RDBSelectBuilder::class);
            $select->method('findOne')->willReturn($this->user);
            $select->method('find')->willReturn(new EntityCollection(
                $this->user && $this->user->getType() === 'regular' ? [$this->user] : [],
            ));
            return $select;
        });
        $this->em->method('getRDBRepositoryByClass')->with(User::class)->willReturn($users);

        $identities = $this->createMock(RDBRepository::class);
        $identities->method('where')->willReturnCallback(function ($where) {
            $this->assertTrue($this->locked, 'Identity lookup must run under the platform lock.');
            $select = $this->createMock(RDBSelectBuilder::class);
            $select->method('findOne')->willReturn($this->identity);
            return $select;
        });
        $accounts = $this->createMock(RDBRepository::class);
        $accountSelect = $this->createMock(RDBSelectBuilder::class);
        $accountSelect->method('find')->willReturn(new EntityCollection([$this->account]));
        $accounts->method('where')->with(['platformId' => 'platform', 'status' => 'active'])->willReturn($accountSelect);
        $this->em->method('getRDBRepository')->willReturnMap([
            ['User', $users], ['ChatwootUser', $identities], ['ChatwootPlatform', $platformRepo], ['ChatwootAccount', $accounts],
        ]);
        $this->em->method('createEntity')->willReturnCallback(function ($type, $values, $options) {
            $this->assertTrue($this->locked);
            $this->assertArrayNotHasKey('skipHooks', $options, 'Email and team/tenant field savers must run.');
            $this->created[] = $type;
            if ($type === 'User') {
                $this->assertTrue($options['skipChatwootProvisioning'], 'Do not provision the existing Chatwoot agent again.');
                return $this->user = $this->entity('User', ['id' => 'user'] + $values);
            }
            $this->assertSame('ChatwootUser', $type);
            $this->assertArrayNotHasKey('password', $values, 'Never push a generated password to Chatwoot.');
            return $this->identity = $this->entity($type, ['id' => 'identity'] + $values);
        });
    }

    private function service(): ChatwootAgentIdentity
    {
        return new ChatwootAgentIdentity($this->em, $this->api, $this->memberships, $this->createMock(Log::class));
    }

    private function agent(): array
    {
        return ['id' => 98, 'email' => 'agent@example.com', 'name' => 'Agent Full Name', 'role' => 'administrator'];
    }

    public function testProvisionsNewAgentOnceWithRegularTenantAccess(): void
    {
        $service = $this->service();
        $identity = $service->importForAccount($this->account, $this->agent());
        $this->assertSame($identity, $service->importForAccount($this->account, $this->agent()));
        $this->assertSame(['User', 'ChatwootUser'], $this->created);
        $this->assertSame('regular', $this->user->getType(), 'Chatwoot administrator must not become a CRM administrator.');
        $this->assertSame(['team'], $this->user->getLinkMultipleIdList('teams'));
        $this->assertSame('team', $this->user->get('defaultTeamId'));
        $this->assertSame('agent@example.com', $this->user->getUserName());
        $this->assertSame('Agent', $this->user->get('firstName'));
        $this->assertSame('Full Name', $this->user->get('lastName'));
        $this->assertSame('bcrypt', password_get_info($this->user->get('password'))['algoName']);
        $this->assertSame('user', $identity->get('assignedUserId'));
    }

    public function testResetBeforeScheduledImportVerifiesRemoteMembershipAndCreatesIdentity(): void
    {
        $this->api->expects($this->once())->method('listAgents')
            ->with('http://chatwoot', 'account-key', 9)->willReturn([$this->agent()]);
        $this->memberships->expects($this->once())->method('upsertMembership')->with('account', 'identity', 'administrator')
            ->willReturn($this->entity('ChatwootAccountUserMembership', ['id' => 'membership']));
        $this->service()->importForPasswordSync(98, 'agent@example.com', 'https://chat.test/');
        $this->assertSame(['User', 'ChatwootUser'], $this->created);
    }

    public function testResetDoesNotProvisionAnEmailOrIdMissingFromTheAuthenticatedAccount(): void
    {
        $this->api->method('listAgents')->willReturn([$this->agent()]);
        $service = $this->service();
        $service->importForPasswordSync(99, 'agent@example.com', 'https://chat.test');
        $service->importForPasswordSync(98, 'different@example.com', 'https://chat.test');
        $service->importForPasswordSync(98, 'agent@example.com', 'https://other-installation.test');
        $this->assertSame([], $this->created);
    }

    public function testDoesNotProvisionWithoutTheTenantBaseTeam(): void
    {
        $this->account->set('teamsIds', ['other-tenant-team']);
        $this->assertNull($this->service()->importForAccount($this->account, $this->agent()));
        $this->assertSame([], $this->created);
    }

    public function testExistingUserIsLinkedWithoutChangingTheirPassword(): void
    {
        $this->existingUser();
        $this->service()->importForAccount($this->account, $this->agent());
        $this->assertSame(['ChatwootUser'], $this->created);
        $this->assertSame('chosen-password-hash', $this->user->get('password'));
    }

    public function testDoesNotReactivateEscalateOrMoveExistingUsersBetweenTenants(): void
    {
        foreach ([['isActive' => false], ['type' => 'admin'], ['teamsIds' => ['other-team']]] as $changes) {
            $this->existingUser($changes);
            $this->assertNull($this->service()->importForAccount($this->account, $this->agent()));
            $this->assertSame([], $this->created);
            $this->assertSame('chosen-password-hash', $this->user->get('password'));
        }
    }

    private function existingUser(array $changes = []): void
    {
        $this->user = $this->entity('User', $changes + [
            'id' => 'user', 'userName' => 'agent@example.com', 'emailAddress' => 'agent@example.com',
            'type' => 'regular', 'isActive' => true, 'teamsIds' => ['team'], 'password' => 'chosen-password-hash',
        ]);
    }
}
