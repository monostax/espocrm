<?php

declare(strict_types=1);

namespace tests\integration\Espo\Modules\FeatureSelfService;

use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Core\Exceptions\Conflict;
use Espo\Entities\User;
use Espo\Modules\FeatureSelfService\Jobs\RunProvisionings;
use Espo\Modules\FeatureSelfService\Tools\Policy\SelfServicePolicy;
use Espo\Modules\FeatureSelfService\Tools\Policy\SelfServiceUnavailable;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\Provisioner;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\ProvisioningRequest;
use Espo\Modules\Global\Tools\Tenant\TenantAdminTeamProvisioner;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * Self-service provisioning against a real CRM database and a fake Chatwoot
 * (fake-chatwoot.php). Run inside an isolated CRM install whose database is
 * named by SELF_SERVICE_TEST_DATABASE, with FAKE_CHATWOOT_URL pointing at the
 * fake. See FeatureSelfService/index.md.
 */
class ProvisioningTest extends TestCase
{
    private Application $app;
    private EntityManager $em;
    private string $fake;

    protected function setUp(): void
    {
        $database = getenv('SELF_SERVICE_TEST_DATABASE');
        $this->fake = (string) getenv('FAKE_CHATWOOT_URL');

        if (!$database || $this->fake === '') {
            self::markTestSkipped('Set SELF_SERVICE_TEST_DATABASE and FAKE_CHATWOOT_URL in an isolated CRM runtime.');
        }

        $this->app = new Application(new ApplicationParams(noErrorHandler: true));
        self::assertSame($database, $this->app->getContainer()->get('config')->get('database.dbname'));
        $this->app->setupSystemUser();
        $this->em = $this->app->getContainer()->get('entityManager');

        $this->http('POST', '/__reset');
        putenv(SelfServicePolicy::ENV . '=true');
        $this->policy()->resume();

        if (!$this->em->getRDBRepository('ChatwootPlatform')->findOne()) {
            $this->em->createEntity('ChatwootPlatform', [
                'name' => 'Fake Chatwoot',
                'backendUrl' => $this->fake,
                'frontendUrl' => $this->fake,
                'accessToken' => 'platform-token',
            ], ['skipHooks' => true]);
        }
    }

    public function testProvisionsAWorkspaceOnceAndOnlyOnce(): void
    {
        $owner = $this->owner();
        $request = $this->request('signup:happy-' . $this->suffix(), $owner, 'Clínica Teste');

        $record = $this->provisioner()->start($request);
        self::assertSame($record->getId(), $this->provisioner()->start($request)->getId());

        $done = $this->provisioner()->run($record->getId());
        self::assertSame('completed', $done->get('status'), (string) $done->get('lastError'));
        self::assertSame('done', $done->get('step'));

        $tenant = $this->em->getEntityById('Tenant', $done->get('tenantId'));
        self::assertStringStartsWith('clinica-teste', $tenant->get('slug'));
        self::assertSame('Clínica Teste', $tenant->get('name'));

        $baseTeamId = $tenant->get('baseUserTeamId');
        $adminTeamId = $this->adminTeams()->findAdminTeamId($tenant);
        self::assertSame('tenant-base:' . $tenant->getId(), $this->team($baseTeamId)->get('provisioningKey'));
        self::assertSame('tenant-admin:' . $tenant->getId(), $this->team($adminTeamId)->get('provisioningKey'));

        $owner = $this->em->getEntityById('User', $owner->getId());
        self::assertEqualsCanonicalizing([$baseTeamId, $adminTeamId], $owner->getLinkMultipleIdList('teams'));
        self::assertSame($baseTeamId, $owner->get('defaultTeamId'));
        self::assertFalse($owner->isAdmin());
        self::assertTrue($this->em->getRDBRepository('Tenant')->getRelation($tenant, 'users')->isRelatedById($owner->getId()));

        $rate = $this->em->getRDBRepository('TenantAiBillingRate')->where(['tenantId' => $tenant->getId()])->findOne();
        self::assertSame('block', $rate->get('overagePolicy'), 'New workspaces must not get unmetered AI.');
        self::assertSame('credit', $rate->get('billingModel'));
        self::assertSame(250, $rate->get('planIncludedCredits'));

        $account = $this->em->getEntityById('ChatwootAccount', $done->get('chatwootAccountId'));
        self::assertGreaterThan(0, (int) $account->get('chatwootAccountId'));
        self::assertNotEmpty($account->get('apiKey'));
        self::assertSame($tenant->getId(), $account->get('tenantId'));
        self::assertSame([$baseTeamId], $account->getLinkMultipleIdList('teams'));
        self::assertSame('administrator', $this->ownerRole($account, $owner));
        // An operator-provisioned owner never proved the email: Chatwoot still confirms it by email.
        self::assertSame(0, $this->callCount('POST /platform/api/v1/users/:id/confirm'));
        self::assertSame(1, $this->callCount('POST /platform/api/v1/accounts'));

        // Running a completed provisioning again changes nothing.
        self::assertSame('completed', $this->provisioner()->run($record->getId())->get('status'));
        self::assertSame(1, $this->callCount('POST /platform/api/v1/accounts'));
        self::assertSame(1, $this->em->getRDBRepository('TenantAiBillingRate')->where(['tenantId' => $tenant->getId()])->count());
    }

    public function testSameNamedWorkspacesGetSeparateTenantsAndTeams(): void
    {
        $first = $this->provisionCompleted('signup:same-a-' . $this->suffix(), $this->owner(), 'Acme Teste');
        $second = $this->provisionCompleted('signup:same-b-' . $this->suffix(), $this->owner(), 'Acme Teste');

        $a = $this->em->getEntityById('Tenant', $first->get('tenantId'));
        $b = $this->em->getEntityById('Tenant', $second->get('tenantId'));

        self::assertNotSame($a->get('slug'), $b->get('slug'));
        self::assertNotSame($a->get('baseUserTeamId'), $b->get('baseUserTeamId'));
        self::assertNotSame($this->adminTeams()->findAdminTeamId($a), $this->adminTeams()->findAdminTeamId($b));
    }

    public function testReusingASignupKeyWithOtherSettingsConflicts(): void
    {
        $owner = $this->owner();
        $key = 'signup:conflict-' . $this->suffix();
        $this->provisioner()->start($this->request($key, $owner, 'Original'));

        $this->expectException(Conflict::class);
        $this->provisioner()->start($this->request($key, $owner, 'Different'));
    }

    public function testResumesTheChatwootAccountAfterTheConciergeStepFails(): void
    {
        $this->http('POST', '/__fault', ['call' => 'POST /platform/api/v1/users', 'times' => 1, 'status' => 500]);
        $record = $this->provisioner()->start($this->request('signup:resume-' . $this->suffix(), $this->owner(), 'Resume Teste'));

        $failed = $this->provisioner()->run($record->getId());
        self::assertSame('failed', $failed->get('status'));
        self::assertSame('chatwootAccount', $failed->get('step'));
        self::assertSame(1, $failed->get('attempts'));
        self::assertNotNull($failed->get('nextAttemptAt'));
        self::assertNull($this->em->getEntityById('ChatwootAccount', $failed->get('chatwootAccountId')));

        $journal = $this->em->getEntityById('ChatwootAccountCreation', $failed->get('chatwootAccountId'));
        self::assertGreaterThan(0, (int) $journal->get('remoteAccountId'));

        $done = $this->provisioner()->run($record->getId());
        self::assertSame('completed', $done->get('status'), (string) $done->get('lastError'));
        $account = $this->em->getEntityById('ChatwootAccount', $done->get('chatwootAccountId'));
        self::assertSame((int) $journal->get('remoteAccountId'), (int) $account->get('chatwootAccountId'));
        self::assertSame(1, $this->callCount('POST /platform/api/v1/accounts'));
    }

    public function testAdoptsAConciergeWhoseCreationReplyWasLost(): void
    {
        $this->http('POST', '/__fault', [
            'call' => 'POST /platform/api/v1/users', 'times' => 1, 'status' => 502, 'afterCreate' => true,
        ]);
        $record = $this->provisioner()->start($this->request('signup:lost-' . $this->suffix(), $this->owner(), 'Lost Teste'));

        self::assertSame('failed', $this->provisioner()->run($record->getId())->get('status'));

        $done = $this->provisioner()->run($record->getId());
        self::assertSame('completed', $done->get('status'), (string) $done->get('lastError'));
        $journal = $this->em->getEntityById('ChatwootAccountCreation', $done->get('chatwootAccountId'));
        self::assertGreaterThan(0, (int) $journal->get('conciergeUserId'));
        self::assertSame(1, $this->callCount('POST /platform/api/v1/accounts'));
    }

    public function testPauseStopsNewWorkAndResumeContinuesWhereItStopped(): void
    {
        $record = $this->provisioner()->start($this->request('signup:pause-' . $this->suffix(), $this->owner(), 'Pause Teste'));
        $this->policy()->pause('integration test');

        $paused = $this->provisioner()->run($record->getId());
        self::assertSame('paused', $paused->get('status'));
        self::assertSame('tenant', $paused->get('step'));
        self::assertNull($this->em->getEntityById('Tenant', $paused->get('tenantId')));

        try {
            $this->provisioner()->start($this->request('signup:pause-new-' . $this->suffix(), $this->owner(), 'Blocked'));
            self::fail('start() must refuse while paused.');
        } catch (SelfServiceUnavailable $e) {
            self::assertSame('paused', $e->state);
        }

        $this->policy()->resume();
        self::assertSame('completed', $this->provisioner()->run($record->getId())->get('status'));
    }

    public function testTheDeploymentSwitchOffRefusesEvenWhenNotPaused(): void
    {
        putenv(SelfServicePolicy::ENV);

        $this->expectException(SelfServiceUnavailable::class);
        $this->provisioner()->start($this->request('signup:off-' . $this->suffix(), $this->owner(), 'Off'));
    }

    public function testAnInstanceAdministratorCannotOwnAWorkspace(): void
    {
        $record = $this->provisioner()->start(
            $this->request('signup:admin-' . $this->suffix(), $this->owner(User::TYPE_ADMIN), 'Admin Owner')
        );

        $failed = $this->provisioner()->run($record->getId());
        self::assertSame('failed', $failed->get('status'));
        self::assertSame('owner', $failed->get('step'));
        self::assertNull($failed->get('nextAttemptAt'), 'Retrying cannot fix the owner.');
        self::assertStringContainsString('regular user', (string) $failed->get('lastError'));
    }

    public function testAnotherWorkersLiveLeaseIsLeftAlone(): void
    {
        $record = $this->provisioner()->start($this->request('signup:lease-' . $this->suffix(), $this->owner(), 'Lease Teste'));
        $this->em->getQueryExecutor()->execute(
            $this->em->getQueryBuilder()->update()->in(Provisioner::ENTITY_TYPE)
                ->set(['leaseToken' => 'other-worker', 'leaseUntil' => gmdate('Y-m-d H:i:s', time() + 300)])
                ->where(['id' => $record->getId()])
                ->build()
        );

        $untouched = $this->provisioner()->run($record->getId());
        self::assertSame('tenant', $untouched->get('step'));
        self::assertSame('other-worker', $untouched->get('leaseToken'));
        self::assertNull($this->em->getEntityById('Tenant', $untouched->get('tenantId')));
    }

    public function testADeletedTenantTeamIsReplacedUnderTheSameKey(): void
    {
        $done = $this->provisionCompleted('signup:deleted-' . $this->suffix(), $this->owner(), 'Deleted Team');
        $tenant = $this->em->getEntityById('Tenant', $done->get('tenantId'));
        $oldAdminTeamId = $this->adminTeams()->findAdminTeamId($tenant);
        $this->em->removeEntity($this->team($oldAdminTeamId));

        $newAdminTeamId = $this->adminTeams()->ensureForTenant($tenant);

        self::assertNotNull($newAdminTeamId);
        self::assertNotSame($oldAdminTeamId, $newAdminTeamId);
        self::assertSame('tenant-admin:' . $tenant->getId(), $this->team($newAdminTeamId)->get('provisioningKey'));
    }

    public function testTheScheduledJobRunsDueProvisionings(): void
    {
        $record = $this->provisioner()->start($this->request('signup:job-' . $this->suffix(), $this->owner(), 'Job Teste'));

        $this->app->getInjectableFactory()->create(RunProvisionings::class)->run();

        self::assertSame('completed', $this->em->getEntityById(Provisioner::ENTITY_TYPE, $record->getId())->get('status'));
    }

    private function provisionCompleted(string $key, User $owner, string $name): Entity
    {
        $record = $this->provisioner()->start($this->request($key, $owner, $name));
        $done = $this->provisioner()->run($record->getId());
        self::assertSame('completed', $done->get('status'), (string) $done->get('lastError'));

        return $done;
    }

    private function request(string $key, User $owner, string $name): ProvisioningRequest
    {
        return new ProvisioningRequest($key, (string) $owner->getId(), $name);
    }

    private function owner(string $type = User::TYPE_REGULAR): User
    {
        $suffix = $this->suffix();

        /** @var User */
        return $this->em->createEntity('User', [
            'userName' => 'owner-' . $suffix,
            'firstName' => 'Owner',
            'lastName' => $suffix,
            'emailAddress' => "owner-{$suffix}@example.test",
            'type' => $type,
            'isActive' => true,
        ]);
    }

    private function ownerRole(Entity $account, User $owner): ?string
    {
        $chatwootUser = $this->em->getRDBRepository('ChatwootUser')
            ->where(['assignedUserId' => $owner->getId()])
            ->findOne();

        if (!$chatwootUser) {
            return null;
        }

        return $this->em->getRDBRepository('ChatwootAccountUserMembership')
            ->where(['chatwootAccountId' => $account->getId(), 'chatwootUserId' => $chatwootUser->getId()])
            ->findOne()
            ?->get('role');
    }

    private function team(?string $id): Entity
    {
        self::assertNotNull($id);

        return $this->em->getEntityById('Team', $id);
    }

    private function provisioner(): Provisioner
    {
        return $this->app->getInjectableFactory()->create(Provisioner::class);
    }

    private function policy(): SelfServicePolicy
    {
        return $this->app->getInjectableFactory()->create(SelfServicePolicy::class);
    }

    private function adminTeams(): TenantAdminTeamProvisioner
    {
        return $this->app->getInjectableFactory()->create(TenantAdminTeamProvisioner::class);
    }

    private function callCount(string $call): int
    {
        return count(array_filter($this->http('GET', '/__calls'), fn (string $c) => $c === $call));
    }

    private function suffix(): string
    {
        return bin2hex(random_bytes(4));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<mixed>
     */
    private function http(string $method, string $path, array $body = []): array
    {
        $curl = curl_init(rtrim($this->fake, '/') . $path);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $method === 'GET' ? null : json_encode($body),
        ]);
        $response = (string) curl_exec($curl);
        curl_close($curl);

        return json_decode($response, true) ?: [];
    }
}
