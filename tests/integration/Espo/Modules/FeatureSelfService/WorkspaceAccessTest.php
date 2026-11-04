<?php

declare(strict_types=1);

namespace tests\integration\Espo\Modules\FeatureSelfService;

use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Tools\Billing\AiBudget;
use Espo\Modules\FeatureSelfService\Jobs\ApplyWorkspaceAccess;
use Espo\Modules\FeatureSelfService\Tools\Policy\SelfServicePolicy;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\Provisioner;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\ProvisioningRequest;
use Espo\Modules\FeatureSelfService\Tools\Workspace\WorkspaceAccess;
use Espo\Modules\FeatureSelfService\Tools\Workspace\WorkspaceTrial;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * A trial that runs out freezes the workspace: Chatwoot is told (and retried
 * until it hears it) and the AI budget admits nothing. Same isolated runtime as
 * ProvisioningTest.
 */
class WorkspaceAccessTest extends TestCase
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

        putenv(SelfServicePolicy::ENV . '=true');
        $this->app->getInjectableFactory()->create(SelfServicePolicy::class)->resume();

        if (!$this->em->getRDBRepository('ChatwootPlatform')->findOne()) {
            $this->em->createEntity('ChatwootPlatform', [
                'name' => 'Fake Chatwoot', 'backendUrl' => $this->fake, 'frontendUrl' => $this->fake, 'accessToken' => 'platform-token',
            ], ['skipHooks' => true]);
        }
    }

    public function testAnEndedTrialFreezesTheWorkspace(): void
    {
        [$tenant, $account] = $this->workspaceOnTrial();
        $this->runJob();
        self::assertSame(WorkspaceAccess::STATUS_TRIAL, $this->reload($tenant)->get('subscriptionStatus'), 'Trial still running.');
        self::assertSame('active', $this->chatwootAccess($account));

        $this->endTrial($tenant);
        $this->runJob();

        $tenant = $this->reload($tenant);
        self::assertSame(WorkspaceAccess::STATUS_FROZEN, $tenant->get('subscriptionStatus'));
        self::assertNotEmpty($tenant->get('frozenAt'));
        self::assertSame('frozen', $tenant->get('chatwootAccess'));
        self::assertSame('frozen', $this->chatwootAccess($account));
        self::assertTrue($this->trial()->state($tenant)['trialEnded']);

        $budget = $this->app->getInjectableFactory()->create(AiBudget::class)
            ->execute((object) ['operation' => 'status', 'tenantId' => $tenant->getId()]);
        self::assertFalse($budget->allowed);
        self::assertSame('workspace_frozen', $budget->reason);
    }

    public function testAFailedPushToChatwootIsRetried(): void
    {
        [$tenant, $account] = $this->workspaceOnTrial();
        $this->runJob();
        $this->endTrial($tenant);

        $this->fault('PATCH /platform/api/v1/accounts/:id', 500, 1,
            '/platform/api/v1/accounts/' . (int) $account->get('chatwootAccountId'));
        $this->runJob();

        $tenant = $this->reload($tenant);
        self::assertSame(WorkspaceAccess::STATUS_FROZEN, $tenant->get('subscriptionStatus'));
        self::assertSame('active', $tenant->get('chatwootAccess'), 'Not recorded as pushed.');
        self::assertSame('active', $this->chatwootAccess($account));

        $this->runJob();
        self::assertSame('frozen', $this->reload($tenant)->get('chatwootAccess'));
        self::assertSame('frozen', $this->chatwootAccess($account));
    }

    public function testATenantWithoutAPlanIsNeverFrozen(): void
    {
        $team = $this->em->createEntity('Team', ['name' => 'Legacy ' . bin2hex(random_bytes(3))], ['skipHooks' => true]);
        $tenant = $this->em->createEntity('Tenant', [
            'name' => 'Legacy', 'slug' => 'legacy-' . bin2hex(random_bytes(4)), 'baseUserTeamId' => $team->getId(),
            'subscriptionStatus' => 'trial', 'trialEndsAt' => gmdate('Y-m-d H:i:s', time() - 60),
        ], ['skipHooks' => true]);

        $this->runJob();

        self::assertSame('trial', $this->reload($tenant)->get('subscriptionStatus'));
        self::assertEmpty($this->reload($tenant)->get('chatwootAccess'));
    }

    /**
     * @return array{0: Entity, 1: Entity}
     */
    private function workspaceOnTrial(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $owner = $this->em->createEntity('User', [
            'firstName' => 'Owner', 'lastName' => $suffix, 'emailAddress' => "access-{$suffix}@example.test",
            'type' => User::TYPE_REGULAR, 'isActive' => true,
        ]);
        $provisioner = $this->app->getInjectableFactory()->create(Provisioner::class);
        $record = $provisioner->start(new ProvisioningRequest("signup:access-{$suffix}", (string) $owner->getId(), "Access {$suffix}"));
        $done = $provisioner->run((string) $record->getId());
        self::assertSame('completed', $done->get('status'), (string) $done->get('lastError'));

        $account = $this->em->getEntityById('ChatwootAccount', $done->get('chatwootAccountId'));
        $this->em->createEntity('ChatwootInboxIntegration', [
            'name' => 'WhatsApp ' . $suffix, 'channelType' => 'whatsappQrcode', 'status' => 'ACTIVE',
            'chatwootAccountId' => $account->getId(),
        ]);

        $tenant = $this->em->getEntityById('Tenant', $done->get('tenantId'));
        self::assertSame(WorkspaceTrial::STEP_DONE, $tenant->get('onboardingStep'));

        return [$tenant, $account];
    }

    private function endTrial(Entity $tenant): void
    {
        $tenant = $this->reload($tenant);
        $tenant->set('trialEndsAt', gmdate('Y-m-d H:i:s', time() - 60));
        $this->em->saveEntity($tenant, ['silent' => true]);
    }

    private function runJob(): void
    {
        $this->app->getInjectableFactory()->create(ApplyWorkspaceAccess::class)->run();
    }

    private function chatwootAccess(Entity $account): ?string
    {
        $remote = json_decode((string) file_get_contents(
            $this->fake . '/platform/api/v1/accounts/' . (int) $account->get('chatwootAccountId')
        ), true);

        return $remote['custom_attributes']['workspace_access'] ?? null;
    }

    private function fault(string $call, int $status, int $times, string $path): void
    {
        file_get_contents($this->fake . '/__fault', false, stream_context_create(['http' => [
            'method' => 'POST', 'header' => 'Content-Type: application/json',
            'content' => json_encode(['call' => $call, 'status' => $status, 'times' => $times, 'path' => $path]),
        ]]));
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
