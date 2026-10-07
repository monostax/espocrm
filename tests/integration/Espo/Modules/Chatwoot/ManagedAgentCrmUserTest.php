<?php

declare(strict_types=1);

namespace tests\integration\Espo\Modules\Chatwoot;

use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Core\Authentication\Helper\UserFinder;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\AiAgentProvisioning;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Chatwoot\Services\ManagedAgentCrmUser;
use Espo\Modules\Chatwoot\Services\ManagedIdentityPolicy;
use Espo\Modules\Global\Services\TeamTenantAccess;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/** Run in an isolated installed CRM with MANAGED_AGENT_TEST_DATABASE naming its disposable database. */
class ManagedAgentCrmUserTest extends TestCase
{
    private Application $app;
    private EntityManager $em;
    private Entity $identity;
    private Entity $tenant;
    private Entity $team;
    private Entity $account;
    private Entity $chatwootUser;

    protected function setUp(): void
    {
        if (!getenv('MANAGED_AGENT_TEST_DATABASE')) self::markTestSkipped('Set MANAGED_AGENT_TEST_DATABASE in an isolated CRM runtime.');
        $this->app = new Application(new ApplicationParams(noErrorHandler: true));
        self::assertSame(getenv('MANAGED_AGENT_TEST_DATABASE'), $this->app->getContainer()->get('config')->get('database.dbname'));
        $this->app->setupSystemUser();
        $this->em = $this->app->getContainer()->get('entityManager');
        $role = $this->fixture('Role', ['name' => 'Agent member', 'data' => (object) [
            'Contact' => (object) ['read' => 'team', 'edit' => 'team', 'create' => 'yes', 'delete' => 'no'],
            'User' => (object) ['create' => 'yes', 'read' => 'team', 'edit' => 'team', 'delete' => 'team'],
            'Role' => false, 'Team' => false,
            'ChatwootAccount' => (object) ['read' => 'team', 'edit' => 'team', 'create' => 'no', 'delete' => 'no'],
            'ChatwootAccountUserMembership' => (object) ['read' => 'team', 'edit' => 'team', 'create' => 'yes', 'delete' => 'team'],
        ]]);
        $this->team = $this->fixture('Team', ['name' => 'Agent workspace', 'rolesIds' => [$role->getId()]]);
        $this->tenant = $this->fixture('Tenant', ['name' => 'Agent workspace', 'slug' => 'agent-test-' . bin2hex(random_bytes(4)), 'baseUserTeamId' => $this->team->getId()]);
        $platform = $this->fixture('ChatwootPlatform', ['name' => 'Test', 'backendUrl' => 'https://chatwoot.example.test', 'accessToken' => 'test-only']);
        $this->account = $this->fixture('ChatwootAccount', [
            'name' => 'Test account', 'tenantId' => $this->tenant->getId(), 'teamsIds' => [$this->team->getId()],
            'status' => 'active', 'platformId' => $platform->getId(), 'chatwootAccountId' => 13,
        ]);
        $machineId = bin2hex(random_bytes(16));
        $email = "agent.$machineId@test.monostax-ext.com";
        $this->chatwootUser = $this->fixture('ChatwootUser', [
            'name' => 'Sofia Sales', 'emailAddress' => $email, 'platformId' => $platform->getId(),
            'chatwootUserId' => 108, 'teamsIds' => [$this->team->getId()],
        ]);
        $membership = $this->fixture('ChatwootAccountUserMembership', [
            'name' => 'Sofia Sales', 'chatwootAccountId' => $this->account->getId(), 'chatwootUserId' => $this->chatwootUser->getId(),
            'email' => $email, 'role' => 'agent', 'isAI' => true, 'teamsIds' => [$this->team->getId()],
        ]);
        $this->identity = $this->fixture('ChatwootMachineIdentity', [
            'machineIdentityId' => $machineId, 'operationId' => $machineId, 'payloadHash' => hash('sha256', $machineId),
            'tenantId' => $this->tenant->getId(), 'accountId' => $this->account->getId(), 'membershipId' => $membership->getId(),
            'chatwootUserId' => $this->chatwootUser->getId(), 'platformId' => $platform->getId(),
            'remoteAccountId' => 13, 'remoteUserId' => 108, 'email' => $email, 'status' => 'active',
        ]);
    }

    private function fixture(string $type, array $data): Entity
    {
        $record = $this->em->createEntity($type, $data, [
            'silent' => true, 'skipHooks' => $type !== 'ChatwootUser', 'managedIdentityProvisioning' => true,
        ]);
        foreach (['teams', 'roles'] as $link) {
            foreach ($data[$link . 'Ids'] ?? [] as $id) {
                $this->em->getRelation($record, $link)->relateById($id, null, ['skipHooks' => true]);
            }
        }
        return $this->em->getEntityById($type, $record->getId());
    }

    private function service(): ManagedAgentCrmUser
    {
        return $this->app->getInjectableFactory()->create(ManagedAgentCrmUser::class);
    }

    public function testCreatesLinkedApiUserAndSingleCredentialWithTenantAcl(): void
    {
        $user = $this->service()->ensure($this->identity->getId());
        self::assertTrue($user->isApi());
        self::assertFalse($user->isAdmin());
        self::assertSame('Sofia Sales', $user->getName());
        self::assertSame([$this->team->getId()], $user->getTeamIdList());
        self::assertSame($this->team->getId(), $user->get('defaultTeamId'));
        self::assertSame($user->getId(), $this->em->getEntityById('ChatwootUser', $this->chatwootUser->getId())->get('assignedUserId'));
        self::assertSame($user->getId(), $this->service()->ensure($this->identity->getId())->getId());
        self::assertSame(1, $this->em->getRDBRepository('UserApiKey')->where(['userId' => $user->getId()])->count());
        self::assertNull($this->app->getInjectableFactory()->create(UserFinder::class)->find($user->getUserName()));
        $own = $this->fixture('Contact', ['lastName' => 'Own', 'teamsIds' => [$this->team->getId()]]);
        $otherTeam = $this->fixture('Team', ['name' => 'Foreign workspace']);
        $foreign = $this->fixture('Contact', ['lastName' => 'Foreign', 'teamsIds' => [$otherTeam->getId()]]);
        $acl = $this->app->getContainer()->get('aclManager');
        self::assertTrue($acl->check($user, $own, 'read'));
        self::assertFalse($acl->check($user, $foreign, 'read'));
        self::assertFalse($acl->check($user, 'Role', 'edit'));
        self::assertFalse($acl->check($user, 'User', 'create'));
        self::assertFalse($acl->check($user, 'User', 'edit'));
        self::assertFalse($acl->check($user, 'User', 'delete'));
    }

    public function testUnprovenExistingUserIsNeverAdopted(): void
    {
        $this->fixture('User', ['userName' => $this->identity->get('email'), 'type' => 'admin', 'isActive' => true]);
        $this->expectException(Conflict::class);
        $this->service()->ensure($this->identity->getId());
    }

    public function testTenantMismatchCannotProvision(): void
    {
        $this->identity->set('tenantId', 'foreign-tenant');
        $this->em->saveEntity($this->identity);
        $this->expectException(Conflict::class);
        $this->service()->ensure($this->identity->getId());
    }

    public function testPrincipalCannotBecomeAdminEvenWithSilentOrmSave(): void
    {
        $user = $this->service()->ensure($this->identity->getId());
        $user->set('type', 'admin');
        $this->expectException(Forbidden::class);
        $this->em->saveEntity($user, ['silent' => true]);
    }

    public function testForeignAndAdminTeamLinksAreRejectedInBothDirections(): void
    {
        $user = $this->service()->ensure($this->identity->getId());
        $other = $this->fixture('Team', ['name' => 'Foreign or admin team']);
        $policy = $this->app->getInjectableFactory()->create(ManagedIdentityPolicy::class);
        self::assertFalse($policy->permitsCrmRelation($user, 'teams', $other->getId()));
        self::assertFalse($policy->permitsCrmRelation($other, 'users', $user->getId()));
        try {
            $this->em->getRelation($other, 'users')->relate($user);
            self::fail('Inverse Team.users must reject managed principal escalation.');
        } catch (Forbidden) {
            self::assertFalse($this->em->getRelation($other, 'users')->isRelated($user));
        }
        self::assertSame([$this->team->getId()], $this->em->getEntityById('User', $user->getId())->getTeamIdList());
    }

    public function testRetirementRevokesCrmAccessAndPreventsReactivation(): void
    {
        $user = $this->service()->ensure($this->identity->getId());
        $identity = $this->em->getEntityById('ChatwootMachineIdentity', $this->identity->getId());
        $identity->set('status', 'retired');
        $this->em->saveEntity($identity);
        $this->service()->retire($identity);
        self::assertFalse($this->em->getEntityById('User', $user->getId())->isActive());
        self::assertSame(0, $this->em->getRDBRepository('UserApiKey')->where(['userId' => $user->getId(), 'isActive' => true])->count());
        $this->expectException(Conflict::class);
        $this->service()->ensure($identity->getId());
    }

    public function testManagedPrincipalCannotBeAttachedToAnotherChatwootUser(): void
    {
        $user = $this->service()->ensure($this->identity->getId());
        $other = $this->fixture('ChatwootUser', [
            'name' => 'Other agent', 'emailAddress' => 'other@example.test', 'platformId' => $this->identity->get('platformId'),
        ]);
        $other->set('assignedUserId', $user->getId());
        $this->expectException(Forbidden::class);
        $this->em->saveEntity($other, ['silent' => true]);
    }

    public function testBackfillWithRebuildHooksDisabledStillPersistsEmailAndTeams(): void
    {
        $hooks = $this->app->getContainer()->get('hookManager');
        $hooks->disable();
        try {
            $backfill = $this->app->getInjectableFactory()->create(\Espo\Modules\Chatwoot\Rebuild\BackfillManagedAgentCrmUsers::class);
            $backfill->process();
            $backfill->process();
            self::assertSame(1, $this->em->getRDBRepository('Job')->where([
                'className' => \Espo\Modules\Chatwoot\Jobs\BackfillManagedAgentCrmUsers::class, 'status' => 'Pending',
            ])->count());
            self::assertNull($this->em->getEntityById('ChatwootMachineIdentity', $this->identity->getId())->get('crmUserId'));
        } finally {
            $hooks->enable();
        }
        $this->app->getInjectableFactory()->create(\Espo\Modules\Chatwoot\Jobs\BackfillManagedAgentCrmUsers::class)->run();
        $identity = $this->em->getEntityById('ChatwootMachineIdentity', $this->identity->getId());
        $user = $this->em->getEntityById('User', $identity->get('crmUserId'));
        $user = $this->em->getEntityById('User', $user->getId());
        self::assertSame($this->identity->get('email'), $user->get('emailAddress'));
        self::assertSame([$this->team->getId()], $user->getTeamIdList());
        self::assertSame($user->getId(), $this->service()->ensure($this->identity->getId())->getId());
    }

    public function testCredentialFailureRollsBackTheUserAndBinding(): void
    {
        $pdo = $this->em->getPDO();
        $description = $pdo->quote('Managed AI agent membership ' . $this->identity->get('membershipId'));
        $pdo->exec("ALTER TABLE user_api_key ADD CONSTRAINT test_managed_key_failure CHECK (description <> $description)");
        try {
            try {
                $this->service()->ensure($this->identity->getId());
                self::fail('Credential creation should fail.');
            } catch (\PDOException) {
                self::assertNull($this->em->getEntityById('ChatwootMachineIdentity', $this->identity->getId())->get('crmUserId'));
                self::assertNull($this->em->getEntityById('ChatwootUser', $this->chatwootUser->getId())->get('assignedUserId'));
                self::assertSame(0, $this->em->getRDBRepository('User')->where(['userName' => $this->identity->get('email')])->count());
            }
        } finally {
            $pdo->exec('ALTER TABLE user_api_key DROP CONSTRAINT test_managed_key_failure');
        }
        self::assertTrue($this->service()->ensure($this->identity->getId())->isApi());
    }

    public function testForeignTenantCallerCannotCreateUserOrReachChatwoot(): void
    {
        // Same create-capable role, different tenant: scope ACL alone must not suffice.
        $roleIds = $this->team->getLinkMultipleIdList('roles');
        $foreignTeam = $this->fixture('Team', ['name' => 'Foreign tenant team', 'rolesIds' => $roleIds]);
        $foreignTenant = $this->fixture('Tenant', ['name' => 'Foreign', 'baseUserTeamId' => $foreignTeam->getId()]);
        $actor = $this->fixture('User', [
            'type' => 'regular', 'userName' => bin2hex(random_bytes(6)) . '@example.test', 'isActive' => true,
            'teamsIds' => [$foreignTeam->getId()],
        ]);
        $api = $this->createMock(ChatwootApiClient::class);
        $api->expects(self::never())->method('provisionMachineIdentity');
        $this->expectException(Forbidden::class);
        $this->provisioner($actor, $api)->create($this->account->getId(), $this->input());
    }

    public function testCreationIgnoresForgedUserPrivilegesAndRetryReusesTheCrmUser(): void
    {
        $actor = $this->fixture('User', [
            'type' => 'regular', 'userName' => bin2hex(random_bytes(6)) . '@example.test', 'isActive' => true,
            'teamsIds' => [$this->team->getId()],
        ]);
        $api = $this->createMock(ChatwootApiClient::class);
        $api->expects(self::once())->method('provisionMachineIdentity')->willReturnCallback(function ($url, $token, $accountId, $data) {
            self::assertSame($this->tenant->getId(), $data['tenant_id']);
            return $data + ['confirmed' => true, 'user_id' => 112, 'account_id' => $accountId];
        });
        $input = $this->input();
        $input->type = 'admin';
        $input->tenantId = 'foreign';
        $input->assignedUserId = $actor->getId();
        $input->rolesIds = ['tenant-admin'];
        $input->teamsIds = ['foreign'];
        $service = $this->provisioner($actor, $api);
        $membership = $service->create($this->account->getId(), $input);
        self::assertSame($membership->getId(), $service->create($this->account->getId(), $input)->getId());
        $cw = $this->em->getEntityById('ChatwootUser', $membership->get('chatwootUserId'));
        $user = $this->em->getEntityById('User', $cw->get('assignedUserId'));
        self::assertTrue($user->isApi());
        self::assertNotSame($actor->getId(), $user->getId());
        self::assertSame([$this->team->getId()], $user->getTeamIdList());
        self::assertSame([], $user->getLinkMultipleIdList('roles'));
        self::assertSame(1, $this->em->getRDBRepository('UserApiKey')->where(['userId' => $user->getId()])->count());
    }

    public function testManagedUserCannotProvisionAnotherIdentity(): void
    {
        $actor = $this->service()->ensure($this->identity->getId());
        $api = $this->createMock(ChatwootApiClient::class);
        $api->expects(self::never())->method('provisionMachineIdentity');
        $this->expectException(Forbidden::class);
        $this->provisioner($actor, $api)->create($this->account->getId(), $this->input());
    }

    private function input(): object
    {
        $id = bin2hex(random_bytes(16));
        return (object) ['name' => 'New agent', 'operationId' => substr($id, 0, 8) . '-' . substr($id, 8, 4) . '-4' . substr($id, 13, 3) . '-8' . substr($id, 17, 3) . '-' . substr($id, 20)];
    }

    private function provisioner(User $actor, ChatwootApiClient $api): AiAgentProvisioning
    {
        $app = new Application(new ApplicationParams(noErrorHandler: true, services: ['user' => $actor]));
        $factory = $app->getInjectableFactory();
        return new AiAgentProvisioning(
            $app->getContainer()->get('entityManager'), $app->getContainer()->get('acl'), $api, $actor,
            $factory->create(UserTenantResolver::class), $factory->create(TeamTenantAccess::class),
            $factory->create(ManagedAgentCrmUser::class),
        );
    }
}
