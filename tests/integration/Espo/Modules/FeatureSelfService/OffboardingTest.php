<?php

declare(strict_types=1);

namespace tests\integration\Espo\Modules\FeatureSelfService;

use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\Deleted\RestorerFactory;
use Espo\Entities\User;
use Espo\Modules\FeatureSelfService\Jobs\RunOffboardings;
use Espo\Modules\FeatureSelfService\Tools\Offboarding\Offboarder;
use Espo\Modules\FeatureSelfService\Tools\Policy\SelfServicePolicy;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\Provisioner;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\ProvisioningRequest;
use Espo\Modules\Global\Tools\Tenant\TenantAdminTeamProvisioner;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * Tenant offboarding against a real CRM database and the fake Chatwoot/WAHA
 * (fake-chatwoot.php). Same runtime as ProvisioningTest; see
 * FeatureSelfService/index.md.
 */
class OffboardingTest extends TestCase
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
        $this->app->getInjectableFactory()->create(SelfServicePolicy::class)->resume();

        if (!$this->em->getRDBRepository('ChatwootPlatform')->findOne()) {
            $this->em->createEntity('ChatwootPlatform', [
                'name' => 'Fake Chatwoot',
                'backendUrl' => $this->fake,
                'frontendUrl' => $this->fake,
                'accessToken' => 'platform-token',
            ], ['skipHooks' => true]);
        }
    }

    public function testATenantCanOnlyBeRemovedByOffboarding(): void
    {
        $tenant = $this->tenant($this->provision('Direct Delete'));

        try {
            $this->em->removeEntity($tenant);
            self::fail('Removing a Tenant directly must be refused.');
        } catch (Forbidden) {
        }

        self::assertNotNull($this->em->getEntityById('Tenant', $tenant->getId()));
    }

    public function testStartingNeedsTheSlugAndNoOpenSubscription(): void
    {
        $tenant = $this->tenant($this->provision('Guarded'));

        try {
            $this->offboarder()->start($tenant->getId(), 'not-the-slug', 'system');
            self::fail('A wrong slug must be refused.');
        } catch (BadRequest $e) {
            self::assertSame('SLUG_MISMATCH', $e->getMessage());
        }

        $this->em->createEntity('CreditBillingObligation', [
            'tenantId' => $tenant->getId(),
            'state' => 'active',
            ...array_fill_keys(['requestKey', 'inputHash', 'acceptanceHash', 'provider', 'integrationId', 'catalogId', 'termsId', 'operation', 'createdById'], 'test-' . $this->suffix()),
            'termsSnapshot' => (object) ['test' => true],
            'billingCountry' => 'BR',
            'assignmentRevision' => 1,
            'createdAt' => gmdate('Y-m-d H:i:s'),
        ], ['skipHooks' => true]);

        try {
            $this->offboarder()->start($tenant->getId(), $tenant->get('slug'), 'system');
            self::fail('An open subscription must be cancelled first.');
        } catch (Conflict $e) {
            self::assertSame('OPEN_SUBSCRIPTION', $e->getMessage());
        }

        self::assertNull($this->offboarder()->findByTenant($tenant->getId()));
    }

    public function testOffboardsAWorkspaceAndLeavesEveryoneElseAlone(): void
    {
        $provisioning = $this->provision('Offboard Me');
        $other = $this->provision('Neighbour');
        $tenant = $this->tenant($provisioning);
        $neighbour = $this->tenant($other);
        $owner = $this->em->getEntityById('User', $provisioning->get('ownerId'));
        $baseTeamId = (string) $tenant->get('baseUserTeamId');
        $adminTeamId = (string) $this->adminTeams()->findAdminTeamId($tenant);
        $session = $this->whatsApp($provisioning->get('chatwootAccountId'));

        // In both workspaces: only loses this one.
        $shared = $this->user();
        $this->em->getRDBRepository('Tenant')->getRelation($tenant, 'users')->relateById($shared->getId());
        $this->em->getRDBRepository('Tenant')->getRelation($neighbour, 'users')->relateById($shared->getId());
        // An instance admin in the workspace's team: only loses the team.
        $admin = $this->user(User::TYPE_ADMIN);
        $this->em->getRDBRepository('User')->getRelation($admin, 'teams')->relateById($baseTeamId);

        $contact = $this->em->createEntity('Contact', [
            'lastName' => 'Cliente', 'tenantId' => $tenant->getId(), 'teamsIds' => [$baseTeamId],
        ]);
        $teamOnlyTask = $this->em->createEntity('Task', ['name' => 'Only here', 'teamsIds' => [$baseTeamId]]);
        $sharedTask = $this->em->createEntity('Task', [
            'name' => 'Shared', 'teamsIds' => [$baseTeamId, (string) $neighbour->get('baseUserTeamId')],
        ]);
        $ownerChatwootUser = $this->em->getRDBRepository('ChatwootUser')->where(['assignedUserId' => $owner->getId()])->findOne();
        self::assertNotNull($ownerChatwootUser);
        $conciergeId = $this->em->getEntityById('ChatwootAccount', $provisioning->get('chatwootAccountId'))->get('conciergeUserId');
        self::assertNotEmpty($conciergeId);

        $record = $this->offboarder()->start($tenant->getId(), $tenant->get('slug'), 'system');
        self::assertSame($record->getId(), $this->offboarder()->start($tenant->getId(), $tenant->get('slug'), 'system')->getId());

        $done = $this->offboarder()->run($record->getId());
        self::assertSame('completed', $done->get('status'), (string) $done->get('lastError'));
        self::assertSame('done', $done->get('step'));

        // Outside the CRM: the WhatsApp number is logged out, Chatwoot is gone.
        self::assertSame(1, $this->callCount("POST /api/sessions/{$session}/stop"));
        self::assertSame(1, $this->callCount("DELETE /api/sessions/{$session}"));
        self::assertSame(1, $this->callCount('DELETE /platform/api/v1/accounts/:id'));
        // The owner's Chatwoot user and the account's concierge.
        self::assertSame(2, $this->callCount('DELETE /platform/api/v1/users/:id'));

        // Inside: the workspace, its data, teams and own users are gone.
        self::assertNull($this->em->getEntityById('Tenant', $tenant->getId()));
        self::assertNull($this->em->getEntityById('ChatwootAccount', $provisioning->get('chatwootAccountId')));
        self::assertSame(0, $this->em->getRDBRepository('ChatwootInboxIntegration')->where(['chatwootAccountId' => $provisioning->get('chatwootAccountId')])->count());
        self::assertNull($this->em->getEntityById('Contact', $contact->getId()));
        self::assertNull($this->em->getEntityById('Task', $teamOnlyTask->getId()));
        self::assertNull($this->em->getEntityById('Team', $baseTeamId));
        self::assertNull($this->em->getEntityById('Team', $adminTeamId));
        self::assertNull($this->em->getEntityById('User', $owner->getId()));
        self::assertNull($this->em->getEntityById('ChatwootUser', $ownerChatwootUser->getId()));
        self::assertNull($this->em->getEntityById('ChatwootUser', $conciergeId));
        self::assertNull(
            $this->em->getRDBRepository('User')->where(['emailAddress' => $owner->get('emailAddress')])->findOne(),
            'The owner can sign up again with the same email.',
        );

        // Kept: billing history, other workspaces and their users.
        self::assertSame(1, $this->em->getRDBRepository('TenantAiBillingRate')->where(['tenantId' => $tenant->getId()])->count());
        self::assertNotNull($this->em->getEntityById('Task', $sharedTask->getId()));
        self::assertNotNull($this->em->getEntityById('Tenant', $neighbour->getId()));
        self::assertNotNull($this->em->getEntityById('ChatwootAccount', $other->get('chatwootAccountId')));

        $shared = $this->em->getEntityById('User', $shared->getId());
        self::assertTrue($shared->isActive());
        self::assertNotContains($baseTeamId, $shared->getLinkMultipleIdList('teams'));
        self::assertContains((string) $neighbour->get('baseUserTeamId'), $shared->getLinkMultipleIdList('teams'));
        $admin = $this->em->getEntityById('User', $admin->getId());
        self::assertTrue($admin->isActive());
        self::assertNotContains($baseTeamId, $admin->getLinkMultipleIdList('teams'));

        $state = json_decode((string) json_encode($done->get('state')), true);
        self::assertSame(1, $state['removed']['Contact'] ?? null);
        self::assertSame(1, $state['removed']['User'] ?? null);

        // Again: nothing more happens.
        self::assertSame('completed', $this->offboarder()->run($record->getId())->get('status'));
        self::assertSame(1, $this->callCount('DELETE /platform/api/v1/accounts/:id'));

        // An offboarded workspace can't be restored.
        $removed = $this->em->getRDBRepository('Tenant')->clone(
            $this->em->getQueryBuilder()->select()->from('Tenant')->where(['id' => $tenant->getId()])->withDeleted()->build()
        )->findOne();
        $this->expectException(Conflict::class);
        $this->app->getInjectableFactory()->create(RestorerFactory::class)->create('Tenant')->restore($removed);
    }

    public function testAFailedStepRetriesWithoutRepeatingTheDoneOnes(): void
    {
        $provisioning = $this->provision('Retry Me');
        $tenant = $this->tenant($provisioning);
        $session = $this->whatsApp($provisioning->get('chatwootAccountId'));
        $remoteId = (int) $this->em->getEntityById('ChatwootAccount', $provisioning->get('chatwootAccountId'))->get('chatwootAccountId');
        $this->http('POST', '/__fault', ['call' => 'DELETE /platform/api/v1/accounts/:id', 'times' => 1, 'status' => 500]);

        $record = $this->offboarder()->start($tenant->getId(), $tenant->get('slug'), 'system');
        $failed = $this->offboarder()->run($record->getId());

        self::assertSame('failed', $failed->get('status'));
        self::assertSame('chatwootRemote', $failed->get('step'));
        self::assertSame(1, $failed->get('attempts'));
        self::assertNotNull($failed->get('nextAttemptAt'));
        // Access is already revoked.
        self::assertFalse($this->em->getEntityById('User', $provisioning->get('ownerId'))->isActive());
        self::assertNotNull($this->em->getEntityById('Tenant', $tenant->getId()));

        // Due before anything else: the job picks it up and finishes.
        $this->em->getQueryExecutor()->execute(
            $this->em->getQueryBuilder()->update()->in(Offboarder::ENTITY_TYPE)
                ->set(['nextAttemptAt' => '2000-01-01 00:00:00'])->where(['id' => $record->getId()])->build()
        );
        $this->app->getInjectableFactory()->create(RunOffboardings::class)->run();

        $done = $this->em->getEntityById(Offboarder::ENTITY_TYPE, $record->getId());
        self::assertSame('completed', $done->get('status'), (string) $done->get('lastError'));
        self::assertSame(1, $this->callCount("DELETE /api/sessions/{$session}"), 'The WAHA session is ended once.');
        self::assertSame(['error' => 'not found'], $this->http('GET', "/platform/api/v1/accounts/{$remoteId}"));
        self::assertNull($this->em->getEntityById('Tenant', $tenant->getId()));
    }

    public function testRemovingAChatwootAccountEndsItsWhatsAppSessions(): void
    {
        $provisioning = $this->provision('Leaky Account');
        $session = $this->whatsApp($provisioning->get('chatwootAccountId'));

        $this->em->removeEntity($this->em->getEntityById('ChatwootAccount', $provisioning->get('chatwootAccountId')));

        self::assertSame(1, $this->callCount("DELETE /api/sessions/{$session}"));
        self::assertSame(1, $this->callCount('DELETE /platform/api/v1/accounts/:id'));
    }

    private function provision(string $name): Entity
    {
        $owner = $this->user();
        $provisioner = $this->app->getInjectableFactory()->create(Provisioner::class);
        $record = $provisioner->start(new ProvisioningRequest('signup:offboard-' . $this->suffix(), (string) $owner->getId(), $name));
        $done = $provisioner->run($record->getId());
        self::assertSame('completed', $done->get('status'), (string) $done->get('lastError'));

        return $done;
    }

    /**
     * A QR channel on the account with a live WAHA session; returns its name.
     */
    private function whatsApp(string $chatwootAccountId): string
    {
        $platform = $this->em->getRDBRepository('WahaPlatform')->where(['backendUrl' => $this->fake])->findOne()
            ?? $this->em->createEntity('WahaPlatform', [
                'name' => 'Fake WAHA', 'backendUrl' => $this->fake, 'apiKey' => 'waha-key',
            ], ['skipHooks' => true]);
        $session = 'ob-' . $this->suffix();

        $this->em->createEntity('ChatwootInboxIntegration', [
            'name' => 'WhatsApp ' . $session,
            'chatwootAccountId' => $chatwootAccountId,
            'channelType' => 'whatsappQrcode',
            'status' => 'ACTIVE',
            'wahaPlatformId' => $platform->getId(),
            'wahaSessionName' => $session,
        ], ['skipHooks' => true]);

        return $session;
    }

    private function tenant(Entity $provisioning): Entity
    {
        return $this->em->getEntityById('Tenant', $provisioning->get('tenantId'));
    }

    private function user(string $type = User::TYPE_REGULAR): User
    {
        $suffix = $this->suffix();

        /** @var User */
        return $this->em->createEntity('User', [
            'userName' => 'ob-' . $suffix,
            'lastName' => $suffix,
            'emailAddress' => "ob-{$suffix}@example.test",
            'type' => $type,
            'isActive' => true,
        ]);
    }

    private function offboarder(): Offboarder
    {
        return $this->app->getInjectableFactory()->create(Offboarder::class);
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
        // Letters first: the fake's call log turns "/<digits>" into "/:id".
        return 'x' . bin2hex(random_bytes(4));
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
