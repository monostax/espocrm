<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Doctrine\DBAL\Platforms\{MySQL80Platform, PostgreSQLPlatform};
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Utils\{Config, Crypt};
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Billing\{Accounts, Catalog, Checkout, Creation, CreationStore, Subscriptions};
use Espo\Modules\FeatureCredits\Billing\Asaas\{Adapter, Connection, Response};
use Espo\Modules\FeatureSelfService\Tools\Plans\{PlanCheckout, PlanOffers, PlanSubscription};
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FeatureSelfService's plan checkout on the real billing engine (both
 * dialects, fake Asaas): attendants × price + monthly credits as one card
 * subscription, the credits each attendant brings, the hosted invoice, safe
 * retries, and replacing an unpaid checkout.
 */
final class SelfServicePlanCheckoutDatabaseTest extends TestCase
{
    use SchemaFixture;

    private string $now = '2026-10-12 12:00:00';

    public static function dialects(): iterable
    {
        yield 'MySQL' => ['Mysql']; yield 'PostgreSQL' => ['Postgresql'];
    }

    #[DataProvider('dialects')]
    public function testAPlanCheckoutCreatesOneSubscriptionForTheTotal(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$checkout, $transport, $engine] = $this->prepare($pdo);

            $result = $checkout->start('tenant', 'owner', 3, '2000', self::payer());

            $this->assertSame('pending', $result['state']);
            $this->assertStringStartsWith('https://sandbox.asaas.com/i/', $result['paymentUrl']);
            $subscriptions = $transport->posted('/subscriptions');
            $this->assertCount(1, $subscriptions);
            $this->assertSame('1745.00', $subscriptions[0]['value'], '3 × 249 + 998.');
            $this->assertSame('MONTHLY', $subscriptions[0]['cycle']);
            $this->assertSame('CREDIT_CARD', $subscriptions[0]['billingType'], 'A card renews by itself.');
            $this->assertSame('Plano Monostax: 3 atendentes, 2.750 créditos de IA por mês', $subscriptions[0]['description']);
            $this->assertArrayNotHasKey('callback', $subscriptions[0], 'No return URL unless one is given.');
            $terms = $engine->read('tenant', $result['obligationId'])['acceptance']['terms'];
            $this->assertSame(3, $terms['seats']['quantity']);
            $this->assertSame('2750.0000', $terms['credits'], '2.000 monthly credits + 3 × 250 included.');

            $again = $checkout->start('tenant', 'owner', 3, '2000', self::payer());
            $this->assertSame($result['obligationId'], $again['obligationId']);
            $this->assertSame($result['paymentUrl'], $again['paymentUrl']);
            $this->assertCount(1, $transport->posted('/subscriptions'), 'A retry continues the same checkout.');
            $this->assertCount(1, $transport->posted('/customers'));
        });
    }

    #[DataProvider('dialects')]
    public function testThePaymentPageSendsThePayerBack(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$checkout, $transport] = $this->prepare($pdo);

            $checkout->start('tenant', 'owner', 1, '0', self::payer(), 'https://chat.example.test/app/accounts/61/settings/plan?paid=1');

            $subscription = $transport->posted('/subscriptions')[0];
            $this->assertSame(['successUrl' => 'https://chat.example.test/app/accounts/61/settings/plan?paid=1', 'autoRedirect' => true],
                $subscription['callback']);
            $this->assertSame('Plano Monostax: 1 atendente, 250 créditos de IA por mês', $subscription['description']);
            $this->assertSame('249.00', $subscription['value']);
        });
    }

    #[DataProvider('dialects')]
    public function testAPaidPlanIsReadAndCancelledKeepingItsPaidPeriod(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$checkout, $transport, , , $manager, $subscriptions] = $this->prepare($pdo);
            $plan = new PlanSubscription($manager, $subscriptions);
            $this->assertNull($plan->read('tenant')['subscription']);

            $started = $checkout->start('tenant', 'owner', 3, '2000', self::payer());
            $pdo->exec("UPDATE credit_billing_obligation SET state = 'active' WHERE id = '{$started['obligationId']}'");
            $pdo->prepare('INSERT INTO credit_billing_period (id, tenant_id, obligation_id, period_key, starts_at, ends_at, terms_id,
                binding_hash, created_at, paid_payment_id, deleted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute(['period00000000001', 'tenant', $started['obligationId'], '2026-11-13', '2026-10-13 03:00:00',
                    '2026-11-13 03:00:00', str_repeat('c', 64), str_repeat('e', 64), $this->now, 'pay_paid', 0]);

            $this->assertSame(['state' => 'active', 'seats' => 3, 'seatPrice' => '249.00', 'monthlyCredits' => 2000,
                'includedCredits' => 750, 'totalCredits' => 2750, 'price' => '1745.00', 'currency' => 'BRL',
                'paidThrough' => '2026-11-13 03:00:00'], $plan->read('tenant')['subscription']);
            $this->assertFalse($plan->isCancelled('tenant'));

            $read = $plan->cancel('tenant', 'owner');
            $this->assertSame('cancelled', $read['subscription']['state']);
            $this->assertSame('2026-11-13 03:00:00', $read['subscription']['paidThrough'], 'Access runs to the end of the paid period.');
            $this->assertTrue($plan->isCancelled('tenant'));
            $this->assertCount(1, array_filter($transport->calls, static fn ($call) => $call[2] === 'DELETE'));

            $this->expectException(Conflict::class);
            $this->expectExceptionMessage('NO_ACTIVE_SUBSCRIPTION');
            $plan->cancel('tenant', 'owner');
        });
    }

    #[DataProvider('dialects')]
    public function testChangingTheChoiceReplacesTheUnpaidSubscription(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$checkout, $transport, $engine] = $this->prepare($pdo);
            $first = $checkout->start('tenant', 'owner', 3, '2000', self::payer());

            $second = $checkout->start('tenant', 'owner', 5, '0', self::payer());

            $this->assertNotSame($first['obligationId'], $second['obligationId']);
            $this->assertSame('cancelled', $engine->read('tenant', $first['obligationId'])['state']);
            $this->assertCount(1, array_filter($transport->calls, static fn ($call) => $call[2] === 'DELETE'));
            $subscriptions = $transport->posted('/subscriptions');
            $this->assertSame('1245.00', $subscriptions[1]['value'], '5 × 249, no monthly credits on top.');
            $this->assertCount(1, $transport->posted('/customers'), 'The payer is reused.');
        });
    }

    #[DataProvider('dialects')]
    public function testUnknownChoicesAndAPaidPlanAreRefused(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$checkout, , , $pdoCheck] = $this->prepare($pdo);

            foreach ([[0, '2000'], [3, '500'], [3, '30000'], [1001, '0']] as [$seats, $credits]) {
                try {
                    $checkout->start('tenant', 'owner', $seats, $credits, self::payer());
                    $this->fail("{$seats}/{$credits} started a checkout.");
                } catch (\InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }

            $paid = $checkout->start('tenant', 'owner', 2, '2000', self::payer());
            $pdoCheck->exec("UPDATE credit_billing_obligation SET state = 'active' WHERE id = '{$paid['obligationId']}'");
            $this->expectException(Conflict::class);
            $this->expectExceptionMessage('ALREADY_SUBSCRIBED');
            $checkout->start('tenant', 'owner', 2, '0', self::payer());
        });
    }

    private static function payer(): array
    {
        return ['name' => 'Clínica Sol Ltda', 'cpfCnpj' => '52998224725', 'email' => 'financeiro@example.test'];
    }

    private function prepare(PDO $pdo): array
    {
        $manager = $this->createStub(EntityManager::class);
        $manager->method('getPDO')->willReturn($pdo);
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturnCallback(fn () => $this->now);
        $config = $this->createStub(Config::class);
        $config->method('get')->willReturn('self-service-checkout-test-key');
        $connections = new AsaasTestConnections();
        $transport = new PlanTestTransport();

        $catalog = new Catalog($manager, $clock);
        $input = BillingFixtures::definition();
        // The one plan, as in self-service-2026-11-v2: R$249 per attendant with 250 credits each, plus 1.000-credit steps.
        foreach (['0' => '0', '1000' => '499', '2000' => '998'] as $credits => $price) {
            $credits = (string) $credits;
            $input->products[] = (object) ['productId' => "plan-credits-{$credits}", 'productVersion' => 'v1',
                'name' => "Plan, {$credits} monthly credits", 'operation' => 'subscription', 'credits' => $credits];
            $input->offers[] = (object) ['offerId' => "plan-credits-{$credits}-br", 'version' => 'v1',
                'productId' => "plan-credits-{$credits}", 'productVersion' => 'v1', 'countries' => ['BR'],
                'currency' => 'BRL', 'price' => $price, 'interval' => 'month',
                'seats' => (object) ['plan' => 'monostax', 'unitPrice' => '249', 'min' => 1, 'max' => 1000, 'includedCredits' => '250']];
        }
        $catalog->publish($input, 'admin');
        $this->now = '2026-10-13 00:00:00';

        $accounts = new Accounts($manager, $clock, $catalog, new BillingTestProviders());
        $store = new CreationStore($accounts, new Crypt($config), $connections);
        $engine = new Checkout($accounts, $store, $connections, $transport);
        $subscriptions = new Subscriptions($accounts, $store, $connections, $transport);
        $checkout = new PlanCheckout($accounts, $engine, new Creation($store, new Adapter($transport)),
            $subscriptions, new PlanOffers($catalog));

        return [$checkout, $transport, $engine, $pdo, $manager, $subscriptions];
    }

    private function database(string $dialect, callable $test): void
    {
        $dsn = getenv('FEATURE_CREDITS_' . strtoupper($dialect) . '_DSN');
        if (!$dsn) $this->markTestSkipped('Requires an empty feature_credits_schema_test database.');
        $pdo = new PDO($dsn, 'credits_test', 'credits_test', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $schema = $this->schema($dialect); $platform = $dialect === 'Mysql' ? new MySQL80Platform() : new PostgreSQLPlatform();
        try {
            foreach ($schema->toSql($platform) as $sql) $pdo->exec($sql);
            $pdo->exec('CREATE TABLE tenant (id VARCHAR(24) PRIMARY KEY, deleted BOOLEAN NOT NULL DEFAULT FALSE)');
            $pdo->exec("INSERT INTO tenant (id) VALUES ('tenant'), ('other')");
            $test($pdo);
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->exec('DROP TABLE IF EXISTS tenant');
            foreach (array_reverse($schema->getTables()) as $table) $pdo->exec('DROP TABLE IF EXISTS ' . $platform->quoteIdentifier($table->getName()));
        }
    }
}

/** The fake Asaas, plus the first invoice Asaas generates for each subscription. */
final class PlanTestTransport extends \Espo\Modules\FeatureCredits\Billing\Asaas\Transport
{
    public array $calls = [];
    private AsaasTestTransport $inner;

    public function __construct()
    {
        $this->inner = new AsaasTestTransport();
    }

    /** Posted bodies, with `value` kept as the exact decimal text that was sent. */
    public function posted(string $path): array
    {
        return array_values(array_map(static function ($call) {
            $body = json_decode($call[4], true);
            if (preg_match('/"value":([0-9]+\.[0-9]{2})/', $call[4], $match)) $body['value'] = $match[1];
            return $body;
        }, array_filter($this->calls, static fn ($call) => $call[2] === 'POST' && $call[3] === $path)));
    }

    public function send(Connection $connection, string $method, string $path, ?string $json = null, array $query = []): Response
    {
        $this->calls[] = [$connection->id, $connection->environment, $method, $path, $json, $query];

        if ($method === 'GET' && (($path === '/payments' && isset($query['subscription'])) || str_starts_with($path, '/payments/pay_first_'))) {
            $subscriptionId = $query['subscription'] ?? substr($path, strlen('/payments/pay_first_'));
            foreach ($this->inner->objects as $object) {
                if ($object['id'] !== $subscriptionId) continue;
                $payment = ['object' => 'payment', 'id' => 'pay_first_' . $subscriptionId, 'customer' => $object['customer'],
                    'subscription' => $subscriptionId, 'value' => $object['value'], 'originalValue' => $object['value'],
                    'billingType' => $object['billingType'] ?? 'UNDEFINED', 'dueDate' => $object['nextDueDate'], 'originalDueDate' => $object['nextDueDate'],
                    'deleted' => false, 'status' => 'PENDING', 'invoiceUrl' => 'https://sandbox.asaas.com/i/first-' . $subscriptionId];

                return new Response(200, $path === '/payments'
                    ? ['object' => 'list', 'totalCount' => '1', 'offset' => '0', 'hasMore' => false, 'data' => [$payment]]
                    : $payment);
            }

            return new Response(404, null);
        }

        return $this->inner->send($connection, $method, $path, $json, $query);
    }
}
