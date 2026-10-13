<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Doctrine\DBAL\Platforms\{MySQL80Platform, PostgreSQLPlatform};
use Espo\Core\Exceptions\{Conflict, NotFound};
use Espo\Core\Api\{Request, Response};
use Espo\Entities\User;
use Espo\Modules\FeatureAiUsage\Services\Access;
use Espo\Modules\FeatureCredits\Controllers\CreditBilling;
use Espo\Modules\Global\Tools\Tenant\{TenantResolver, UserTenantResolver};
use Espo\Core\Utils\{Config, Crypt};
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Billing\{Accounts, Catalog, Checkout, Creation, CreationStore, Providers};
use Espo\Modules\FeatureCredits\Billing\Asaas\{Adapter, Connections};
use Espo\ORM\EntityManager;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SchemaTest.php';
require_once __DIR__ . '/BillingFixtures.php';
require_once __DIR__ . '/BillingAccountFixtures.php';
require_once __DIR__ . '/AsaasFixtures.php';

final class BillingCreationDatabaseTest extends TestCase
{
    use SchemaFixture;
    private string $now = '2026-10-12 12:00:00';

    public static function dialects(): iterable { yield 'MySQL' => ['Mysql']; yield 'PostgreSQL' => ['Postgresql']; }
    public static function operations(): iterable
    {
        foreach (self::dialects() as $name => [$dialect]) foreach (['pack-br', 'monthly-br'] as $offer) yield "$name $offer" => [$dialect, $offer];
    }

    private function database(string $dialect, callable $test): void
    {
        $dsn = getenv('FEATURE_CREDITS_' . strtoupper($dialect) . '_DSN');
        if (!$dsn) $this->markTestSkipped('Requires an empty feature_credits_schema_test database.');
        $pdo = new PDO($dsn, 'credits_test', 'credits_test', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->assertSame('feature_credits_schema_test', $pdo->query($dialect === 'Mysql' ? 'SELECT DATABASE()' : 'SELECT current_database()')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query($dialect === 'Mysql' ?
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()' :
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public'")->fetchColumn(), 'Refusing a nonempty database.');
        $schema = $this->schema($dialect); $platform = $dialect === 'Mysql' ? new MySQL80Platform() : new PostgreSQLPlatform();
        try {
            foreach ($schema->toSql($platform) as $sql) $pdo->exec($sql);
            $pdo->exec('CREATE TABLE tenant (id VARCHAR(24) PRIMARY KEY, deleted BOOLEAN NOT NULL DEFAULT FALSE)');
            $pdo->exec("INSERT INTO tenant (id) VALUES ('tenant'), ('other'), ('third')");
            $test($pdo);
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack(); $pdo->exec('DROP TABLE IF EXISTS tenant');
            foreach (array_reverse($schema->getTables()) as $table) $pdo->exec('DROP TABLE IF EXISTS ' . $platform->quoteIdentifier($table->getName()));
        }
    }

    private function prepare(PDO $pdo): array
    {
        $manager = $this->createStub(EntityManager::class); $manager->method('getPDO')->willReturn($pdo);
        $clock = $this->createStub(Clock::class); $clock->method('now')->willReturnCallback(fn () => $this->now);
        $config = $this->createStub(Config::class); $config->method('get')->willReturn('billing-creation-test-crypt-key');
        $providers = new BillingTestProviders(); $connections = new AsaasTestConnections(); $transport = new AsaasTestTransport();
        $transport->onSend = function () use ($pdo): void { $this->assertFalse($pdo->inTransaction(), 'External I/O under a database lock.'); };
        $catalog = new Catalog($manager, $clock); $catalog->publish(BillingFixtures::definition(), 'admin');
        $accounts = new Accounts($manager, $clock, $catalog, $providers);
        $store = new CreationStore($accounts, new Crypt($config), $connections); $creation = new Creation($store, new Adapter($transport));
        $this->now = '2026-10-13 00:00:00';
        return [$accounts, $catalog, $store, $creation, $connections, $transport, $providers];
    }

    private function accept(Accounts $accounts, Catalog $catalog, string $tenant = 'tenant', string $key = 'buy', string $offer = 'pack-br'): string
    {
        $context = $accounts->context($tenant);
        if ($context['revision'] === 0) $accounts->changeCountry($tenant, 'country', 0, 'BR', 'admin');
        $card = $catalog->current();
        return $accounts->accept($tenant, $key, $accounts->context($tenant)['revision'], $card->id, $offer, $card->offers[$offer]->termsId, 'admin')['obligationId'];
    }

    private function snapshot(PDO $pdo): array
    {
        return array_map(static fn ($table) => $pdo->query("SELECT * FROM $table ORDER BY id")->fetchAll(PDO::FETCH_ASSOC), ['credit_billing_creation', 'credit_billing_obligation']);
    }

    private function posts(AsaasTestTransport $transport): int { return count(array_filter($transport->calls, static fn ($call) => $call[2] === 'POST')); }

    #[DataProvider('operations')]
    public function testCheckoutResumesPersistedCreationAndReturnsOnlyVerifiedOriginalPaymentLink(string $dialect, string $offer): void
    {
        $this->database($dialect, function (PDO $pdo) use ($offer): void {
            [$accounts, $catalog, $store, $creation, $connections, $transport] = $this->prepare($pdo);
            $id = $this->accept($accounts, $catalog, offer: $offer);
            $checkout = new Checkout($accounts, $store, $connections, $transport);
            $before = $checkout->read('tenant', $id);
            $this->assertSame('accepted', $before['state']); $this->assertNull($before['customer']);
            $customer = $creation->customer('tenant', $id, AsaasTestTransport::profile());
            $this->assertSame($customer['creationId'], $checkout->read('tenant', $id)['customer']['creationId']);
            $charge = $creation->charge('tenant', $id, $customer['creationId'], 'BOLETO', '2026-10-15');
            $state = $checkout->read('tenant', $id);
            $this->assertSame('pending', $state['state']); $this->assertSame($charge['creationId'], $state['charge']['creationId']);
            $this->assertSame($before['acceptance'], $state['acceptance']);
            $this->assertCount(1, $checkout->context('tenant')['checkouts']);
            $encoded = json_encode([$state, $checkout->context('tenant')], JSON_THROW_ON_ERROR);
            foreach (['cpfCnpj', '52998224725', 'Sandbox Payer', 'payer@example.test', 'test-only-key', 'external_id', 'request_ciphertext'] as $secret) $this->assertStringNotContainsString($secret, $encoded);
            $payment = ['object' => 'payment', 'id' => $offer === 'pack-br' ? $charge['externalId'] : 'pay_initial',
                'customer' => $customer['externalId'], 'value' => $state['acceptance']['terms']['price'],
                'billingType' => 'BOLETO', 'originalDueDate' => '2026-10-15', 'deleted' => false, 'status' => 'PENDING',
                'invoiceUrl' => 'https://sandbox.asaas.com/i/original'];
            if ($offer === 'monthly-br') $payment['subscription'] = $charge['externalId'];
            else {
                foreach ($transport->objects as $object) if ($object['id'] === $charge['externalId']) $payment['externalReference'] = $object['externalReference'];
            }
            $calls = 0; $listMode = 'success';
            $lookup = $this->createMock(\Espo\Modules\FeatureCredits\Billing\Asaas\Transport::class);
            $lookup->method('send')->willReturnCallback(function ($connection, $method, $path, $json = null, $query = []) use ($pdo, &$payment, &$calls, &$listMode, $charge, $customer) {
                ++$calls; $this->assertFalse($pdo->inTransaction()); $this->assertSame('GET', $method); $this->assertNull($json);
                $this->assertSame('test-asaas', $connection->id);
                if ($path === '/payments') {
                    $this->assertSame(['subscription' => $charge['externalId'], 'customer' => $customer['externalId'],
                        'dueDate[ge]' => '2026-10-15', 'dueDate[le]' => '2026-10-15', 'offset' => 0, 'limit' => 2], $query);
                    $rows = $listMode === 'duplicate' ? [$payment, $payment] : ($listMode === 'empty' ? [] : [$payment]);
                    return new \Espo\Modules\FeatureCredits\Billing\Asaas\Response(200, ['object' => 'list', 'hasMore' => $listMode === 'truncated',
                        'offset' => '0', 'totalCount' => (string) count($rows), 'data' => $rows]);
                }
                $this->assertSame('/payments/' . $payment['id'], $path);
                return new \Espo\Modules\FeatureCredits\Billing\Asaas\Response(200, $payment);
            });
            $checkout = new Checkout($accounts, $store, $connections, $lookup);
            $connections->enabled = false;
            $saved = $this->snapshot($pdo);
            $link = $checkout->payment('tenant', $id);
            $this->assertSame($payment['invoiceUrl'], $link['invoiceUrl']); $this->assertSame('pending', $link['state']);
            $this->assertSame($link, $checkout->payment('tenant', $id));
            $this->assertSame($saved, $this->snapshot($pdo));
            foreach (['credit_grant', 'credit_transaction', 'credit_billing_payment', 'tenant_credit_balance'] as $table) $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn());
            $payment['status'] = 'RECEIVED';
            $this->assertSame('paid', $checkout->payment('tenant', $id)['state']); $this->assertNull($checkout->payment('tenant', $id)['invoiceUrl']);
            $payment['status'] = 'REFUNDED';
            $this->assertSame('review_required', $checkout->payment('tenant', $id)['state']);
            $payment['status'] = 'PENDING';
            foreach (['customer' => 'cus_foreign', 'value' => '0.01', 'originalDueDate' => '2026-11-15',
                'invoiceUrl' => 'https://sandbox.asaas.com.evil.test/i/pay'] as $key => $value) {
                $original = $payment[$key]; $payment[$key] = $value;
                try { $checkout->payment('tenant', $id); $this->fail('Unverified handoff allowed.'); } catch (Conflict) {}
                $payment[$key] = $original;
            }
            if ($offer === 'monthly-br') foreach (['empty', 'duplicate', 'truncated'] as $mode) {
                $listMode = $mode;
                try { $checkout->payment('tenant', $id); $this->fail('Ambiguous listing accepted.'); }
                catch (Conflict | \Espo\Core\Exceptions\ServiceUnavailable) {}
            }
            $count = $calls;
            try { $checkout->payment('other', $id); $this->fail('Foreign obligation exposed.'); } catch (NotFound) {}
            $connections->environment = 'production';
            try { $checkout->payment('tenant', $id); $this->fail('Environment changed.'); } catch (Conflict) {}
            $this->assertSame($count, $calls);
        });
    }

    #[DataProvider('dialects')]
    public function testCheckoutShowsUnknownCreationAndSharedCustomerWithoutRedispatch(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$accounts, $catalog, $store, $creation, $connections, $transport] = $this->prepare($pdo);
            $id = $this->accept($accounts, $catalog); $transport->mode = 'lost';
            $unknown = $creation->customer('tenant', $id, AsaasTestTransport::profile());
            $checkout = new Checkout($accounts, $store, $connections, $transport);
            $this->assertSame('unknown', $checkout->read('tenant', $id)['customer']['state']);
            $this->assertSame(1, $this->posts($transport));
            try { $checkout->payment('tenant', $id); $this->fail('Unbound handoff allowed.'); } catch (Conflict) {}
            $transport->mode = 'success'; $creation->reconcile('tenant', $unknown['creationId']);
            $second = $this->accept($accounts, $catalog, key: 'second');
            $this->assertSame($unknown['creationId'], $checkout->read('tenant', $second)['customer']['creationId']);
            $this->assertSame(1, $this->posts($transport));
            $connections->available = false;
            $this->assertSame('bound', $checkout->read('tenant', $second)['customer']['state']);
        });
    }

    private function checkoutController(Accounts $accounts, Creation $creation): CreditBilling
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn('admin');
        $user->method('getLinkMultipleIdList')->willReturn([md5('tenant-admin')]);
        $memberships = $this->createStub(UserTenantResolver::class);
        $memberships->method('resolveTenantIds')->willReturn(['tenant']);
        $manager = $this->createStub(EntityManager::class);
        $manager->method('getEntityById')->willReturn($this->createStub(\Espo\ORM\Entity::class));
        return new CreditBilling($user, new Access($user, $manager, $this->createStub(TenantResolver::class), $memberships), $accounts, $creation);
    }

    private function checkout(CreditBilling $controller, string $action, array $body): array
    {
        $request = $this->createStub(Request::class);
        $request->method('getBodyContents')->willReturn(json_encode($body, JSON_THROW_ON_ERROR));
        $response = $this->createMock(Response::class);
        $response->expects($this->once())->method('setHeader')->with('Cache-Control', 'private, no-store');
        return (array) $controller->$action($request, $response);
    }

    #[DataProvider('operations')]
    public function testAuthorizedHttpCheckoutPreservesTermsAndReplaysWithoutProviderPosts(string $dialect, string $offer): void
    {
        $this->database($dialect, function (PDO $pdo) use ($offer): void {
            [$accounts, $catalog, , $creation, $connections, $transport] = $this->prepare($pdo);
            $controller = $this->checkoutController($accounts, $creation);
            $this->checkout($controller, 'postActionCountry', ['tenantId' => 'tenant', 'requestKey' => 'country', 'revision' => 0, 'billingCountry' => 'BR']);
            $card = $catalog->current();
            $accept = ['tenantId' => 'tenant', 'requestKey' => 'checkout', 'revision' => $accounts->context('tenant')['revision'],
                'catalogId' => $card->id, 'offerId' => $offer, 'termsId' => $card->offers[$offer]->termsId];
            $accepted = $this->checkout($controller, 'postActionAccept', $accept);
            $this->assertSame($accepted, $this->checkout($controller, 'postActionAccept', $accept));
            $payer = ['tenantId' => 'tenant', 'obligationId' => $accepted['obligationId'], 'payer' => AsaasTestTransport::profile()];
            $customer = $this->checkout($controller, 'postActionCustomer', $payer);
            $charge = ['tenantId' => 'tenant', 'obligationId' => $accepted['obligationId'], 'customerCreationId' => $customer['creationId'], 'billingType' => 'BOLETO', 'dueDate' => '2026-10-15'];
            $receipt = $this->checkout($controller, 'postActionCharge', $charge);
            $this->assertSame('bound', $receipt['state']);
            $this->assertSame($offer === 'pack-br' ? 'payment' : 'subscription', $receipt['kind']);
            $this->assertSame(2, $this->posts($transport));
            $connections->enabled = false; $connections->available = false;
            $this->assertSame($customer, $this->checkout($controller, 'postActionCustomer', $payer));
            $this->assertSame($receipt, $this->checkout($controller, 'postActionCharge', $charge));
            $this->assertSame($receipt, $this->checkout($controller, 'postActionRecoverCreation', ['tenantId' => 'tenant', 'creationId' => $receipt['creationId']]));
            $this->assertCount(2, $transport->calls);
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM credit_grant')->fetchColumn());
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM credit_transaction')->fetchColumn());
            $this->assertSame($accepted['terms'], $accounts->readObligation('tenant', $accepted['obligationId'])['acceptance']['terms']);
            $encoded = json_encode([$customer, $receipt], JSON_THROW_ON_ERROR);
            foreach (['cpfCnpj', 'Sandbox Payer', '52998224725', 'request_ciphertext', 'test-only-key'] as $secret) $this->assertStringNotContainsString($secret, $encoded);
        });
    }

    #[DataProvider('dialects')]
    public function testHttpUnknownRecoveryForeignBindingsAndInvalidPayerNeverDuplicateCreation(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$accounts, $catalog, , $creation, , $transport] = $this->prepare($pdo);
            $id = $this->accept($accounts, $catalog);
            $foreign = $this->accept($accounts, $catalog, 'other');
            $controller = $this->checkoutController($accounts, $creation);
            $payer = ['tenantId' => 'tenant', 'obligationId' => $id, 'payer' => AsaasTestTransport::profile()];
            foreach ([['payer' => AsaasTestTransport::profile() + ['externalReference' => 'forged']],
                ['payer' => array_replace(AsaasTestTransport::profile(), ['cpfCnpj' => '11111111111'])],
                ['payer' => array_replace(AsaasTestTransport::profile(), ['notificationDisabled' => 'true'])]] as $invalid) {
                try { $this->checkout($controller, 'postActionCustomer', array_replace($payer, $invalid)); $this->fail('Invalid payer accepted.'); }
                catch (\Espo\Core\Exceptions\BadRequest) {}
            }
            try { $this->checkout($controller, 'postActionCustomer', array_replace($payer, ['obligationId' => $foreign])); $this->fail('Foreign obligation accepted.'); }
            catch (NotFound) {}
            $this->assertSame([], $transport->calls);
            $transport->mode = 'lost';
            $unknown = $this->checkout($controller, 'postActionCustomer', $payer);
            $this->assertSame('unknown', $unknown['state']);
            $this->assertSame($unknown, $this->checkout($controller, 'postActionCustomer', $payer));
            $transport->mode = 'success';
            $customer = $this->checkout($controller, 'postActionRecoverCreation', ['tenantId' => 'tenant', 'creationId' => $unknown['creationId']]);
            $this->assertSame('bound', $customer['state']);
            $this->assertSame(1, $this->posts($transport));
            $foreignCustomer = $creation->customer('other', $foreign, AsaasTestTransport::profile());
            $before = count($transport->calls);
            foreach (['postActionRecoverCreation' => ['tenantId' => 'tenant', 'creationId' => $foreignCustomer['creationId']],
                'postActionCharge' => ['tenantId' => 'tenant', 'obligationId' => $id, 'customerCreationId' => $foreignCustomer['creationId'], 'billingType' => 'PIX', 'dueDate' => '2026-10-15']] as $action => $body) {
                try { $this->checkout($controller, $action, $body); $this->fail('Foreign creation accepted.'); } catch (NotFound) {}
            }
            $this->assertCount($before, $transport->calls);
        });
    }

    #[DataProvider('operations')]
    public function testCustomerAndChargeUseOriginalAcceptedTermsAndNeverGrantCredits(string $dialect, string $offer): void
    {
        $this->database($dialect, function (PDO $pdo) use ($offer): void {
            [$accounts, $catalog, $store, $creation, $connections, $transport] = $this->prepare($pdo);
            $id = $this->accept($accounts, $catalog, offer: $offer);
            $customer = $creation->customer('tenant', $id, AsaasTestTransport::profile());
            $this->assertSame('bound', $customer['state']);
            $receipt = $creation->charge('tenant', $id, $customer['creationId'], 'PIX', '2026-10-15');
            $this->assertSame($offer === 'pack-br' ? 'payment' : 'subscription', $receipt['kind']);
            $this->assertSame('bound', $receipt['state']); $this->assertFalse($receipt['dispatchAllowed']);
            $this->assertSame('pending', $accounts->readObligation('tenant', $id)['state']);
            $this->assertSame(2, $this->posts($transport));
            $cipher = $pdo->query("SELECT request_ciphertext FROM credit_billing_creation WHERE kind='customer'")->fetchColumn();
            $this->assertStringNotContainsString('Sandbox Payer', $cipher); $this->assertStringNotContainsString('52998224725', $cipher);
            $this->assertStringNotContainsString('test-only-key', json_encode($this->snapshot($pdo), JSON_THROW_ON_ERROR));
            $accounts->changeCountry('tenant', 'country-us', 1, 'US', 'admin');
            $connections->enabled = false; $connections->available = false; $this->now = '2026-12-01 00:00:00';
            $before = $this->snapshot($pdo);
            $this->assertSame($customer, $creation->customer('tenant', $id, AsaasTestTransport::profile()));
            $this->assertSame($receipt, $creation->charge('tenant', $id, $customer['creationId'], 'PIX', '2026-10-15'));
            $this->assertSame($receipt, $creation->reconcile('tenant', $receipt['creationId']));
            $this->assertSame($before, $this->snapshot($pdo)); $this->assertSame(2, count($transport->calls));
            foreach (['tenant_credit_balance', 'credit_grant', 'credit_transaction', 'credit_reservation'] as $table) $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testConfiguredPublicProviderCanAcceptAndDisableSalesWithoutBreakingOriginalRecovery(string $dialect): void
    {
        $old = getenv('CREDIT_BILLING_ACTIVATION_TEST_KEY');
        putenv('CREDIT_BILLING_ACTIVATION_TEST_KEY=fixture-only-key');
        try {
            $this->database($dialect, function (PDO $pdo): void {
                [, $catalog, , , , $transport] = $this->prepare($pdo);
                $selection = ['integrationId' => 'configured-asaas', 'environment' => 'sandbox', 'salesEnabled' => true, 'operations' => ['purchase', 'subscription']];
                $connection = ['environment' => 'sandbox', 'apiKeyEnv' => 'CREDIT_BILLING_ACTIVATION_TEST_KEY', 'creationEnabled' => true];
                $config = $this->createStub(Config::class);
                $config->method('get')->willReturnCallback(static function ($key) use (&$selection, &$connection) {
                    return match ($key) {
                        'featureCreditsBillingProviders' => ['asaas' => $selection],
                        'featureCreditsAsaasConnections' => ['configured-asaas' => $connection, 'replacement' => $connection],
                        default => 'configured-checkout-crypt-key',
                    };
                });
                $manager = $this->createStub(EntityManager::class); $manager->method('getPDO')->willReturn($pdo);
                $clock = $this->createStub(Clock::class); $clock->method('now')->willReturnCallback(fn () => $this->now);
                $connections = new Connections($config);
                $accounts = new Accounts($manager, $clock, $catalog, new Providers($config, $connections));
                $creation = new Creation(new CreationStore($accounts, new Crypt($config), $connections), new Adapter($transport));
                $controller = $this->checkoutController($accounts, $creation);
                $this->checkout($controller, 'postActionCountry', ['tenantId' => 'tenant', 'requestKey' => 'country', 'revision' => 0, 'billingCountry' => 'BR']);
                $card = $catalog->current();
                $accept = ['tenantId' => 'tenant', 'requestKey' => 'configured-checkout', 'revision' => 1,
                    'catalogId' => $card->id, 'offerId' => 'monthly-br', 'termsId' => $card->offers['monthly-br']->termsId];
                $accepted = $this->checkout($controller, 'postActionAccept', $accept);
                $this->assertSame('configured-asaas', $accepted['integrationId']);
                $payer = ['tenantId' => 'tenant', 'obligationId' => $accepted['obligationId'], 'payer' => AsaasTestTransport::profile()];
                $customer = $this->checkout($controller, 'postActionCustomer', $payer);
                $charge = ['tenantId' => 'tenant', 'obligationId' => $accepted['obligationId'], 'customerCreationId' => $customer['creationId'], 'billingType' => 'BOLETO', 'dueDate' => '2026-10-15'];
                $transport->mode = 'lost';
                $unknown = $this->checkout($controller, 'postActionCharge', $charge);
                $this->assertSame('unknown', $unknown['state']);
                $selection['salesEnabled'] = false; $connection['creationEnabled'] = false;
                foreach ($accounts->context('tenant')['offers'] as $offer) $this->assertSame('billing_provider_unavailable', $offer['eligibility']['blockedReason']);
                $this->assertSame($accepted, $this->checkout($controller, 'postActionAccept', $accept));
                try { $this->checkout($controller, 'postActionAccept', array_replace($accept, ['requestKey' => 'new-sale'])); $this->fail('Disabled sales accepted.'); }
                catch (Conflict) {}
                $this->assertSame($unknown, $this->checkout($controller, 'postActionCharge', $charge));
                $transport->mode = 'success';
                $receipt = $this->checkout($controller, 'postActionRecoverCreation', ['tenantId' => 'tenant', 'creationId' => $unknown['creationId']]);
                $this->assertSame('bound', $receipt['state']);
                $this->assertSame(2, $this->posts($transport));
                $selection['salesEnabled'] = true; $connection['creationEnabled'] = true; $selection['integrationId'] = 'replacement';
                foreach ($accounts->context('tenant')['offers'] as $offer) $this->assertSame('billing_integration_pinned', $offer['eligibility']['blockedReason']);
                $this->assertSame($receipt, $this->checkout($controller, 'postActionCharge', $charge));
                $this->assertSame(2, $this->posts($transport));
                $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM credit_grant')->fetchColumn());
            });
        } finally {
            putenv($old === false ? 'CREDIT_BILLING_ACTIVATION_TEST_KEY' : 'CREDIT_BILLING_ACTIVATION_TEST_KEY=' . $old);
        }
    }

    #[DataProvider('dialects')]
    public function testLostReplyRecoversByReferenceWithSalesDisabledAndNoDuplicatePost(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$accounts, $catalog, $store, $creation, $connections, $transport] = $this->prepare($pdo);
            $id = $this->accept($accounts, $catalog); $transport->mode = 'lost';
            $unknown = $creation->customer('tenant', $id, AsaasTestTransport::profile());
            $this->assertSame('unknown', $unknown['state']); $this->assertSame('unknown', $accounts->readObligation('tenant', $id)['state']);
            $this->assertSame($unknown, $creation->customer('tenant', $id, AsaasTestTransport::profile()));
            try { $accounts->cancel('tenant', 'cancel', $id, 'admin'); $this->fail('Unknown creation locally cancelled.'); } catch (Conflict) {}
            try { $accounts->changeOverride('tenant', 'switch', 1, 'stripe', 'admin'); $this->fail('Unknown creation allowed provider switch.'); } catch (Conflict) {}
            $connections->enabled = false;
            foreach (['empty', 'duplicate'] as $mode) {
                $transport->mode = $mode;
                $this->assertSame('unknown', $creation->reconcile('tenant', $unknown['creationId'])['state']);
                $this->assertSame(1, $this->posts($transport));
            }
            $transport->mode = 'success';
            $bound = $creation->reconcile('tenant', $unknown['creationId']);
            $this->assertSame('bound', $bound['state']); $this->assertSame('creating', $accounts->readObligation('tenant', $id)['state']);
            $this->assertSame($bound, $store->record('tenant', $bound['creationId'], ['state' => 'unknown']));
            $this->assertSame(1, $this->posts($transport));
        });
    }

    #[DataProvider('dialects')]
    public function testCommittedClaimAndProcessFailureCannotRedispatchCreation(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$accounts, $catalog, $store, $creation, , $transport] = $this->prepare($pdo);
            $id = $this->accept($accounts, $catalog);
            $claim = $store->customer('tenant', $id, AsaasTestTransport::profile());
            $this->assertTrue($claim['dispatchAllowed']); $this->assertSame([], $transport->calls);
            $this->assertSame('creating', $creation->customer('tenant', $id, AsaasTestTransport::profile())['state']);
            $this->assertSame(0, $this->posts($transport));
            $this->assertSame('unknown', $creation->reconcile('tenant', $claim['receipt']['creationId'])['state']);
            $this->assertSame(0, $this->posts($transport));
            $transport->objects[$claim['command']->body['externalReference']] = $claim['command']->body + ['id' => 'cus_recovered', 'deleted' => false];
            $customer = $creation->reconcile('tenant', $claim['receipt']['creationId']);
            $transport->mode = 'throw';
            try { $creation->charge('tenant', $id, $customer['creationId'], 'PIX', '2026-10-15'); $this->fail('Simulated process failure missed.'); }
            catch (\RuntimeException) {}
            $replay = $creation->charge('tenant', $id, $customer['creationId'], 'PIX', '2026-10-15');
            $this->assertSame('creating', $replay['state']); $this->assertSame(1, $this->posts($transport));
            $transport->mode = 'success';
            $this->assertSame('bound', $creation->reconcile('tenant', $replay['creationId'])['state']);
            $this->assertSame(1, $this->posts($transport));
        });
    }

    #[DataProvider('dialects')]
    public function testExistingCustomerIsReusedButChangedRequestsAndForeignCustomersAreRejected(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$accounts, $catalog, $store, $creation, , $transport] = $this->prepare($pdo);
            $id = $this->accept($accounts, $catalog); $customer = $creation->customer('tenant', $id, AsaasTestTransport::profile());
            $second = $this->accept($accounts, $catalog, key: 'second');
            $this->assertSame($customer, $creation->customer('tenant', $second, AsaasTestTransport::profile()));
            $this->assertSame(1, $this->posts($transport));
            $charge = $creation->charge('tenant', $second, $customer['creationId'], 'BOLETO', '2026-10-15');
            $changed = AsaasTestTransport::profile(); $changed['name'] = 'Changed payer';
            foreach ([fn () => $creation->customer('tenant', $second, $changed),
                fn () => $creation->charge('tenant', $second, $customer['creationId'], 'PIX', '2026-10-15'),
                fn () => $creation->charge('tenant', $second, $customer['creationId'], 'BOLETO', '2026-10-16')] as $call) {
                try { $call(); $this->fail('Creation input changed on replay.'); } catch (Conflict) {}
            }
            $other = $this->accept($accounts, $catalog, 'other');
            try { $creation->charge('other', $other, $customer['creationId'], 'PIX', '2026-10-15'); $this->fail('Foreign customer linked.'); } catch (NotFound) {}
            try { $creation->reconcile('other', $charge['creationId']); $this->fail('Foreign creation read.'); } catch (NotFound) {}
            $this->assertSame(2, $this->posts($transport));
        });
    }

    #[DataProvider('dialects')]
    public function testExternalIdsAreUniquePerProviderIntegrationKindAndCaseExact(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$accounts, $catalog, $store, , , , $providers] = $this->prepare($pdo);
            $first = $store->customer('tenant', $this->accept($accounts, $catalog), AsaasTestTransport::profile());
            $store->record('tenant', $first['receipt']['creationId'], ['state' => 'bound', 'externalId' => 'cus_Shared']);
            $second = $store->customer('other', $this->accept($accounts, $catalog, 'other'), AsaasTestTransport::profile());
            try { $store->record('other', $second['receipt']['creationId'], ['state' => 'bound', 'externalId' => 'cus_Shared']); $this->fail('External customer bound to two tenants.'); }
            catch (Conflict) {}
            $this->assertSame('creating', $store->recovery('other', $second['receipt']['creationId'])['receipt']['state']);
            $store->record('other', $second['receipt']['creationId'], ['state' => 'bound', 'externalId' => 'cus_shared']);
            $providers->integration = 'another-asaas';
            $third = $store->customer('third', $this->accept($accounts, $catalog, 'third'), AsaasTestTransport::profile());
            $this->assertSame('bound', $store->record('third', $third['receipt']['creationId'], ['state' => 'bound', 'externalId' => 'cus_Shared'])['state']);
        });
    }

    #[DataProvider('dialects')]
    public function testClaimAndBindingWriteFailuresRollbackWithoutRepeatingSupplierPost(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            [$accounts, $catalog, $store, $creation, , $transport] = $this->prepare($pdo); $id = $this->accept($accounts, $catalog);
            foreach (['credit_billing_creation' => '1=0', 'credit_billing_obligation' => "state='accepted'"] as $table => $condition) {
                $before = $this->snapshot($pdo); $pdo->exec("ALTER TABLE $table ADD CONSTRAINT injected_failure CHECK ($condition)");
                try { $creation->customer('tenant', $id, AsaasTestTransport::profile()); $this->fail('Partial creation claim committed.'); }
                catch (PDOException) { $this->assertSame($before, $this->snapshot($pdo)); $this->assertSame([], $transport->calls); }
                finally { $pdo->exec("ALTER TABLE $table DROP " . self::dropCheckKeyword($pdo) . ' injected_failure'); }
            }
            $pdo->exec("ALTER TABLE credit_billing_creation ADD CONSTRAINT injected_failure CHECK (state <> 'bound')");
            try { $creation->customer('tenant', $id, AsaasTestTransport::profile()); $this->fail('Binding failure swallowed.'); }
            catch (Conflict | PDOException) { $this->assertSame(1, $this->posts($transport)); }
            finally { $pdo->exec('ALTER TABLE credit_billing_creation DROP ' . self::dropCheckKeyword($pdo) . ' injected_failure'); }
            $saved = $creation->customer('tenant', $id, AsaasTestTransport::profile());
            $this->assertSame('creating', $saved['state']);
            $this->assertSame('bound', $creation->reconcile('tenant', $saved['creationId'])['state']);
            $this->assertSame(1, $this->posts($transport));
        });
    }

    #[DataProvider('dialects')]
    public function testCorruptionAndEnvironmentChangesCannotRedirectRecovery(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$accounts, $catalog, $store, $creation, $connections, $transport] = $this->prepare($pdo); $id = $this->accept($accounts, $catalog);
            $claim = $store->customer('tenant', $id, AsaasTestTransport::profile());
            $connections->environment = 'production';
            try { $creation->reconcile('tenant', $claim['receipt']['creationId']); $this->fail('Recovery changed environment.'); } catch (Conflict) {}
            $connections->environment = 'sandbox';
            $row = $pdo->query('SELECT * FROM credit_billing_creation')->fetch(PDO::FETCH_ASSOC);
            foreach (['environment' => 'production', 'integration_id' => 'other', 'request_hash' => str_repeat('a', 64), 'binding_hash' => str_repeat('b', 64)] as $field => $value) {
                $pdo->prepare("UPDATE credit_billing_creation SET $field=? WHERE id=?")->execute([$value, $row['id']]);
                try { $creation->reconcile('tenant', $row['id']); $this->fail('Corrupt creation dispatched lookup.'); } catch (Conflict) {}
                $pdo->prepare("UPDATE credit_billing_creation SET $field=? WHERE id=?")->execute([$row[$field], $row['id']]);
            }
            $this->assertSame([], $transport->calls);
        });
    }

    public static function races(): iterable
    {
        foreach (self::dialects() as $name => [$dialect]) foreach (['duplicate', 'charge', 'cancel'] as $race) yield "$name $race" => [$dialect, $race];
    }

    #[DataProvider('races')]
    public function testIndependentProcessesCannotDoublePostOrBypassCancellation(string $dialect, string $race): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect, $race): void {
            [$accounts, $catalog, , $creation] = $this->prepare($pdo); $id = $this->accept($accounts, $catalog);
            $customer = $race === 'charge' ? $creation->customer('tenant', $id, AsaasTestTransport::profile())['creationId'] : '';
            $actions = $race === 'charge' ? ['charge', 'charge'] : ['create', $race === 'duplicate' ? 'create' : 'cancel'];
            $workers = []; $pdo->beginTransaction(); $pdo->query("SELECT id FROM tenant WHERE id='tenant' FOR UPDATE");
            try {
                foreach ($actions as $action) {
                    $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/billing-creation-worker.php', $dialect, $id, $action, $this->now, $customer],
                        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                    $this->assertIsResource($process); $workers[] = [$process, $pipes]; stream_set_timeout($pipes[1], 15);
                    $this->assertSame("ready\n", fgets($pipes[1]));
                }
                foreach ($workers as [$process, $pipes]) {
                    $read = [$pipes[1]]; $write = $except = [];
                    $this->assertSame(0, stream_select($read, $write, $except, 0, 150000)); $this->assertTrue(proc_get_status($process)['running']);
                }
                $pdo->commit(); $results = [];
                foreach ($workers as [$process, $pipes]) { $results[] = json_decode(fgets($pipes[1]), true, 32, JSON_THROW_ON_ERROR); $this->assertSame('', stream_get_contents($pipes[2])); }
                $posts = array_sum(array_column($results, 'posts'));
                if ($race !== 'cancel') {
                    $this->assertSame(1, $posts); $this->assertCount(2, array_column($results, 'result'));
                    $this->assertSame($results[0]['result']['creationId'], $results[1]['result']['creationId']);
                } else {
                    $this->assertCount(1, array_column($results, 'result')); $this->assertSame([Conflict::class], array_column($results, 'error'));
                    $this->assertSame($posts === 1 ? 'creating' : 'cancelled', $accounts->readObligation('tenant', $id)['state']);
                }
                $this->assertSame($posts + ($race === 'charge' ? 1 : 0), (int) $pdo->query('SELECT COUNT(*) FROM credit_billing_creation')->fetchColumn());
            } finally {
                if ($pdo->inTransaction()) $pdo->rollBack();
                foreach ($workers as [$process, $pipes]) { if (proc_get_status($process)['running']) proc_terminate($process); foreach ($pipes as $pipe) fclose($pipe); proc_close($process); }
            }
        });
    }
}
