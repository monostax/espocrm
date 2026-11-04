<?php

declare(strict_types=1);

namespace tests\integration\Espo\Modules\FeatureSelfService;

use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Core\Utils\Config;
use Espo\Entities\User;
use Espo\Modules\FeatureCredits\Services\Configuration;
use Espo\Modules\FeatureSelfService\Tools\Policy\SelfServicePolicy;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\Provisioner;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\ProvisioningRequest;
use Espo\Modules\FeatureSelfService\Tools\Workspace\WorkspaceWallet;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * Self-service workspaces start on the credit wallet: provisioning publishes
 * the agreement and schedules the cutover, and the trial's credits arrive when
 * the trial starts, expiring with it. Same isolated runtime as ProvisioningTest.
 */
class WorkspaceWalletTest extends TestCase
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

    public function testAWorkspaceStartsOnTheWalletAndGetsItsTrialCredits(): void
    {
        $rate = $this->app->getInjectableFactory()->create(Configuration::class)->publishModel((object) [
            'provider' => 'google', 'model' => 'gemini-3.8-flash', 'multiplier' => '1',
            'inputTokenBound' => 262144, 'outputTokenLimit' => 16384, 'boundProfile' => 'gemini38-flash-medium-signature-bytes-v1',
        ]);
        $wallet = $this->wallet(['aiModelCreditRateId' => $rate['id'], 'creditUnitPrice' => '0.50', 'currency' => 'BRL',
            'aiMaxRequests' => 100, 'trialCredits' => '100']);

        [$tenantId, $provisioning] = $this->provision($wallet);
        $pdo = $this->em->getPDO();

        $agreement = $pdo->query("SELECT version, currency, credit_unit_price, monthly_credits, ai_model_credit_rate_id, ai_max_requests
            FROM tenant_credit_billing_rate WHERE tenant_id = " . $pdo->quote($tenantId))->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(WorkspaceWallet::AGREEMENT_VERSION, $agreement['version']);
        self::assertSame('BRL', $agreement['currency']);
        self::assertEquals(0.5, (float) $agreement['credit_unit_price']);
        self::assertSame($rate['id'], $agreement['ai_model_credit_rate_id']);
        self::assertSame(100, (int) $agreement['ai_max_requests']);
        $cutoverAt = $pdo->query("SELECT cutover_at FROM tenant_credit_cutover WHERE tenant_id = " . $pdo->quote($tenantId))->fetchColumn();
        self::assertGreaterThan(gmdate('Y-m-d H:i:s'), $cutoverAt, 'Scheduled ahead, never backdated.');

        // A retried step replays the agreement and keeps the cutover.
        $wallet->ensure($tenantId, (string) $provisioning->get('createdAt'), (string) $provisioning->getId());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM tenant_credit_billing_rate WHERE tenant_id = " . $pdo->quote($tenantId))->fetchColumn());

        $tenant = $this->em->getEntityById('Tenant', $tenantId);
        self::assertFalse($wallet->grantTrialCredits($tenant), 'No credits before the trial starts.');

        $account = $this->em->getRDBRepository('ChatwootAccount')->where(['tenantId' => $tenantId])->findOne();
        $this->em->createEntity('ChatwootInboxIntegration', [
            'name' => 'WhatsApp', 'channelType' => 'whatsappQrcode', 'status' => 'ACTIVE', 'chatwootAccountId' => $account->getId(),
        ]);
        $tenant = $this->em->getEntityById('Tenant', $tenantId);

        self::assertTrue($wallet->grantTrialCredits($tenant));
        $grant = $pdo->query("SELECT source_type, granted_credits, expires_at FROM credit_grant WHERE tenant_id = " . $pdo->quote($tenantId))
            ->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('migration', $grant['source_type']);
        self::assertEquals(100.0, (float) $grant['granted_credits']);
        self::assertSame((string) $tenant->get('trialEndsAt'), (string) $grant['expires_at'], 'Trial credits end with the trial.');
        self::assertFalse($wallet->grantTrialCredits($tenant), 'Granted once.');
        self::assertSame(0, $wallet->grantStartedTrials());
    }

    public function testWithoutTheSettingWorkspacesKeepTheLegacyCap(): void
    {
        $wallet = $this->wallet(null);
        self::assertFalse($wallet->isEnabled());

        [$tenantId] = $this->provision($wallet);
        $pdo = $this->em->getPDO();

        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM tenant_credit_billing_rate WHERE tenant_id = " . $pdo->quote($tenantId))->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM tenant_credit_cutover WHERE tenant_id = " . $pdo->quote($tenantId))->fetchColumn());
        self::assertSame(80, $this->em->getRDBRepository('TenantAiBillingRate')->where(['tenantId' => $tenantId])->findOne()->get('planIncludedCredits'));
    }

    private function wallet(?array $settings): WorkspaceWallet
    {
        $config = $this->createStub(Config::class);
        $config->method('get')->willReturnCallback(fn (string $name) => $name === 'selfServiceCreditWallet' ? $settings : null);

        return $this->app->getInjectableFactory()->createWith(WorkspaceWallet::class, ['config' => $config]);
    }

    /**
     * @return array{0: string, 1: \Espo\ORM\Entity}
     */
    private function provision(WorkspaceWallet $wallet): array
    {
        $suffix = bin2hex(random_bytes(4));
        $owner = $this->em->createEntity('User', [
            'firstName' => 'Owner', 'lastName' => $suffix, 'emailAddress' => "wallet-{$suffix}@example.test",
            'type' => User::TYPE_REGULAR, 'isActive' => true,
        ]);
        $provisioner = $this->app->getInjectableFactory()->createWith(Provisioner::class, ['wallet' => $wallet]);
        $record = $provisioner->start(new ProvisioningRequest("signup:wallet-{$suffix}", (string) $owner->getId(), "Wallet {$suffix}"));
        $done = $provisioner->run((string) $record->getId());
        self::assertSame('completed', $done->get('status'), (string) $done->get('lastError'));

        return [(string) $done->get('tenantId'), $done];
    }
}
