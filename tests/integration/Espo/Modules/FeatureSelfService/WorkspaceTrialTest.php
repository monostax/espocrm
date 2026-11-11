<?php

declare(strict_types=1);

namespace tests\integration\Espo\Modules\FeatureSelfService;

use Espo\Core\Acl;
use Espo\Core\AclManager;
use Espo\Core\Api\Request;
use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\FeatureSelfService\Controllers\SelfServiceWorkspace;
use Espo\Modules\FeatureSelfService\Tools\Policy\SelfServicePolicy;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\Provisioner;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\ProvisioningRequest;
use Espo\Modules\FeatureSelfService\Tools\Workspace\WorkspaceTrial;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * A self-service workspace waits on "connect WhatsApp" and its 7 days start
 * with the first connected WhatsApp channel. Same isolated runtime as
 * ProvisioningTest.
 */
class WorkspaceTrialTest extends TestCase
{
    private Application $app;
    private EntityManager $em;

    protected function setUp(): void
    {
        $database = getenv('SELF_SERVICE_TEST_DATABASE');
        $fake = (string) getenv('FAKE_CHATWOOT_URL');

        if (!$database || $fake === '') {
            self::markTestSkipped('Set SELF_SERVICE_TEST_DATABASE and FAKE_CHATWOOT_URL in an isolated CRM runtime.');
        }

        $this->app = new Application(new ApplicationParams(noErrorHandler: true));
        self::assertSame($database, $this->app->getContainer()->get('config')->get('database.dbname'));
        $this->app->setupSystemUser();
        $this->em = $this->app->getContainer()->get('entityManager');

        putenv(SelfServicePolicy::ENV . '=true');
        $this->app->getInjectableFactory()->create(SelfServicePolicy::class)->resume();

        if (!$this->em->getRDBRepository('ChatwootPlatform')->findOne()) {
            $this->em->createEntity('ChatwootPlatform', [
                'name' => 'Fake Chatwoot', 'backendUrl' => $fake, 'frontendUrl' => $fake, 'accessToken' => 'platform-token',
            ], ['skipHooks' => true]);
        }
    }

    public function testTheTrialStartsWithTheFirstConnectedWhatsapp(): void
    {
        [$tenant, $account] = $this->workspace();

        self::assertSame('monostax', $tenant->get('plan'));
        self::assertSame('trial', $tenant->get('subscriptionStatus'));
        self::assertSame(WorkspaceTrial::STEP_CONNECT_WHATSAPP, $tenant->get('onboardingStep'));
        self::assertNull($tenant->get('trialStartsAt'));

        $integration = $this->integration($account, 'whatsappCoexistence', 'PENDING_COEXISTENCE_CONFIRMATION');
        self::assertNull($this->reload($tenant)->get('trialStartsAt'), 'Not connected yet.');

        $integration->set('status', 'ACTIVE');
        $this->em->saveEntity($integration);

        $tenant = $this->reload($tenant);
        self::assertSame(WorkspaceTrial::STEP_IMPORT_HISTORY, $tenant->get('onboardingStep'), 'Its history comes next.');
        self::assertSame(
            7 * 86400,
            strtotime($tenant->get('trialEndsAt') . ' UTC') - strtotime($tenant->get('trialStartsAt') . ' UTC')
        );

        $endsAt = $tenant->get('trialEndsAt');
        $this->integration($account, 'whatsappQrcode', 'ACTIVE');
        self::assertSame($endsAt, $this->reload($tenant)->get('trialEndsAt'), 'A second number does not restart the trial.');
    }

    public function testACloudApiNumberOpensTheWorkspaceAtOnce(): void
    {
        [$tenant, $account] = $this->workspace();

        $this->integration($account, 'whatsappCloudApi', 'ACTIVE');

        $tenant = $this->reload($tenant);
        self::assertSame(WorkspaceTrial::STEP_DONE, $tenant->get('onboardingStep'));
        self::assertNotEmpty($tenant->get('trialStartsAt'));
    }

    public function testOnboardingWaitsForTheNewestHistoryWindow(): void
    {
        [$tenant, $account] = $this->workspace();
        $integration = $this->integration($account, 'whatsappQrcode', 'ACTIVE');
        $tenant = $this->reload($tenant);
        $minuteLater = strtotime($tenant->get('channelConnectedAt') . ' UTC') + 60;

        self::assertSame(WorkspaceTrial::STEP_IMPORT_HISTORY, $tenant->get('onboardingStep'));
        self::assertFalse($this->trial()->finishImportIfSettled($tenant, $minuteLater), 'Nothing reported yet.');

        $inbox = $this->em->createEntity('ChatwootInbox', [
            'name' => 'QR', 'chatwootAccountId' => $account->getId(), 'chatwootInboxId' => random_int(100000, 999999),
            'chatwootInboxIntegrationId' => $integration->getId(),
            'historyImportStatus' => 'importing', 'historyImportPhase' => 0,
        ], ['skipHooks' => true]);
        self::assertFalse($this->trial()->finishImportIfSettled($tenant, $minuteLater), 'Last 3 days still coming.');

        $inbox->set('historyImportPhase', 1);
        $this->em->saveEntity($inbox, ['skipHooks' => true]);
        self::assertTrue($this->trial()->finishImportIfSettled($tenant, $minuteLater));
        self::assertSame(WorkspaceTrial::STEP_DONE, $this->reload($tenant)->get('onboardingStep'));
    }

    public function testInstagramDoesNotStartTheTrial(): void
    {
        [$tenant, $account] = $this->workspace();

        $this->integration($account, 'instagram', 'ACTIVE');

        self::assertSame(WorkspaceTrial::STEP_CONNECT_WHATSAPP, $this->reload($tenant)->get('onboardingStep'));
    }

    public function testATenantWithoutAPlanIsNotSelfService(): void
    {
        $team = $this->em->createEntity('Team', ['name' => 'Legacy ' . bin2hex(random_bytes(3))], ['skipHooks' => true]);
        $tenant = $this->em->createEntity('Tenant', [
            'name' => 'Legacy customer', 'slug' => 'legacy-' . bin2hex(random_bytes(4)), 'baseUserTeamId' => $team->getId(),
        ], ['skipHooks' => true]);
        $account = $this->em->createEntity('ChatwootAccount', [
            'name' => 'Legacy', 'tenantId' => $tenant->getId(), 'platformId' => $this->em->getRDBRepository('ChatwootPlatform')->findOne()->getId(),
            'chatwootAccountId' => random_int(100000, 999999), 'status' => 'active',
        ], ['skipHooks' => true]);

        $this->integration($account, 'whatsappQrcode', 'ACTIVE');

        $tenant = $this->reload($tenant);
        self::assertSame(['selfService' => false], $this->trial()->state($tenant));
        self::assertEmpty($tenant->get('trialStartsAt'));
    }

    public function testAnotherWorkspacesOwnerCannotReadTheState(): void
    {
        [, $account, $owner] = $this->workspace();
        [, , $stranger] = $this->workspace();

        $state = $this->controller($owner)->getActionState($this->request((int) $account->get('chatwootAccountId')));
        self::assertTrue($state->selfService);
        self::assertSame(WorkspaceTrial::STEP_CONNECT_WHATSAPP, $state->onboardingStep);

        $this->expectException(Forbidden::class);
        $this->controller($stranger)->getActionState($this->request((int) $account->get('chatwootAccountId')));
    }

    /**
     * @return array{0: Entity, 1: Entity, 2: User}
     */
    private function workspace(): array
    {
        $suffix = bin2hex(random_bytes(4));
        /** @var User $owner */
        $owner = $this->em->createEntity('User', [
            'firstName' => 'Owner', 'lastName' => $suffix, 'emailAddress' => "trial-{$suffix}@example.test",
            'type' => User::TYPE_REGULAR, 'isActive' => true,
        ]);
        $provisioner = $this->app->getInjectableFactory()->create(Provisioner::class);
        $record = $provisioner->start(new ProvisioningRequest("signup:trial-{$suffix}", (string) $owner->getId(), "Trial {$suffix}"));
        $done = $provisioner->run((string) $record->getId());
        self::assertSame('completed', $done->get('status'), (string) $done->get('lastError'));

        return [
            $this->em->getEntityById('Tenant', $done->get('tenantId')),
            $this->em->getEntityById('ChatwootAccount', $done->get('chatwootAccountId')),
            $this->em->getEntityById('User', $owner->getId()),
        ];
    }

    private function integration(Entity $account, string $channelType, string $status): Entity
    {
        return $this->em->createEntity('ChatwootInboxIntegration', [
            'name' => "{$channelType} " . bin2hex(random_bytes(3)),
            'channelType' => $channelType,
            'status' => $status,
            'chatwootAccountId' => $account->getId(),
        ]);
    }

    private function controller(User $user): SelfServiceWorkspace
    {
        /** @var AclManager $aclManager */
        $aclManager = $this->app->getContainer()->get('aclManager');
        $acl = new Acl($aclManager, $user);

        return $this->app->getInjectableFactory()->createWith(SelfServiceWorkspace::class, [
            'user' => $user,
            'acl' => $acl,
        ]);
    }

    private function request(int $accountId): Request
    {
        $request = $this->createMock(Request::class);
        $request->method('getQueryParam')->willReturnCallback(fn (string $name) => $name === 'accountId' ? (string) $accountId : null);

        return $request;
    }

    private function trial(): WorkspaceTrial
    {
        return $this->app->getInjectableFactory()->create(WorkspaceTrial::class);
    }

    private function reload(Entity $entity): Entity
    {
        return $this->em->getEntityById($entity->getEntityType(), $entity->getId());
    }
}
