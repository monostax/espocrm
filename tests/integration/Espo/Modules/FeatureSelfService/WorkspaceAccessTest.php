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

    public function testAPaidPlanIsActiveThenPastDueThenFrozen(): void
    {
        [$tenant] = $this->workspaceOnTrial();
        $this->endTrial($tenant);
        $this->runJob();
        self::assertSame(WorkspaceAccess::STATUS_FROZEN, $this->reload($tenant)->get('subscriptionStatus'));

        $this->paidPlan((string) $tenant->getId(), 'advanced', 4, '2026-12-10 03:00:00');
        $access = $this->app->getInjectableFactory()->create(WorkspaceAccess::class);

        $access->applyBilling((string) $tenant->getId(), '2026-12-01 12:00:00');
        $tenant = $this->reload($tenant);
        self::assertSame(WorkspaceAccess::STATUS_ACTIVE, $tenant->get('subscriptionStatus'), 'Paying unfreezes.');
        self::assertSame('advanced', $tenant->get('plan'));
        self::assertSame(4, $tenant->get('seats'));
        self::assertEmpty($tenant->get('frozenAt'));
        self::assertFalse($this->trial()->state($tenant)['trialEnded']);

        $access->freezeEndedTrials('2026-12-01 12:00:00');
        self::assertSame(WorkspaceAccess::STATUS_ACTIVE, $this->reload($tenant)->get('subscriptionStatus'), 'The trial rule leaves payers alone.');

        $access->applyBilling((string) $tenant->getId(), '2026-12-11 12:00:00');
        self::assertSame(WorkspaceAccess::STATUS_PAST_DUE, $this->reload($tenant)->get('subscriptionStatus'), 'Renewal unpaid: grace.');

        $access->applyBilling((string) $tenant->getId(), '2026-12-13 04:00:00');
        self::assertSame(WorkspaceAccess::STATUS_FROZEN, $this->reload($tenant)->get('subscriptionStatus'), 'Grace over.');

        $this->paidPlan((string) $tenant->getId(), 'advanced', 4, '2027-01-10 03:00:00');
        $access->applyBilling((string) $tenant->getId(), '2026-12-14 12:00:00');
        self::assertSame(WorkspaceAccess::STATUS_ACTIVE, $this->reload($tenant)->get('subscriptionStatus'), 'Paying the renewal reopens.');
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
        self::assertSame(WorkspaceTrial::STEP_IMPORT_HISTORY, $tenant->get('onboardingStep'));

        return [$tenant, $account];
    }

    /**
     * A plan subscription with one funded period ending at $paidThrough, as the
     * billing engine leaves them after a verified payment (only the columns
     * WorkspaceAccess reads carry meaning).
     */
    private function paidPlan(string $tenantId, string $plan, int $seats, string $paidThrough): void
    {
        $pdo = $this->em->getPDO();
        $obligation = $pdo->query("SELECT id FROM credit_billing_obligation WHERE tenant_id = " . $pdo->quote($tenantId))->fetchColumn();
        $now = gmdate('Y-m-d H:i:s');

        if (!$obligation) {
            $obligation = substr(bin2hex(random_bytes(9)), 0, 17);
            $snapshot = json_encode(['schema' => 'billing-offer-v2', 'operation' => 'subscription', 'price' => '2153.00',
                'seats' => ['plan' => $plan, 'unitPrice' => '289.00', 'min' => 1, 'max' => 50, 'quantity' => $seats, 'basePrice' => '997.00']]);
            $pdo->prepare('INSERT INTO credit_billing_obligation (id, tenant_id, request_key, input_hash, provider, integration_id,
                catalog_id, terms_id, terms_snapshot, operation, billing_country, assignment_revision, state, created_at, created_by_id,
                acceptance_hash, deleted)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$obligation, $tenantId, 'test/' . $obligation, str_repeat('a', 64), 'asaas', 'test', str_repeat('b', 64),
                    str_repeat('c', 64), $snapshot, 'subscription', 'BR', 1, 'active', $now, 'system', str_repeat('d', 64), 0]);
        }

        $pdo->prepare('INSERT INTO credit_billing_period (id, tenant_id, obligation_id, period_key, starts_at, ends_at, terms_id,
            binding_hash, created_at, paid_payment_id, deleted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([substr(bin2hex(random_bytes(9)), 0, 17), $tenantId, $obligation, $paidThrough,
                gmdate('Y-m-d H:i:s', strtotime($paidThrough . ' UTC') - 30 * 86400), $paidThrough, str_repeat('c', 64),
                str_repeat('e', 64), $now, 'pay' . substr(bin2hex(random_bytes(8)), 0, 14), 0]);
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
