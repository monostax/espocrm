<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Doctrine\DBAL\Platforms\{MySQL80Platform, PostgreSQLPlatform};
use Espo\Core\Utils\{Config, Log};
use Espo\Modules\FeatureCredits\Accounting\{Clock, ConsistencyAudit, Funding, GrantInput, WalletLock};
use Espo\Modules\FeatureCredits\Billing\Asaas\{Connection, Response, Transport};
use Espo\Modules\FeatureCredits\Billing\ExternalSubscriptions;
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SchemaTest.php';
require_once __DIR__ . '/AsaasFixtures.php';

final class ExternalSubscriptionsDatabaseTest extends TestCase
{
    use SchemaFixture;

    private const LINK = [
        'tenantId' => 'tenant', 'integration' => 'asaas-br', 'subscription' => 'sub_ifv', 'customer' => 'cus_ifv',
        'firstDueDate' => '2026-11-06', 'value' => '2023.00', 'credits' => '4200',
    ];

    private string $now = '2026-11-13 01:00:00';
    private array $links = ['ifv-subscription' => self::LINK];
    /** @var list<array<string, mixed>> */
    private array $payments = [];
    /** @var list<string> */
    private array $warnings = [];

    public static function dialects(): iterable { yield 'MySQL' => ['Mysql']; yield 'PostgreSQL' => ['Postgresql']; }

    private function database(string $dialect, callable $test): void
    {
        $dsn = getenv('FEATURE_CREDITS_' . strtoupper($dialect) . '_DSN');
        if (!$dsn) $this->markTestSkipped('Requires an empty feature_credits_schema_test database.');
        $pdo = new PDO($dsn, 'credits_test', 'credits_test', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->assertSame('feature_credits_schema_test', $pdo->query($dialect === 'Mysql' ? 'SELECT DATABASE()' : 'SELECT current_database()')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query($dialect === 'Mysql' ? 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()' :
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public'")->fetchColumn(), 'Refusing a nonempty database.');
        $schema = $this->schema($dialect); $platform = $dialect === 'Mysql' ? new MySQL80Platform() : new PostgreSQLPlatform();
        try {
            foreach ($schema->toSql($platform) as $sql) $pdo->exec($sql);
            $pdo->exec('CREATE TABLE tenant (id VARCHAR(24) PRIMARY KEY, deleted BOOLEAN NOT NULL DEFAULT FALSE)');
            $pdo->exec("INSERT INTO tenant (id) VALUES ('tenant')");
            $test($pdo, ...$this->service($pdo));
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack(); $pdo->exec('DROP TABLE IF EXISTS tenant');
            foreach (array_reverse($schema->getTables()) as $table) $pdo->exec('DROP TABLE IF EXISTS ' . $platform->quoteIdentifier($table->getName()));
        }
    }

    /** @return array{ExternalSubscriptions, Funding, EntityManager, Clock} */
    private function service(PDO $pdo): array
    {
        $manager = new class ($pdo) extends EntityManager {
            public function __construct(private PDO $connection) {}
            public function getPDO(): PDO { return $this->connection; }
        };
        $clock = new class ($this->now) extends Clock {
            private string $time;
            public function __construct(string &$now) { $this->time =& $now; }
            public function now(): string { return $this->time; }
        };
        $config = new class ($this->links) extends Config {
            private array $value;
            public function __construct(array &$links) { $this->value =& $links; }
            public function get($name, $default = null): mixed { return $name === 'featureCreditsExternalSubscriptions' ? $this->value : $default; }
        };
        $transport = new class ($this->payments, $pdo) extends Transport {
            private array $rows;
            public function __construct(array &$payments, private PDO $pdo) { $this->rows =& $payments; }
            public function send(Connection $connection, string $method, string $path, ?string $json = null, array $query = []): Response
            {
                if ($this->pdo->inTransaction()) throw new \RuntimeException('Provider I/O under lock.');
                if ($method !== 'GET' || $path !== '/payments') throw new \RuntimeException("Unexpected {$method} {$path}.");
                $rows = array_values(array_filter($this->rows, static fn ($p) => ($p['subscription'] ?? null) === $query['subscription']));
                return new Response(200, ['object' => 'list', 'hasMore' => false, 'data' => array_slice($rows, $query['offset'], $query['limit'])]);
            }
        };
        $log = $this->createStub(Log::class);
        $log->method('warning')->willReturnCallback(function ($message) { $this->warnings[] = (string) $message; });
        $funding = new Funding(new WalletLock($manager, $clock));
        return [new ExternalSubscriptions($config, $manager, new AsaasTestConnections(), $transport, $funding, $clock, $log), $funding, $manager, $clock];
    }

    private static function payment(string $id, string $due, string $status = 'RECEIVED', array $extra = []): array
    {
        return $extra + ['object' => 'payment', 'id' => $id, 'subscription' => 'sub_ifv', 'customer' => 'cus_ifv', 'value' => '2023.0',
            'status' => $status, 'dueDate' => $due, 'originalDueDate' => $due, 'deleted' => false, 'refunds' => null];
    }

    private static function grants(PDO $pdo): array
    {
        return $pdo->query("SELECT source_type, source_key, granted_credits, expires_at FROM credit_grant ORDER BY source_key")->fetchAll(PDO::FETCH_ASSOC);
    }

    #[DataProvider('dialects')]
    public function testEachPaidStartedPeriodIsFundedOnceWithOneMonthCarryOver(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, ExternalSubscriptions $service, Funding $funding, EntityManager $manager, Clock $clock): void {
            $this->payments = [self::payment('pay_nov', '2026-11-06'), self::payment('pay_dec', '2026-12-06', 'PENDING')];
            $this->assertSame(['granted' => 1, 'failed' => 0], $service->fundAll());
            $this->assertSame(['granted' => 0, 'failed' => 0], $service->fundAll());
            $this->assertEquals([['source_type' => 'subscription', 'source_key' => 'ifv-subscription:2026-11-06',
                'granted_credits' => '4200.0000', 'expires_at' => '2027-01-06 03:00:00']], self::grants($pdo));

            // Paid early by PIX: funded only once its period starts (São Paulo midnight).
            $this->payments[1]['status'] = 'RECEIVED';
            $this->now = '2026-12-06 02:59:59';
            $this->assertSame(0, $service->fund('ifv-subscription', self::LINK));
            $this->now = '2026-12-06 03:00:00';
            $this->assertSame(1, $service->fund('ifv-subscription', self::LINK));
            $this->assertSame('2027-02-06 03:00:00', self::grants($pdo)[1]['expires_at']);
            $this->assertSame('8400.0000', $pdo->query("SELECT balance FROM tenant_credit_balance WHERE tenant_id='tenant'")->fetchColumn());

            // A card payment moving CONFIRMED → RECEIVED is still one grant.
            $this->payments[] = self::payment('pay_jan', '2027-01-06', 'CONFIRMED');
            $this->now = '2027-01-06 12:00:00';
            $this->assertSame(1, $service->fund('ifv-subscription', self::LINK));
            $this->payments[2]['status'] = 'RECEIVED';
            $this->assertSame(0, $service->fund('ifv-subscription', self::LINK));
            $this->assertCount(3, self::grants($pdo));
            $this->assertTrue((new ConsistencyAudit($manager, $clock))->inspect('tenant')['consistent']);
        });
    }

    #[DataProvider('dialects')]
    public function testAnInvoiceOffThePlanIsNeverFunded(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, ExternalSubscriptions $service): void {
            $this->now = '2027-06-10 00:00:00';
            $this->payments = [
                self::payment('pay_value', '2026-11-06', 'RECEIVED', ['value' => '697.9']),
                self::payment('pay_customer', '2026-12-06', 'RECEIVED', ['customer' => 'cus_other']),
                self::payment('pay_refund', '2027-01-06', 'RECEIVED', ['refunds' => [['value' => '2023.0']]]),
                self::payment('pay_chargeback', '2027-02-06', 'RECEIVED', ['chargeback' => ['status' => 'REQUESTED']]),
                self::payment('pay_schedule', '2027-03-09'),
                self::payment('pay_refunded', '2027-04-06', 'REFUNDED'),
                self::payment('pay_deleted', '2027-05-06', 'RECEIVED', ['deleted' => true]),
                self::payment('pay_overdue', '2027-06-06', 'OVERDUE'),
            ];
            $this->assertSame(0, $service->fund('ifv-subscription', self::LINK));
            $this->assertSame([], self::grants($pdo));
            $this->assertCount(5, $this->warnings);
            foreach (['value differs', 'another customer', 'refunded or charged back', 'refunded or charged back', 'off the original monthly schedule'] as $i => $reason) {
                $this->assertStringContainsString($reason, $this->warnings[$i]);
            }
        });
    }

    #[DataProvider('dialects')]
    public function testAPeriodGrantedByHandIsLeftAlone(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, ExternalSubscriptions $service, Funding $funding): void {
            // November was granted by an operator on 2026-11-13 with its own evidence.
            $funding->grant(new GrantInput('tenant', 'subscription', 'ifv-subscription:2026-11-06', '4200', '2026-11-13 01:00:00',
                '2027-01-06 03:00:00', (object) ['source' => 'asaas-subscription-manual']));
            $this->payments = [self::payment('pay_nov', '2026-11-06')];
            $this->assertSame(['granted' => 0, 'failed' => 0], $service->fundAll());
            $this->assertCount(1, self::grants($pdo));
        });
    }

    #[DataProvider('dialects')]
    public function testABadLinkFailsAloneAndClosed(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, ExternalSubscriptions $service): void {
            $this->payments = [self::payment('pay_nov', '2026-11-06')];
            $this->links = ['Bad Key' => self::LINK, 'typo' => ['credits' => '999999'] + self::LINK + ['extra' => 'x'], 'ifv-subscription' => self::LINK];
            $this->assertSame(['granted' => 1, 'failed' => 2], $service->fundAll());
            $this->assertSame('4200.0000', self::grants($pdo)[0]['granted_credits']);
        });
    }

    public function testLinkValidation(): void
    {
        $this->assertSame(self::LINK, ExternalSubscriptions::link('ifv-subscription', self::LINK));
        foreach ([['value' => '0'], ['value' => '20,23'], ['credits' => '0'], ['subscription' => 'pay_x'], ['customer' => 'sub_x'],
            ['firstDueDate' => '2026-13-06'], ['tenantId' => 'bad id']] as $change) {
            try {
                ExternalSubscriptions::link('ifv-subscription', $change + self::LINK);
                $this->fail('Accepted ' . json_encode($change));
            } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }
}
