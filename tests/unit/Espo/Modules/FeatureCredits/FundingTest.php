<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Accounting\Funding;
use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use Espo\Modules\FeatureCredits\Accounting\ReservationInput;
use Espo\Modules\FeatureCredits\Accounting\Reservations;
use Espo\Modules\FeatureCredits\Accounting\RequestInput;
use Espo\Modules\FeatureCredits\Accounting\Requests;
use Espo\Modules\FeatureCredits\Accounting\OutcomeInput;
use Espo\Modules\FeatureCredits\Accounting\Outcomes;
use Espo\Modules\FeatureCredits\Accounting\Settlements;
use Espo\Modules\FeatureCredits\Accounting\Reconciliation;
use Espo\Modules\FeatureCredits\Accounting\ReconciliationInput;
use Espo\Modules\FeatureCredits\Accounting\ReconciliationPolicy;
use Espo\Modules\FeatureCredits\Accounting\ReconciliationDiscovery;
use Espo\Modules\FeatureCredits\Pricing\AiRate;
use Espo\Modules\FeatureCredits\Accounting\WalletLock;
use Espo\ORM\EntityManager;
use LogicException;
use OverflowException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SchemaTest.php';
require_once __DIR__ . '/ConsistencyAuditCases.php';
require_once __DIR__ . '/BalanceStatusCases.php';
require_once __DIR__ . '/TransactionHistoryCases.php';
require_once __DIR__ . '/GrantBalancesCases.php';
require_once __DIR__ . '/ReservationHistoryCases.php';
require_once __DIR__ . '/RequestHistoryCases.php';
require_once __DIR__ . '/OperationHistoryCases.php';
require_once __DIR__ . '/SourceAdmissionCases.php';
require_once __DIR__ . '/ExecutionRoutingCases.php';
require_once __DIR__ . '/DispatchStoreCases.php';
require_once __DIR__ . '/CompletionCases.php';
require_once __DIR__ . '/ConversationCallerCases.php';
require_once __DIR__ . '/ConfigurationCases.php';
require_once __DIR__ . '/NativeSearchDebtCases.php';

class FundingTest extends TestCase
{
    use SchemaFixture;
    use ConsistencyAuditCases;
    use BalanceStatusCases;
    use TransactionHistoryCases;
    use GrantBalancesCases;
    use ReservationHistoryCases;
    use RequestHistoryCases;
    use OperationHistoryCases;
    use SourceAdmissionCases;
    use ExecutionRoutingCases;
    use DispatchStoreCases;
    use CompletionCases;
    use ConversationCallerCases;
    use ConfigurationCases;
    use NativeSearchDebtCases;

    private string $now = '2026-10-10 12:00:00';

    public static function dialects(): iterable
    {
        yield 'MySQL' => ['Mysql'];
        yield 'PostgreSQL' => ['Postgresql'];
    }

    private function connection(string $dialect): PDO
    {
        $dsn = getenv('FEATURE_CREDITS_' . strtoupper($dialect) . '_DSN');
        if (!$dsn) {
            $this->markTestSkipped('Requires an empty feature_credits_schema_test database.');
        }
        return new PDO($dsn, 'credits_test', 'credits_test', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function database(string $dialect, callable $test): void
    {
        $pdo = $this->connection($dialect);
        $this->assertSame('feature_credits_schema_test', $pdo->query($dialect === 'Mysql' ?
            'SELECT DATABASE()' : 'SELECT current_database()')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query($dialect === 'Mysql' ?
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()' :
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public'")->fetchColumn(),
            'Refusing to modify a nonempty database.');
        $schema = $this->schema($dialect);
        $platform = $dialect === 'Mysql' ? new MySQL80Platform() : new PostgreSQLPlatform();
        try {
            foreach ($schema->toSql($platform) as $sql) {
                $pdo->exec($sql);
            }
            $pdo->exec('CREATE TABLE tenant (id VARCHAR(24) PRIMARY KEY, deleted BOOLEAN NOT NULL DEFAULT FALSE)');
            $pdo->exec("INSERT INTO tenant (id) VALUES ('tenant'), ('other')");
            $test($pdo);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo = $this->connection($dialect);
            $pdo->exec('DROP TABLE IF EXISTS tenant');
            foreach (array_reverse($schema->getTables()) as $table) {
                $pdo->exec('DROP TABLE IF EXISTS ' . $platform->quoteIdentifier($table->getName()));
            }
        }
    }

    private function lock(PDO $pdo): WalletLock
    {
        $manager = $this->createMock(EntityManager::class);
        $manager->method('getPDO')->willReturn($pdo);
        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturnCallback(fn () => $this->now);
        return new WalletLock($manager, $clock);
    }

    private function input(string $key = 'payment-1', string $credits = '10', string $tenant = 'tenant', ?string $expires = null): GrantInput
    {
        return new GrantInput($tenant, $expires ? 'subscription' : 'purchase', $key, $credits,
            '2026-10-01 00:00:00', $expires, (object) ['verifiedSource' => $key]);
    }

    private function assertConsistent(PDO $pdo, string $expected, string $tenant = 'tenant'): void
    {
        foreach ([
            'SELECT balance FROM tenant_credit_balance WHERE tenant_id = ?',
            'SELECT SUM(credits) FROM credit_transaction WHERE tenant_id = ?',
            'SELECT SUM(remaining_credits) FROM credit_grant WHERE tenant_id = ?',
        ] as $sql) {
            $query = $pdo->prepare($sql);
            $query->execute([$tenant]);
            $this->assertSame($expected, $query->fetchColumn(), $sql);
        }
    }

    #[DataProvider('dialects')]
    public function testFundingReplayConflictAndTenantIsolation(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $funding = new Funding($this->lock($pdo));
            $receipt = $funding->grant($this->input());
            $this->assertSame($receipt, $funding->grant($this->input(credits: '10.0000')));
            $this->assertConsistent($pdo, '10.0000');
            try {
                $funding->grant($this->input(credits: '11'));
                $this->fail('Conflicting replay allowed.');
            } catch (Conflict) {
                $this->assertConsistent($pdo, '10.0000');
            }
            $funding->grant($this->input(tenant: 'other', credits: '0.0001'));
            $this->assertConsistent($pdo, '0.0001', 'other');
            $this->assertConsistent($pdo, '10.0000');
            try {
                $funding->grant($this->input(tenant: 'missing'));
                $this->fail('Missing tenant accepted.');
            } catch (NotFound) {
                $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM tenant_credit_balance')->fetchColumn());
            }
        });
    }

    #[DataProvider('dialects')]
    public function testExpirationPreservesHoldsAndPurchasesAndReplayReceipt(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input('purchase', '7'));
            $monthly = $this->input('monthly', '10', expires: '2026-10-11 00:00:00');
            $receipt = $funding->grant($monthly);
            // Seed an authorized hold projection; request/allocation services are not implemented here.
            $pdo->prepare('UPDATE credit_grant SET reserved_credits = ? WHERE id = ?')->execute(['3.0000', $receipt['grantId']]);
            $pdo->exec("UPDATE tenant_credit_balance SET reserved_credits = 3.0000 WHERE tenant_id = 'tenant'");
            $this->now = '2026-10-11 00:00:00';
            $this->assertSame(['balance' => '10.0000', 'reservedCredits' => '3.0000'], $funding->expire('tenant'));
            $this->assertConsistent($pdo, '10.0000');
            $this->assertSame($receipt, $funding->grant($monthly));
            $funding->expire('tenant');
            $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type = 'expiration'")->fetchColumn());
            $this->assertSame('-7.0000', $pdo->query("SELECT credits FROM credit_transaction WHERE type = 'expiration'")->fetchColumn());
            $this->assertSame('3.0000', $pdo->query("SELECT remaining_credits FROM credit_grant WHERE source_key = 'monthly'")->fetchColumn());
            $this->assertSame('7.0000', $pdo->query("SELECT remaining_credits FROM credit_grant WHERE source_key = 'purchase'")->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testDelayedGrantExpiresAtomicallyAndRenewalSweepsOldFunds(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input('late-month', '9', expires: '2026-10-02 00:00:00'));
            $this->assertConsistent($pdo, '0.0000');
            $funding->grant($this->input('old', '4', expires: '2026-10-11 00:00:00'));
            $this->now = '2026-10-11 00:00:00';
            $funding->grant($this->input('new', '6', expires: '2026-11-11 00:00:00'));
            $this->assertConsistent($pdo, '6.0000');
            $this->assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type = 'expiration'")->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testFailuresRollBackWalletGrantAndLedger(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $funding = new Funding($this->lock($pdo));
            // Database faults at successive write boundaries, after earlier writes have succeeded.
            foreach (['credit_transaction', 'credit_grant', 'tenant_credit_balance'] as $table) {
                $condition = $table === 'tenant_credit_balance' ? 'balance = 0' : '1 = 0';
                $pdo->exec("ALTER TABLE $table ADD CONSTRAINT injected_failure CHECK ($condition)");
                try {
                    $funding->grant($this->input());
                    $this->fail('Injected failure did not abort.');
                } catch (PDOException) {
                    foreach (['credit_transaction', 'credit_grant', 'tenant_credit_balance'] as $target) {
                        $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM $target")->fetchColumn());
                    }
                    $this->assertFalse($pdo->inTransaction());
                } finally {
                    $pdo->exec("ALTER TABLE $table DROP " . ($dialect === 'Mysql' ? 'CHECK' : 'CONSTRAINT') . ' injected_failure');
                }
            }
            $funding->grant($this->input(credits: '9999999999.9999'));
            try {
                $funding->grant($this->input('overflow', '0.0001'));
                $this->fail('Overflow accepted.');
            } catch (OverflowException) {
                $this->assertConsistent($pdo, '9999999999.9999');
                $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM credit_grant')->fetchColumn());
            }
            $pdo->beginTransaction();
            try {
                $funding->expire('tenant');
                $this->fail('Nested transaction accepted.');
            } catch (LogicException) {
                $this->assertTrue($pdo->inTransaction());
            } finally {
                $pdo->rollBack();
            }
        });
    }

    #[DataProvider('dialects')]
    public function testExpirationFailureRollsBackPostingAndGrantProjection(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input('monthly', '10', expires: '2026-10-11 00:00:00'));
            $this->now = '2026-10-11 00:00:00';
            $pdo->exec('ALTER TABLE tenant_credit_balance ADD CONSTRAINT injected_failure CHECK (balance > 0)');
            try {
                $funding->expire('tenant');
                $this->fail('Expiration fault did not abort.');
            } catch (PDOException) {
                $this->assertConsistent($pdo, '10.0000');
                $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type = 'expiration'")->fetchColumn());
            } finally {
                $pdo->exec('ALTER TABLE tenant_credit_balance DROP ' .
                    ($dialect === 'Mysql' ? 'CHECK' : 'CONSTRAINT') . ' injected_failure');
            }
            $funding->expire('tenant');
            $this->assertConsistent($pdo, '0.0000');
        });
    }

    private function reservations(PDO $pdo): Reservations
    {
        $pdo->exec("INSERT INTO tenant_credit_billing_rate
            (id, tenant_id, version, effective_from, currency, credit_unit_price, monthly_credits, created_at)
            VALUES ('rate', 'tenant', 'v1', '2026-10-01 00:00:00', 'USD', '1.00000000', '0.0000', '2026-10-01 00:00:00')");
        $lock = $this->lock($pdo);
        return new Reservations($lock, new Funding($lock));
    }

    private function admission(string $key = 'operation', string $credits = '2', string $type = 'ai'): ReservationInput
    {
        return new ReservationInput('tenant', $type, $key, 'execution', 'rate', $credits);
    }

    private function assertHeld(PDO $pdo, string $expected): void
    {
        foreach ([
            "SELECT reserved_credits FROM tenant_credit_balance WHERE tenant_id = 'tenant'",
            "SELECT SUM(reserved_credits) FROM credit_grant WHERE tenant_id = 'tenant'",
            "SELECT COALESCE(SUM(reserved_credits), 0) FROM credit_reservation WHERE tenant_id = 'tenant'",
            "SELECT COALESCE(SUM(reserved_credits - consumed_credits - released_credits - expired_credits), 0)
                FROM credit_allocation WHERE tenant_id = 'tenant'",
        ] as $sql) {
            $this->assertSame($expected, (string) \Espo\Modules\FeatureCredits\Accounting\Amount::fromString((string) $pdo->query($sql)->fetchColumn()), $sql);
        }
    }

    #[DataProvider('dialects')]
    public function testReservationOrderingReplayAndLateRelease(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $service = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input('purchase', '4'));
            $funding->grant($this->input('later', '1', expires: '2026-10-12 00:00:00'));
            $funding->grant($this->input('soon', '1', expires: '2026-10-11 00:00:00'));
            $input = $this->admission(credits: '1.5');
            $receipt = $service->reserve($input);
            $this->assertSame($receipt, $service->reserve($input));
            $this->assertHeld($pdo, '1.5000');
            $this->assertSame(['later' => '0.5000', 'purchase' => '0.0000', 'soon' => '1.0000'],
                $pdo->query('SELECT source_key, reserved_credits FROM credit_grant ORDER BY source_key')->fetchAll(PDO::FETCH_KEY_PAIR));
            try {
                $service->reserve($this->admission(credits: '1.6'));
                $this->fail('Conflicting admission accepted.');
            } catch (Conflict) {}
            try {
                $service->release('tenant', $receipt['usageId'], 'different-execution');
                $this->fail('Wrong execution accepted.');
            } catch (Conflict) {}
            try {
                $service->release('other', $receipt['usageId'], 'execution');
                $this->fail('Cross-tenant release accepted.');
            } catch (NotFound) {}
            $this->now = '2026-10-11 00:00:00';
            $released = $service->release('tenant', $receipt['usageId'], 'execution');
            $this->assertSame('released', $released['state']);
            $this->assertSame($released, $service->release('tenant', $receipt['usageId'], 'execution'));
            $this->assertSame($released, $service->reserve($input), 'Closed replay must not reauthorize.');
            $this->assertHeld($pdo, '0.0000');
            $this->assertConsistent($pdo, '5.0000');
            $this->assertSame('1.0000', $pdo->query('SELECT SUM(expired_credits) FROM credit_allocation')->fetchColumn());
            $this->assertSame('0.5000', $pdo->query('SELECT SUM(released_credits) FROM credit_allocation')->fetchColumn());
            $funding->expire('tenant');
            $this->assertConsistent($pdo, '5.0000');
        });
    }

    #[DataProvider('dialects')]
    public function testAdmissionMinimumExpirationAndAgreementGuards(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $service = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input('purchase', '1'));
            $funding->grant($this->input('monthly', '10', expires: '2026-10-11 00:00:00'));
            $this->now = '2026-10-11 00:00:00';
            try {
                $service->reserve($this->admission(credits: '2'));
                $this->fail('Expired funds authorized.');
            } catch (Conflict) {
                $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM credit_usage')->fetchColumn());
            }
            $receipt = $service->reserve($this->admission(credits: '0.0001'));
            $this->assertSame('0.5000', $receipt['reservedCredits']);
            $this->assertConsistent($pdo, '1.0000');
            $service->reserve($this->admission('apollo', '0.0001', 'apollo'));
            $this->assertHeld($pdo, '0.5001');
            $pdo->exec("UPDATE tenant_credit_billing_rate SET tenant_id = 'other'");
            try {
                $service->reserve($this->admission('wrong-rate', '0.0001'));
                $this->fail('Other tenant agreement accepted.');
            } catch (Conflict) {}
            $pdo->exec("UPDATE tenant_credit_billing_rate SET tenant_id = 'tenant', effective_until = '2026-10-11 00:00:00'");
            try {
                $service->reserve($this->admission('expired-rate', '0.0001'));
                $this->fail('Expired agreement accepted.');
            } catch (Conflict) {}
            $this->assertSame($receipt, $service->reserve($this->admission(credits: '0.0001')));
        });
    }

    #[DataProvider('dialects')]
    public function testReservationAndReleaseFaultsRollbackAllProjections(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $service = $this->reservations($pdo);
            (new Funding($this->lock($pdo)))->grant($this->input('monthly', '3', expires: '2026-10-11 00:00:00'));
            foreach (['credit_usage', 'credit_reservation', 'credit_allocation', 'tenant_credit_balance'] as $table) {
                $condition = $table === 'tenant_credit_balance' ? 'reserved_credits = 0' : '1 = 0';
                $pdo->exec("ALTER TABLE $table ADD CONSTRAINT injected_failure CHECK ($condition)");
                try {
                    $service->reserve($this->admission());
                    $this->fail('Injected admission failure ignored.');
                } catch (PDOException) {
                    $this->assertHeld($pdo, '0.0000');
                    $this->assertConsistent($pdo, '3.0000');
                    $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM credit_usage')->fetchColumn());
                } finally {
                    $pdo->exec("ALTER TABLE $table DROP " . ($dialect === 'Mysql' ? 'CHECK' : 'CONSTRAINT') . ' injected_failure');
                }
            }
            $receipt = $service->reserve($this->admission());
            $this->now = '2026-10-11 00:00:00';
            foreach (['credit_transaction' => "type = 'grant'", 'credit_allocation' => 'settled_at IS NULL',
                'tenant_credit_balance' => 'balance = 3'] as $table => $condition) {
                $pdo->exec("ALTER TABLE $table ADD CONSTRAINT injected_failure CHECK ($condition)");
                try {
                    $service->release('tenant', $receipt['usageId'], 'execution');
                    $this->fail('Injected release failure ignored.');
                } catch (PDOException) {
                    $this->assertHeld($pdo, '2.0000');
                    $this->assertConsistent($pdo, '3.0000');
                    $this->assertSame($receipt, $service->reserve($this->admission()));
                } finally {
                    $pdo->exec("ALTER TABLE $table DROP " . ($dialect === 'Mysql' ? 'CHECK' : 'CONSTRAINT') . ' injected_failure');
                }
            }
            $service->release('tenant', $receipt['usageId'], 'execution');
            $this->assertHeld($pdo, '0.0000');
            $this->assertConsistent($pdo, '0.0000');
            $this->assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type = 'expiration'")->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testAccruedUsageCannotBeReleased(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $service = $this->reservations($pdo);
            (new Funding($this->lock($pdo)))->grant($this->input());
            $receipt = $service->reserve($this->admission());
            $pdo->exec("UPDATE credit_reservation SET accrued_credits_exact = '0.1'");
            try {
                $service->release('tenant', $receipt['usageId'], 'execution');
                $this->fail('Accrued usage waived.');
            } catch (Conflict) {
                $this->assertHeld($pdo, '2.0000');
                $this->assertConsistent($pdo, '10.0000');
            }
            $pdo->exec("UPDATE credit_reservation SET accrued_credits_exact = '0'");
            $pdo->prepare('INSERT INTO credit_request
                (id, tenant_id, usage_id, reservation_id, request_key, input_hash, pricing_snapshot,
                 authorized_credits, outcome, metering_state, billing_state, authorized_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                    'request', 'tenant', $receipt['usageId'], $receipt['reservationId'], 'request-1', str_repeat('a', 64),
                    '{}', '2.0000', 'inFlight', 'unknown', 'pending', $this->now,
                ]);
            try {
                $service->release('tenant', $receipt['usageId'], 'execution');
                $this->fail('In-flight request waived.');
            } catch (Conflict) {
                $this->assertHeld($pdo, '2.0000');
            }
        });
    }

    #[DataProvider('dialects')]
    public function testConcurrentDuplicateAdmissionAndExpiredFundingRace(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $this->reservations($pdo);
            (new Funding($this->lock($pdo)))->grant($this->input(credits: '1', expires: '2026-10-11 00:00:00'));
            $results = $this->race($pdo, $dialect, [
                ['reserve', 'same', '0.5', ''], ['reserve', 'same', '0.5000', ''],
            ]);
            $this->assertCount(2, array_column($results, 'result'));
            $this->assertSame($results[0], $results[1]);
            $this->assertHeld($pdo, '0.5000');
            $results = $this->race($pdo, $dialect, [
                ['reserve', 'conflict', '0.5', ''], ['reserve', 'conflict', '0.4', ''],
            ]);
            $this->assertCount(1, array_column($results, 'result'));
            $this->assertSame([Conflict::class], array_values(array_column($results, 'error')));
            $this->assertHeld($pdo, '1.0000');
            $this->now = '2026-10-11 00:00:00';
            $results = $this->race($pdo, $dialect, [
                ['reserve', 'unfunded', '2', ''], ['expire', '', '', ''], ['grant', 'renewal', '1', '2026-11-11 00:00:00'],
            ]);
            $this->assertSame(Conflict::class, $results[0]['error']);
            $this->assertCount(2, array_column($results, 'result'));
            $this->assertHeld($pdo, '1.0000');
            $this->assertConsistent($pdo, '2.0000');
        });
    }

    #[DataProvider('dialects')]
    public function testConcurrentReservationsCannotDoubleSpendAndReleaseOnce(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $this->reservations($pdo);
            (new Funding($this->lock($pdo)))->grant($this->input(credits: '1', expires: '2026-10-11 00:00:00'));
            $results = $this->race($pdo, $dialect, [
                ['reserve', 'first', '1', ''], ['reserve', 'second', '1', ''],
            ]);
            $this->assertCount(1, array_column($results, 'result'));
            $this->assertSame([Conflict::class], array_values(array_column($results, 'error')));
            $this->assertHeld($pdo, '1.0000');
            $winner = array_values(array_column($results, 'result'))[0];
            $this->now = '2026-10-11 00:00:00';
            $results = $this->race($pdo, $dialect, [
                ['release', $winner['usageId'], '', ''], ['release', $winner['usageId'], '', ''], ['expire', '', '', ''],
            ]);
            $this->assertCount(3, array_column($results, 'result'));
            $this->assertSame($results[0], $results[1]);
            $this->assertHeld($pdo, '0.0000');
            $this->assertConsistent($pdo, '0.0000');
            $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type = 'expiration'")->fetchColumn());
        });
    }

    private function request(string $usageId, string $key = 'request-1', int $output = 1000, string $multiplier = '1'): RequestInput
    {
        return new RequestInput('tenant', $usageId, 'execution', $key, new AiRate('model-v1', 'provider', 'model', $multiplier), 0, $output);
    }

    #[DataProvider('dialects')]
    public function testRequestAssignmentExtensionReplayAndOwnership(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input(credits: '1'));
            $usage = $reservations->reserve($this->admission(credits: '0.5'))['usageId'];
            $service = new Requests($this->lock($pdo), $funding);
            $input = $this->request($usage);
            $receipt = $service->authorize($input);
            $this->assertTrue($receipt['dispatchAllowed']);
            $this->assertSame('0.1875', $receipt['authorizedCredits']);
            $receipt['dispatchAllowed'] = false;
            $this->assertSame($receipt, $service->authorize($this->request($usage, multiplier: '1.00')));
            $this->assertSame($input->snapshotJson, $pdo->query('SELECT pricing_snapshot FROM credit_request')->fetchColumn());
            $this->assertHeld($pdo, '0.5000');
            $service->authorize($this->request($usage, 'second', 2000));
            $this->assertHeld($pdo, '0.5625');
            $this->assertSame('0.5625', $pdo->query('SELECT SUM(reserved_credits) FROM credit_allocation WHERE request_id IS NOT NULL')->fetchColumn());
            foreach ([$this->request($usage, output: 1001), $this->request($usage, multiplier: '2'),
                new RequestInput('tenant', $usage, 'takeover', 'request-1', $input->rate, 0, 1000),
                $this->request($usage, 'unfunded', 10000)] as $bad) {
                try {
                    $service->authorize($bad);
                    $this->fail('Invalid or unfunded request authorized.');
                } catch (Conflict) {
                    $this->assertHeld($pdo, '0.5625');
                }
            }
            try {
                $service->authorize(new RequestInput('other', $usage, 'execution', 'request-1', $input->rate, 0, 1000));
                $this->fail('Cross-tenant request authorized.');
            } catch (NotFound) {}
            try {
                $reservations->release('tenant', $usage, 'execution');
                $this->fail('Authorized requests released without reconciliation.');
            } catch (Conflict) {}
            $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM credit_request')->fetchColumn());
            $this->assertConsistent($pdo, '1.0000');
        });
    }

    #[DataProvider('dialects')]
    public function testRequestExpirationReallocationPreservesInFlightFunds(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input('monthly', '1', expires: '2026-10-11 00:00:00'));
            $funding->grant($this->input('purchase', '1'));
            $usage = $reservations->reserve($this->admission(credits: '0.5'))['usageId'];
            $service = new Requests($this->lock($pdo), $funding);
            $first = $service->authorize($this->request($usage));
            $this->now = '2026-10-11 00:00:00';
            $service->authorize($this->request($usage, 'second', 2000));
            $this->assertHeld($pdo, '0.5625');
            $this->assertConsistent($pdo, '1.1875');
            $this->assertSame(['monthly' => '0.1875', 'purchase' => '0.3750'],
                $pdo->query('SELECT source_key, reserved_credits FROM credit_grant ORDER BY source_key')->fetchAll(PDO::FETCH_KEY_PAIR));
            $this->assertSame('0.3125', $pdo->query('SELECT SUM(expired_credits) FROM credit_allocation')->fetchColumn());
            $this->assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type = 'expiration'")->fetchColumn());
            $first['dispatchAllowed'] = false;
            $this->assertSame($first, $service->authorize($this->request($usage)));
            $funding->expire('tenant');
            $this->assertConsistent($pdo, '1.1875');
            $this->assertHeld($pdo, '0.5625');
        });
    }

    #[DataProvider('dialects')]
    public function testExpiredInitialHoldCannotAuthorizeAndClosedOperationCannotRestart(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input('monthly', '1', expires: '2026-10-11 00:00:00'));
            $usage = $reservations->reserve($this->admission(credits: '0.5'))['usageId'];
            $service = new Requests($this->lock($pdo), $funding);
            $this->now = '2026-10-11 00:00:00';
            try {
                $service->authorize($this->request($usage));
                $this->fail('Expired admission buffer authorized.');
            } catch (Conflict) {
                $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM credit_request')->fetchColumn());
                $this->assertHeld($pdo, '0.5000');
                $this->assertConsistent($pdo, '1.0000');
            }
            $reservations->release('tenant', $usage, 'execution');
            $funding->grant($this->input('purchase', '2'));
            try {
                $service->authorize($this->request($usage));
                $this->fail('Closed operation restarted.');
            } catch (Conflict) {
                $this->assertHeld($pdo, '0.0000');
            }
        });
    }

    #[DataProvider('dialects')]
    public function testRequestWriteFailuresRollbackExpirationAndExtension(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input('monthly', '1', expires: '2026-10-11 00:00:00'));
            $funding->grant($this->input('purchase', '2'));
            $usage = $reservations->reserve($this->admission(credits: '0.5'))['usageId'];
            $service = new Requests($this->lock($pdo), $funding);
            $this->now = '2026-10-11 00:00:00';
            foreach (['credit_transaction' => "type = 'grant'", 'credit_request' => '1 = 0',
                'credit_allocation' => 'request_id IS NULL', 'credit_grant' => 'remaining_credits >= 1',
                'credit_reservation' => 'reserved_credits = 0.5', 'tenant_credit_balance' => 'balance = 3'] as $table => $condition) {
                $pdo->exec("ALTER TABLE $table ADD CONSTRAINT injected_failure CHECK ($condition)");
                try {
                    $service->authorize($this->request($usage, output: 4000));
                    $this->fail('Injected request failure ignored.');
                } catch (PDOException) {
                    $this->assertHeld($pdo, '0.5000');
                    $this->assertConsistent($pdo, '3.0000');
                    $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM credit_request')->fetchColumn());
                    $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM credit_allocation')->fetchColumn());
                } finally {
                    $pdo->exec("ALTER TABLE $table DROP " . ($dialect === 'Mysql' ? 'CHECK' : 'CONSTRAINT') . ' injected_failure');
                }
            }
            $service->authorize($this->request($usage, output: 4000));
            $this->assertHeld($pdo, '0.7500');
            $this->assertConsistent($pdo, '2.0000');
        });
    }

    #[DataProvider('dialects')]
    public function testConcurrentRequestReplayAndExtensionsCannotDoubleAuthorize(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $reservations = $this->reservations($pdo);
            (new Funding($this->lock($pdo)))->grant($this->input(credits: '1'));
            $usage = $reservations->reserve($this->admission(credits: '0.5'))['usageId'];
            $results = $this->race($pdo, $dialect, [
                ['request', $usage, '1000', 'same'], ['request', $usage, '1000', 'same'],
            ]);
            $receipts = array_column($results, 'result');
            $this->assertCount(2, $receipts);
            $this->assertSame($receipts[0]['requestId'], $receipts[1]['requestId']);
            $this->assertSame(1, count(array_filter(array_column($receipts, 'dispatchAllowed'))));
            $results = $this->race($pdo, $dialect, [
                ['request', $usage, '3000', 'second'], ['request', $usage, '3000', 'third'],
            ]);
            $this->assertCount(1, array_column($results, 'result'));
            $this->assertSame([Conflict::class], array_values(array_column($results, 'error')));
            $this->assertHeld($pdo, '0.7500');
            $this->assertConsistent($pdo, '1.0000');
            $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM credit_request')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testRequestRacesWithExpirationRenewalAndConflictingReplay(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input('monthly', '1', expires: '2026-10-11 00:00:00'));
            $funding->grant($this->input('purchase', '0.5'));
            $usage = $reservations->reserve($this->admission(credits: '0.5'))['usageId'];
            $service = new Requests($this->lock($pdo), $funding);
            $service->authorize($this->request($usage));
            $this->now = '2026-10-11 00:00:00';
            $results = $this->race($pdo, $dialect, [
                ['request', $usage, '2000', 'next'], ['expire', '', '', ''],
                ['grant', 'renewal', '1', '2026-11-11 00:00:00'],
            ]);
            $this->assertCount(3, array_column($results, 'result'));
            $this->assertHeld($pdo, '0.5625');
            $this->assertConsistent($pdo, '1.6875');
            $results = $this->race($pdo, $dialect, [
                ['request', $usage, '1000', 'conflict'], ['request', $usage, '2000', 'conflict'],
            ]);
            $this->assertCount(1, array_column($results, 'result'));
            $this->assertSame([Conflict::class], array_values(array_column($results, 'error')));
            $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM credit_request')->fetchColumn());
            // Terminal evidence never turns replay into permission to send again.
            $pdo->exec("UPDATE credit_request SET outcome = 'success', metering_state = 'measured', billing_state = 'billable', priced_credits_exact = '0.1'
                WHERE request_key = 'request-1'");
            $pdo->exec("UPDATE credit_reservation SET accrued_credits_exact = '0.1'");
            $replay = $service->authorize($this->request($usage));
            $this->assertFalse($replay['dispatchAllowed']);
            $this->assertSame('measured', $replay['meteringState']);
            $this->assertSame('billable', $replay['billingState']);
        });
    }

    #[DataProvider('dialects')]
    public function testSeparateOperationsCompeteForRequestExtension(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $reservations = $this->reservations($pdo);
            (new Funding($this->lock($pdo)))->grant($this->input(credits: '1.25'));
            $first = $reservations->reserve($this->admission('first', '0.5'))['usageId'];
            $second = $reservations->reserve($this->admission('second', '0.5'))['usageId'];
            $results = $this->race($pdo, $dialect, [
                ['request', $first, '4000', 'first'], ['request', $second, '4000', 'second'],
            ]);
            $this->assertCount(1, array_column($results, 'result'));
            $this->assertSame([Conflict::class], array_values(array_column($results, 'error')));
            $this->assertHeld($pdo, '1.2500');
            $this->assertConsistent($pdo, '1.2500');
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM credit_request')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testMeasuredOutcomesPreserveExactAccrualAndRequestScopedWaivers(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input());
            $usage = $reservations->reserve($this->admission())['usageId'];
            $requests = new Requests($this->lock($pdo), $funding);
            $outcomes = new Outcomes($this->lock($pdo));
            foreach (['success', 'cancelled', 'superseded', 'infrastructureFailure'] as $kind) {
                $input = new RequestInput('tenant', $usage, 'execution', strtolower($kind),
                    new AiRate('rate-v2', 'provider', 'model', '1.25'), 100, 100);
                $request = $requests->authorize($input)['requestId'];
                $command = new OutcomeInput('tenant', $usage, 'execution', $request, $kind, 10, 4, 1,
                    (object) ['source' => 'provider'], 'Provider-ID');
                $result = $outcomes->record($command);
                $this->assertSame('0.000534375', $result['pricedCreditsExact']);
                $this->assertSame($kind === 'infrastructureFailure' ? 'waived' : 'billable', $result['billingState']);
                $this->assertSame($result, $outcomes->record($command));
                $this->assertFalse($requests->authorize($input)['dispatchAllowed']);
            }
            $this->assertSame('0.001603125', $pdo->query('SELECT accrued_credits_exact FROM credit_reservation')->fetchColumn());
            $this->assertHeld($pdo, '2.0000');
            $this->assertConsistent($pdo, '10.0000');
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM credit_transaction')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testUnknownOutcomeReconciliationAndImmutableReplay(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input());
            $usage = $reservations->reserve($this->admission())['usageId'];
            $requests = new Requests($this->lock($pdo), $funding);
            $request = $requests->authorize($this->request($usage))['requestId'];
            $outcomes = new Outcomes($this->lock($pdo));
            $pending = new OutcomeInput('tenant', $usage, 'execution', $request, 'cancelled', 0, 0, null,
                (object) ['source' => 'interrupted-stream']);
            $result = $outcomes->record($pending);
            $this->assertSame('pending', $result['billingState']);
            $this->assertNull($result['pricedCreditsExact']);
            $this->assertSame('0', $pdo->query('SELECT accrued_credits_exact FROM credit_reservation')->fetchColumn());
            $this->now = '2026-10-11 00:00:00';
            $resolved = new OutcomeInput('tenant', $usage, 'execution', $request, 'cancelled', 0, 0, 1,
                (object) ['source' => 'authoritative-provider-receipt'], 'provider-id');
            $result = $outcomes->record($resolved);
            $this->assertSame('billable', $result['billingState']);
            $this->assertSame('0.0001875', $result['pricedCreditsExact']);
            $this->assertSame($result, $outcomes->record($pending));
            $this->assertSame($result, $outcomes->record($resolved));
            $row = $pdo->query('SELECT * FROM credit_request')->fetch(PDO::FETCH_ASSOC);
            $this->assertSame('2026-10-10 12:00:00', $row['completed_at']);
            $history = json_decode($row['outcome_record'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertCount(2, $history);
            $this->assertSame('interrupted-stream', $history['initial']['input']['evidence']['source']);
            foreach ([
                new OutcomeInput('tenant', $usage, 'takeover', $request, 'cancelled', 0, 0, 1, (object) ['source' => 'provider']),
                new OutcomeInput('tenant', $usage, 'execution', $request, 'success', 0, 0, 1, (object) ['source' => 'provider']),
                new OutcomeInput('tenant', $usage, 'execution', $request, 'cancelled', 0, 0, 2, (object) ['source' => 'provider']),
            ] as $conflicting) {
                try {
                    $outcomes->record($conflicting);
                    $this->fail('Conflicting outcome accepted.');
                } catch (Conflict) {
                    $this->assertSame($row, $pdo->query('SELECT * FROM credit_request')->fetch(PDO::FETCH_ASSOC));
                }
            }
            try {
                $outcomes->record(new OutcomeInput('other', $usage, 'execution', $request, 'success', 0, 0, 1, (object) ['source' => 'provider']));
                $this->fail('Cross-tenant outcome accepted.');
            } catch (NotFound) {
                $this->assertSame('0.0001875', $pdo->query('SELECT accrued_credits_exact FROM credit_reservation')->fetchColumn());
            }
            $this->assertHeld($pdo, '2.0000');
        });
    }

    #[DataProvider('dialects')]
    public function testOutcomeBoundAndWriteFailuresRollback(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input());
            $usage = $reservations->reserve($this->admission())['usageId'];
            $request = (new Requests($this->lock($pdo), $funding))->authorize($this->request($usage))['requestId'];
            $outcomes = new Outcomes($this->lock($pdo));
            $original = $pdo->query('SELECT * FROM credit_request')->fetch(PDO::FETCH_ASSOC);
            foreach (['credit_request' => "outcome = 'inFlight'", 'credit_reservation' => "accrued_credits_exact = '0'"] as $table => $condition) {
                $pdo->exec("ALTER TABLE $table ADD CONSTRAINT injected_failure CHECK ($condition)");
                try {
                    $outcomes->record(new OutcomeInput('tenant', $usage, 'execution', $request, 'success', 0, 0, 1, (object) ['source' => 'provider']));
                    $this->fail('Injected outcome write failure ignored.');
                } catch (PDOException) {
                    $this->assertSame($original, $pdo->query('SELECT * FROM credit_request')->fetch(PDO::FETCH_ASSOC));
                    $this->assertSame('0', $pdo->query('SELECT accrued_credits_exact FROM credit_reservation')->fetchColumn());
                } finally {
                    $pdo->exec("ALTER TABLE $table DROP " . ($dialect === 'Mysql' ? 'CHECK' : 'CONSTRAINT') . ' injected_failure');
                }
            }
            try {
                $outcomes->record(new OutcomeInput('tenant', $usage, 'execution', $request, 'success', 0, 0, 1001, (object) ['source' => 'provider']));
                $this->fail('Provider bound violation accepted.');
            } catch (Conflict) {
                $this->assertSame($original, $pdo->query('SELECT * FROM credit_request')->fetch(PDO::FETCH_ASSOC));
            }
            $this->assertConsistent($pdo, '10.0000');
            $this->assertHeld($pdo, '2.0000');
        });
    }

    #[DataProvider('dialects')]
    public function testConcurrentOutcomesReplayConflictAndAccrual(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input());
            $usage = $reservations->reserve($this->admission())['usageId'];
            $requests = new Requests($this->lock($pdo), $funding);
            $first = $requests->authorize($this->request($usage))['requestId'];
            $second = $requests->authorize($this->request($usage, 'second'))['requestId'];
            $results = $this->race($pdo, $dialect, [
                ['outcome', $usage, '1', $first], ['outcome', $usage, '1', $first], ['outcome', $usage, '2', $second],
            ]);
            $this->assertCount(3, array_column($results, 'result'));
            $this->assertSame('0.0005625', $pdo->query('SELECT accrued_credits_exact FROM credit_reservation')->fetchColumn());
            $third = $requests->authorize($this->request($usage, 'third'))['requestId'];
            $results = $this->race($pdo, $dialect, [
                ['outcome', $usage, '1', $third], ['outcome', $usage, '2', $third],
            ]);
            $this->assertCount(1, array_column($results, 'result'));
            $this->assertSame([Conflict::class], array_values(array_column($results, 'error')));
            $this->assertContains($pdo->query('SELECT accrued_credits_exact FROM credit_reservation')->fetchColumn(), ['0.00075', '0.0009375']);
            $this->assertHeld($pdo, '2.0000');
            $this->assertConsistent($pdo, '10.0000');
        });
    }

    #[DataProvider('dialects')]
    public function testWaivedUnknownMeteringAndMeasuredZeroRemainDistinct(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input());
            $usage = $reservations->reserve($this->admission())['usageId'];
            $requests = new Requests($this->lock($pdo), $funding);
            $outcomes = new Outcomes($this->lock($pdo));
            $request = $requests->authorize($this->request($usage))['requestId'];
            $unknown = new OutcomeInput('tenant', $usage, 'execution', $request, 'infrastructureFailure', null, null, null,
                (object) ['failure' => 'provider-timeout'], 'provider-id');
            $result = $outcomes->record($unknown);
            $this->assertSame('waived', $result['billingState']);
            $this->assertSame('unknown', $result['meteringState']);
            $this->assertNull($result['pricedCreditsExact']);
            try {
                $outcomes->record(new OutcomeInput('tenant', $usage, 'execution', $request, 'infrastructureFailure', 0, 0, 1,
                    (object) ['source' => 'provider'], 'different-provider-id'));
                $this->fail('Provider request identity was replaced.');
            } catch (Conflict) {
                $this->assertSame($result, $outcomes->record($unknown));
            }
            // Provider COGS evidence remains available even for a waived, over-bound failed request.
            $result = $outcomes->record(new OutcomeInput('tenant', $usage, 'execution', $request, 'infrastructureFailure', 0, 0, 1001,
                (object) ['source' => 'provider'], 'provider-id'));
            $this->assertSame('waived', $result['billingState']);
            $this->assertSame('measured', $result['meteringState']);
            $this->assertSame('0.1876875', $result['pricedCreditsExact']);
            $this->assertSame($result, $outcomes->record($unknown));
            $zero = $requests->authorize($this->request($usage, 'zero'))['requestId'];
            $result = $outcomes->record(new OutcomeInput('tenant', $usage, 'execution', $zero, 'success', 0, 0, 0,
                (object) ['source' => 'provider']));
            $this->assertSame('billable', $result['billingState']);
            $this->assertSame('measured', $result['meteringState']);
            $this->assertSame('0', $result['pricedCreditsExact']);
            $this->assertSame('0', $pdo->query('SELECT accrued_credits_exact FROM credit_reservation')->fetchColumn());
            try {
                $reservations->release('tenant', $usage, 'execution');
                $this->fail('Requests were released through pre-request cancellation.');
            } catch (Conflict) {
                $this->assertHeld($pdo, '2.0000');
            }
        });
    }

    #[DataProvider('dialects')]
    public function testOutcomesRaceExpirationAndNewAuthorizationWithoutReusingAccrual(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input('monthly', '2', expires: '2026-10-11 00:00:00'));
            $funding->grant($this->input('purchase', '1'));
            $usage = $reservations->reserve($this->admission(credits: '0.5'))['usageId'];
            $request = (new Requests($this->lock($pdo), $funding))->authorize($this->request($usage))['requestId'];
            $this->now = '2026-10-11 00:00:00';
            $results = $this->race($pdo, $dialect, [
                ['outcome', $usage, '1', $request], ['expire', '', '', ''], ['request', $usage, '1000', 'next'],
            ]);
            $this->assertCount(3, array_column($results, 'result'));
            $this->assertSame('0.0001875', $pdo->query('SELECT accrued_credits_exact FROM credit_reservation')->fetchColumn());
            $this->assertConsistent($pdo, '1.1875');
            $this->assertHeld($pdo, '0.3750');
            $this->assertSame('0.1875', $pdo->query("SELECT reserved_credits FROM credit_grant WHERE source_key = 'monthly'")->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testOutcomesRejectChangedPricingAndClosedOperations(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input());
            $usage = $reservations->reserve($this->admission())['usageId'];
            $request = (new Requests($this->lock($pdo), $funding))->authorize($this->request($usage))['requestId'];
            $outcomes = new Outcomes($this->lock($pdo));
            $command = new OutcomeInput('tenant', $usage, 'execution', $request, 'success', 0, 0, 1, (object) ['source' => 'provider']);
            $snapshot = $pdo->query('SELECT pricing_snapshot FROM credit_request')->fetchColumn();
            foreach (['formula' => 'future-formula', 'outputCredits' => '2.0000'] as $key => $value) {
                $changed = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
                $changed[$key] = $value;
                $pdo->prepare('UPDATE credit_request SET pricing_snapshot = ?')->execute([json_encode($changed, JSON_THROW_ON_ERROR)]);
                try {
                    $outcomes->record($command);
                    $this->fail('Unsupported pricing was silently repriced.');
                } catch (Conflict) {
                    $this->assertNull($pdo->query('SELECT outcome_record FROM credit_request')->fetchColumn());
                }
            }
            $pdo->prepare('UPDATE credit_request SET pricing_snapshot = ?')->execute([$snapshot]);
            $pdo->exec("UPDATE credit_usage SET state = 'settled'");
            try {
                $outcomes->record($command);
                $this->fail('Closed operation accepted a new outcome.');
            } catch (Conflict) {
                $this->assertNull($pdo->query('SELECT outcome_record FROM credit_request')->fetchColumn());
            }
            $pdo->exec("UPDATE credit_usage SET state = 'admitted'");
            $result = $outcomes->record($command);
            $pdo->exec("UPDATE credit_usage SET state = 'settled'");
            $this->assertSame($result, $outcomes->record($command), 'Matching replay remains available after closure.');
        });
    }

    #[DataProvider('dialects')]
    public function testSettlementRoundsOperationOnceAndReplays(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input());
            $usage = $reservations->reserve($this->admission())['usageId'];
            $requests = new Requests($this->lock($pdo), $funding);
            $outcomes = new Outcomes($this->lock($pdo));
            // Successful, cancelled, and superseded measurements all survive the later failure.
            foreach (['success', 'cancelled', 'superseded'] as $key) {
                $id = $requests->authorize($this->request($usage, $key))['requestId'];
                $outcomes->record(new OutcomeInput('tenant', $usage, 'execution', $id, $key, 0, 0, 1, (object) ['source' => 'provider']));
            }
            $id = $requests->authorize($this->request($usage, 'failed'))['requestId'];
            $outcomes->record(new OutcomeInput('tenant', $usage, 'execution', $id, 'infrastructureFailure', null, null, null,
                (object) ['source' => 'provider']));
            $service = new Settlements($this->lock($pdo), $funding);
            $receipt = $service->settle('tenant', $usage, 'execution');
            $this->assertSame('0.0006', $receipt['settledCredits']);
            $this->assertSame('settled', $receipt['state']);
            $this->assertSame($receipt, $service->settle('tenant', $usage, 'execution'));
            $this->assertSame('0.0005625', $pdo->query('SELECT accrued_credits_exact FROM credit_reservation')->fetchColumn());
            $this->assertSame('0.0000', $pdo->query("SELECT SUM(consumed_credits) FROM credit_allocation WHERE request_id = '$id'")->fetchColumn());
            $this->assertSame('-0.0006', $pdo->query("SELECT SUM(credits) FROM credit_transaction WHERE type = 'debit'")->fetchColumn());
            $this->assertConsistent($pdo, '9.9994');
            $this->assertHeld($pdo, '0.0000');
            $this->assertSame('settled', $reservations->reserve($this->admission())['state']);
            try {
                $requests->authorize($this->request($usage, 'after-settlement'));
                $this->fail('Settled execution restarted.');
            } catch (Conflict) {}
            foreach ([['tenant', 'takeover'], ['other', 'execution']] as [$tenant, $execution]) {
                try {
                    $service->settle($tenant, $usage, $execution);
                    $this->fail('Settlement ownership bypassed.');
                } catch (Conflict|NotFound) {}
            }
        });
    }

    #[DataProvider('dialects')]
    public function testSettlementSubQuantumChargesAndZeroWaivers(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input());
            $requests = new Requests($this->lock($pdo), $funding);
            $outcomes = new Outcomes($this->lock($pdo));
            $service = new Settlements($this->lock($pdo), $funding);
            foreach (['tiny', 'half', 'zero', 'waived'] as $kind) {
                $usage = $reservations->reserve($this->admission($kind))['usageId'];
                for ($i = 0; $i < 2; $i++) {
                    $id = $requests->authorize(new RequestInput('tenant', $usage, 'execution', 'request-' . $i,
                        new AiRate('tiny-v1', 'provider', 'model', $kind === 'half' ? '0.2' : '0.1'), 0, 2))['requestId'];
                    $outcomes->record(new OutcomeInput('tenant', $usage, 'execution', $id,
                        $kind === 'waived' ? 'infrastructureFailure' : 'success', 0, 0, $kind === 'zero' ? 0 : 2,
                        (object) ['source' => 'provider']));
                }
                // Two 0.0000375 prices must round together to 0.0001, not zero.
                $this->assertSame(match ($kind) { 'tiny' => '0.0001', 'half' => '0.0002', default => '0.0000' },
                    $service->settle('tenant', $usage, 'execution')['settledCredits']);
            }
            $this->assertConsistent($pdo, '9.9997');
            $this->assertHeld($pdo, '0.0000');
            $this->assertSame(3, (int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type = 'debit'")->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testSettlementCannotDonateExpiredWaivedFundsToAnotherRequest(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input('monthly', '0.5', expires: '2026-10-11 00:00:00'));
            $funding->grant($this->input('purchase', '1'));
            $usage = $reservations->reserve($this->admission(credits: '0.5'))['usageId'];
            $requests = new Requests($this->lock($pdo), $funding);
            $outcomes = new Outcomes($this->lock($pdo));
            $waived = $requests->authorize($this->request($usage))['requestId'];
            $outcomes->record(new OutcomeInput('tenant', $usage, 'execution', $waived, 'infrastructureFailure', null, null, null,
                (object) ['source' => 'provider']));
            $this->now = '2026-10-11 00:00:00';
            $id = $requests->authorize($this->request($usage, 'after-renewal'))['requestId'];
            $outcomes->record(new OutcomeInput('tenant', $usage, 'execution', $id, 'success', 0, 0, 1000,
                (object) ['source' => 'provider']));
            $service = new Settlements($this->lock($pdo), $funding);
            $this->assertSame('0.1875', $service->settle('tenant', $usage, 'execution')['settledCredits']);
            $this->assertSame('0.0000', $pdo->query("SELECT SUM(consumed_credits) FROM credit_allocation WHERE request_id = '$waived'")->fetchColumn());
            $this->assertSame('-0.5000', $pdo->query("SELECT SUM(credits) FROM credit_transaction WHERE type = 'expiration'")->fetchColumn());
            $this->assertConsistent($pdo, '0.8125');
            $this->assertHeld($pdo, '0.0000');
        });
    }

    #[DataProvider('dialects')]
    public function testSettlementConsumesMultipleGrantLotsInExpiryOrder(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input('purchase', '1'));
            $funding->grant($this->input('later', '0.05', expires: '2026-10-12 00:00:00'));
            $funding->grant($this->input('soon', '0.05', expires: '2026-10-11 00:00:00'));
            $usage = $reservations->reserve($this->admission(credits: '0.5'))['usageId'];
            $id = (new Requests($this->lock($pdo), $funding))->authorize($this->request($usage))['requestId'];
            (new Outcomes($this->lock($pdo)))->record(new OutcomeInput('tenant', $usage, 'execution', $id, 'success', 0, 0, 800,
                (object) ['source' => 'provider']));
            (new Settlements($this->lock($pdo), $funding))->settle('tenant', $usage, 'execution');
            $this->assertSame(['later' => '0.0000', 'purchase' => '0.9500', 'soon' => '0.0000'],
                $pdo->query('SELECT source_key, remaining_credits FROM credit_grant ORDER BY source_key')->fetchAll(PDO::FETCH_KEY_PAIR));
            $this->assertSame('-0.1500', $pdo->query("SELECT SUM(credits) FROM credit_transaction WHERE type = 'debit'")->fetchColumn());
            $this->assertSame(3, (int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type = 'debit'")->fetchColumn());
            $this->assertConsistent($pdo, '0.9500');
            $this->assertHeld($pdo, '0.0000');
        });
    }

    #[DataProvider('dialects')]
    public function testSettlementUsesOriginalRequestLotsAndExpiresUnusedHolds(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input('monthly', '0.1', expires: '2026-10-11 00:00:00'));
            $funding->grant($this->input('purchase', '2'));
            $usage = $reservations->reserve($this->admission(credits: '0.5'))['usageId'];
            $id = (new Requests($this->lock($pdo), $funding))->authorize($this->request($usage))['requestId'];
            (new Outcomes($this->lock($pdo)))->record(new OutcomeInput('tenant', $usage, 'execution', $id, 'success', 0, 0, 400,
                (object) ['source' => 'provider']));
            $this->now = '2026-10-11 00:00:00';
            $receipt = (new Settlements($this->lock($pdo), $funding))->settle('tenant', $usage, 'execution');
            $this->assertSame('0.0750', $receipt['settledCredits']);
            $this->assertSame(['monthly' => '0.0000', 'purchase' => '2.0000'],
                $pdo->query('SELECT source_key, remaining_credits FROM credit_grant ORDER BY source_key')->fetchAll(PDO::FETCH_KEY_PAIR));
            $this->assertSame('0.0250', $pdo->query('SELECT SUM(expired_credits) FROM credit_allocation')->fetchColumn());
            $this->assertSame('0.4000', $pdo->query('SELECT SUM(released_credits) FROM credit_allocation WHERE allocation_key <> \'initial\'')->fetchColumn());
            $this->assertConsistent($pdo, '2.0000');
            $this->assertHeld($pdo, '0.0000');
        });
    }

    #[DataProvider('dialects')]
    public function testSettlementRejectsUnresolvedRequestsAndProjectionCorruption(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input());
            $usage = $reservations->reserve($this->admission())['usageId'];
            $service = new Settlements($this->lock($pdo), $funding);
            $requests = new Requests($this->lock($pdo), $funding);
            $outcomes = new Outcomes($this->lock($pdo));
            $id = $requests->authorize($this->request($usage))['requestId'];
            foreach (['inFlight', 'unknown', 'accrual', 'allocation', 'deleted'] as $state) {
                if ($state === 'unknown') {
                    $outcomes->record(new OutcomeInput('tenant', $usage, 'execution', $id, 'cancelled', null, null, null, (object) ['source' => 'provider']));
                }
                if ($state === 'accrual') {
                    $outcomes->record(new OutcomeInput('tenant', $usage, 'execution', $id, 'cancelled', 0, 0, 1, (object) ['source' => 'provider']));
                    $pdo->exec("UPDATE credit_reservation SET accrued_credits_exact = '0'");
                }
                if ($state === 'allocation') {
                    $pdo->exec("UPDATE credit_reservation SET accrued_credits_exact = '0.0001875'");
                    $pdo->exec("UPDATE credit_allocation SET request_id = NULL WHERE request_id = '$id'");
                }
                if ($state === 'deleted') {
                    $pdo->exec('UPDATE credit_request SET deleted = TRUE');
                }
                try {
                    $service->settle('tenant', $usage, 'execution');
                    $this->fail('Invalid settlement accepted: ' . $state);
                } catch (Conflict) {
                    $this->assertConsistent($pdo, '10.0000');
                    $this->assertHeld($pdo, '2.0000');
                    $this->assertNull($pdo->query('SELECT settled_credits FROM credit_usage')->fetchColumn());
                }
            }
        });
    }

    #[DataProvider('dialects')]
    public function testSettlementWriteFailuresRollbackAllProjections(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input('monthly', '10', expires: '2026-10-11 00:00:00'));
            $usage = $reservations->reserve($this->admission())['usageId'];
            $id = (new Requests($this->lock($pdo), $funding))->authorize($this->request($usage))['requestId'];
            (new Outcomes($this->lock($pdo)))->record(new OutcomeInput('tenant', $usage, 'execution', $id, 'success', 0, 0, 1,
                (object) ['source' => 'provider']));
            $this->now = '2026-10-11 00:00:00';
            $before = [];
            foreach (['credit_transaction', 'credit_grant', 'credit_allocation', 'credit_reservation', 'credit_usage', 'tenant_credit_balance'] as $table) {
                $before[$table] = $pdo->query("SELECT * FROM $table ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            }
            foreach (['credit_transaction' => "type <> 'debit'", 'credit_grant' => 'reserved_credits > 0',
                'credit_allocation' => 'consumed_credits = 0', 'credit_reservation' => "state = 'held'",
                'credit_usage' => "state = 'admitted'", 'tenant_credit_balance' => 'balance > 0'] as $table => $condition) {
                $pdo->exec("ALTER TABLE $table ADD CONSTRAINT injected_failure CHECK ($condition)");
                try {
                    (new Settlements($this->lock($pdo), $funding))->settle('tenant', $usage, 'execution');
                    $this->fail('Injected settlement fault ignored: ' . $table);
                } catch (PDOException) {
                    foreach ($before as $target => $rows) {
                        $this->assertSame($rows, $pdo->query("SELECT * FROM $target ORDER BY id")->fetchAll(PDO::FETCH_ASSOC));
                    }
                } finally {
                    $pdo->exec("ALTER TABLE $table DROP " . ($dialect === 'Mysql' ? 'CHECK' : 'CONSTRAINT') . ' injected_failure');
                }
            }
            $this->assertSame('0.0002', (new Settlements($this->lock($pdo), $funding))->settle('tenant', $usage, 'execution')['settledCredits']);
            $this->assertConsistent($pdo, '0.0000');
            $this->assertHeld($pdo, '0.0000');
        });
    }

    #[DataProvider('dialects')]
    public function testConcurrentSettlementReplayExpirationAndRenewal(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input('monthly', '2', expires: '2026-10-11 00:00:00'));
            $usage = $reservations->reserve($this->admission())['usageId'];
            $id = (new Requests($this->lock($pdo), $funding))->authorize($this->request($usage))['requestId'];
            (new Outcomes($this->lock($pdo)))->record(new OutcomeInput('tenant', $usage, 'execution', $id, 'success', 0, 0, 1,
                (object) ['source' => 'provider']));
            $this->now = '2026-10-11 00:00:00';
            $results = $this->race($pdo, $dialect, [
                ['settle', $usage, '', ''], ['settle', $usage, '', ''], ['expire', '', '', ''],
                ['grant', 'renewal', '3', '2026-11-11 00:00:00'],
            ]);
            $this->assertCount(4, array_column($results, 'result'));
            $this->assertSame($results[0], $results[1]);
            $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type = 'debit'")->fetchColumn());
            $this->assertConsistent($pdo, '3.0000');
            $this->assertHeld($pdo, '0.0000');
        });
    }

    #[DataProvider('dialects')]
    public function testSettlementRacesOutcomeAndAuthorization(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input());
            $usage = $reservations->reserve($this->admission())['usageId'];
            $id = (new Requests($this->lock($pdo), $funding))->authorize($this->request($usage))['requestId'];
            $results = $this->race($pdo, $dialect, [
                ['settle', $usage, '', ''], ['outcome', $usage, '1', $id], ['request', $usage, '1000', 'next'],
            ]);
            $this->assertArrayHasKey('result', $results[1]);
            $state = $pdo->query('SELECT state FROM credit_usage')->fetchColumn();
            if ($state === 'settled') {
                $this->assertSame(Conflict::class, $results[2]['error']);
                $this->assertConsistent($pdo, '9.9998');
                $this->assertHeld($pdo, '0.0000');
            } else {
                $this->assertSame(Conflict::class, $results[0]['error']);
                $this->assertConsistent($pdo, '10.0000');
                $this->assertHeld($pdo, '2.0000');
            }
        });
    }

    #[DataProvider('dialects')]
    public function testConcurrentSettlementsAndAdmissionCannotLoseOrReuseFunds(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input(credits: '1'));
            $usages = [];
            foreach (['first', 'second'] as $key) {
                $usage = $reservations->reserve($this->admission($key, '0.5'))['usageId'];
                $id = (new Requests($this->lock($pdo), $funding))->authorize($this->request($usage))['requestId'];
                (new Outcomes($this->lock($pdo)))->record(new OutcomeInput('tenant', $usage, 'execution', $id, 'success', 0, 0, 1000,
                    (object) ['source' => 'provider']));
                $usages[] = $usage;
            }
            $results = $this->race($pdo, $dialect, [
                ['settle', $usages[0], '', ''], ['settle', $usages[1], '', ''], ['reserve', 'third', '0.5', ''],
            ]);
            $this->assertArrayHasKey('result', $results[0]);
            $this->assertArrayHasKey('result', $results[1]);
            $this->assertConsistent($pdo, '0.6250');
            $this->assertHeld($pdo, isset($results[2]['result']) ? '0.5000' : '0.0000');
            if (isset($results[2]['error'])) {
                $this->assertSame(Conflict::class, $results[2]['error']);
            }
        });
    }

    private function discovery(PDO $pdo): ReconciliationDiscovery
    {
        $manager = $this->createMock(EntityManager::class);
        $manager->method('getPDO')->willReturn($pdo);
        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturnCallback(fn () => $this->now);
        return new ReconciliationDiscovery($manager, $clock);
    }

    #[DataProvider('dialects')]
    public function testRecoveryDiscoveryTimingPolicyAndReadOnlyLifecycle(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$usage, $request] = $this->pendingCancellation($pdo, 'superseded');
            $discovery = $this->discovery($pdo);
            $before = $pdo->query('SELECT * FROM credit_request')->fetchAll(PDO::FETCH_ASSOC);
            $page = $discovery->scan('tenant');
            $this->assertSame($page, $discovery->scan('tenant'));
            $this->assertSame($before, $pdo->query('SELECT * FROM credit_request')->fetchAll(PDO::FETCH_ASSOC));
            $this->assertFalse($pdo->inTransaction());
            $this->assertSame(1, $page['scannedCount']);
            $this->assertNull($page['nextAfterRequestId']);
            $item = $page['items'][0];
            $this->assertSame($request, $item['requestId']);
            $this->assertSame($usage, $item['usageId']);
            $this->assertSame('execution', $item['executionId']);
            $this->assertSame('provider-request', $item['providerRequestId']);
            $this->assertSame('superseded', $item['outcome']);
            $this->assertSame($this->now, $item['dueAt']);
            $this->assertNull($item['policy']);
            $this->assertNull($item['deadlineAt']);
            $this->assertSame(0, $item['attemptCount']);
            $service = new Reconciliation($this->lock($pdo));
            $service->recordUnavailable($this->reconciliation($usage, $request));
            $this->assertSame([], $discovery->scan('tenant')['items']);
            $this->now = '2026-10-10 12:00:59';
            $this->assertSame([], $discovery->scan('tenant')['items']);
            $this->now = '2026-10-10 12:01:00';
            $item = $discovery->scan('tenant')['items'][0];
            $this->assertSame($this->now, $item['dueAt']);
            $this->assertSame('operator', $item['policy']['operator']);
            $this->assertSame('test-v1', $item['policy']['version']);
            $this->assertSame('2026-10-10 12:02:00', $item['deadlineAt']);
            $this->assertSame(1, $item['attemptCount']);
            $this->now = '2026-10-10 12:10:00';
            $this->assertCount(1, $discovery->scan('tenant')['items'], 'Deadline does not resolve pending usage.');
            $this->assertConsistent($pdo, '10.0000');
            $this->assertHeld($pdo, '2.0000');
            $service->recordUnavailable($this->reconciliation($usage, $request, 'final'));
            $this->assertSame([], $discovery->scan('tenant')['items']);
        });
    }

    #[DataProvider('dialects')]
    public function testRecoveryDiscoveryCursorAdvancesPastNotDueRowsAndConcurrentResolution(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            [$usage, $request] = $this->pendingCancellation($pdo);
            $ids = [$request];
            $requests = new Requests($this->lock($pdo), new Funding($this->lock($pdo)));
            $outcomes = new Outcomes($this->lock($pdo));
            foreach (['second', 'third'] as $key) {
                $id = $requests->authorize($this->request($usage, $key))['requestId'];
                $outcomes->record(new OutcomeInput('tenant', $usage, 'execution', $id, 'cancelled',
                    null, null, null, (object) ['source' => 'provider']));
                $ids[] = $id;
            }
            sort($ids, SORT_STRING);
            (new Reconciliation($this->lock($pdo)))->recordUnavailable($this->reconciliation($usage, $ids[0]));
            $discovery = $this->discovery($pdo);
            $first = $discovery->scan('tenant', 1);
            $this->assertSame([], $first['items']);
            $this->assertSame(1, $first['scannedCount']);
            $this->assertSame($ids[0], $first['nextAfterRequestId']);
            $second = $discovery->scan('tenant', 1, $first['nextAfterRequestId']);
            $this->assertSame($ids[1], $second['items'][0]['requestId']);
            $this->assertSame($ids[1], $second['nextAfterRequestId']);
            // A different connection resolves the cursor row between pages. Keyset traversal still advances.
            $other = $this->connection($dialect);
            $providerId = $ids[1] === $request ? 'provider-request' : null;
            (new Outcomes($this->lock($other)))->record(new OutcomeInput('tenant', $usage, 'execution', $ids[1],
                'cancelled', 0, 0, 0, (object) ['source' => 'recovered'], $providerId));
            $last = $discovery->scan('tenant', 1, $second['nextAfterRequestId']);
            $this->assertSame($ids[2], $last['items'][0]['requestId']);
            $this->assertNull($last['nextAfterRequestId']);
            $this->assertSame([], $discovery->scan('other', 1, $ids[0])['items']);
            $this->now = '2026-10-10 12:01:00';
            $this->assertSame([$ids[0], $ids[2]], array_column($discovery->scan('tenant')['items'], 'requestId'));
        });
    }

    #[DataProvider('dialects')]
    public function testRecoveryDiscoveryRejectsIneligibleAndCrossLinkedRecords(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$usage, $request] = $this->pendingCancellation($pdo);
            $discovery = $this->discovery($pdo);
            $reservation = $pdo->query('SELECT id FROM credit_reservation')->fetchColumn();
            foreach ([
                ['credit_request', $request, 'deleted', true],
                ['credit_request', $request, 'tenant_id', 'other'],
                ['credit_request', $request, 'outcome', 'inFlight'],
                ['credit_request', $request, 'outcome', 'success'],
                ['credit_request', $request, 'outcome', 'infrastructureFailure'],
                ['credit_request', $request, 'metering_state', 'measured'],
                ['credit_request', $request, 'billing_state', 'waived'],
                ['credit_request', $request, 'priced_credits_exact', '0'],
                ['credit_request', $request, 'waiver_reason', 'infrastructure_failure'],
                ['credit_request', $request, 'completed_at', null],
                ['credit_request', $request, 'completed_at', '2026-10-11 00:00:00'],
                ['credit_request', $request, 'outcome_record', null],
                ['credit_usage', $usage, 'tenant_id', 'other'],
                ['credit_usage', $usage, 'deleted', true],
                ['credit_usage', $usage, 'operation_type', 'apollo'],
                ['credit_usage', $usage, 'billing_regime', 'legacy'],
                ['credit_usage', $usage, 'state', 'settled'],
                ['credit_reservation', $reservation, 'tenant_id', 'other'],
                ['credit_reservation', $reservation, 'deleted', true],
                ['credit_reservation', $reservation, 'execution_id', 'another'],
                ['credit_reservation', $reservation, 'usage_id', 'missing'],
                ['credit_reservation', $reservation, 'state', 'settled'],
            ] as [$table, $id, $field, $value]) {
                $query = $pdo->prepare("SELECT $field FROM $table WHERE id = ?");
                $query->execute([$id]);
                $original = $query->fetchColumn();
                $update = $pdo->prepare("UPDATE $table SET $field = ? WHERE id = ?");
                // PDO execute binds false as an empty string, not a PostgreSQL boolean.
                $update->execute([is_bool($value) ? (int) $value : $value, $id]);
                $this->assertSame([], $discovery->scan('tenant')['items'], "$table.$field");
                $this->assertSame([], $discovery->scan('other')['items'], "$table.$field tenant isolation");
                $update->execute([is_bool($original) ? (int) $original : $original, $id]);
                $this->assertCount(1, $discovery->scan('tenant')['items']);
            }
        });
    }

    #[DataProvider('dialects')]
    public function testRecoveryDiscoveryFailsClosedOnMalformedSchedule(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$usage, $request] = $this->pendingCancellation($pdo);
            (new Reconciliation($this->lock($pdo)))->recordUnavailable($this->reconciliation($usage, $request));
            $record = json_decode($pdo->query('SELECT reconciliation_record FROM credit_request')->fetchColumn(), true);
            $this->now = '2026-10-10 12:01:00';
            foreach ([[], array_replace($record, ['nextAttemptAt' => null]),
                array_replace($record, ['nextAttemptAt' => '2026-02-30 00:00:00']),
                array_replace($record, ['policy' => []]), array_replace($record, ['attempts' => []]),
                array_replace($record, ['waivedAt' => $this->now]),
            ] as $bad) {
                $pdo->prepare('UPDATE credit_request SET reconciliation_record = ? WHERE id = ?')
                    ->execute([json_encode($bad, JSON_THROW_ON_ERROR), $request]);
                try {
                    $this->discovery($pdo)->scan('tenant');
                    $this->fail('Corrupt schedule became immediate recovery work.');
                } catch (Conflict) {}
                $this->assertConsistent($pdo, '10.0000');
                $this->assertHeld($pdo, '2.0000');
            }
        });
    }

    #[DataProvider('dialects')]
    public function testRecoveryDiscoveryValidatesScopeBoundsAndTransactionContext(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $service = $this->discovery($pdo);
            foreach ([['tenant', 0, null], ['tenant', 501, null], ['bad tenant', 1, null], ['tenant', 1, 'bad cursor']] as $args) {
                try {
                    $service->scan(...$args);
                    $this->fail('Invalid discovery input accepted.');
                } catch (\InvalidArgumentException) {}
            }
            $this->assertSame([], $service->scan('tenant', 500)['items']);
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM tenant_credit_balance')->fetchColumn());
            $pdo->exec("UPDATE tenant SET deleted = TRUE WHERE id = 'other'");
            foreach (['missing', 'other'] as $tenant) {
                try {
                    $service->scan($tenant);
                    $this->fail('Missing/deleted tenant accepted.');
                } catch (NotFound) {}
            }
            $pdo->beginTransaction();
            try {
                $service->scan('tenant');
                $this->fail('Discovery allowed inside transaction.');
            } catch (LogicException) {
                $this->assertTrue($pdo->inTransaction());
            } finally {
                $pdo->rollBack();
            }
        });
    }

    private function reconciliation(string $usage, string $request, string $key = 'first', ?string $time = null): ReconciliationInput
    {
        return new ReconciliationInput('tenant', $usage, 'execution', $request, $key, 'operator', $time ?? $this->now,
            new ReconciliationPolicy('test-v1', 'operator', 120, 60, 2),
            (object) ['source' => 'provider', 'reference' => $key, 'reason' => 'usage unavailable']);
    }

    private function pendingCancellation(PDO $pdo, string $outcome = 'cancelled'): array
    {
        $reservations = $this->reservations($pdo);
        $funding = new Funding($this->lock($pdo));
        $funding->grant($this->input());
        $usage = $reservations->reserve($this->admission())['usageId'];
        $request = (new Requests($this->lock($pdo), $funding))->authorize($this->request($usage))['requestId'];
        if ($outcome !== 'inFlight') {
            (new Outcomes($this->lock($pdo)))->record(new OutcomeInput('tenant', $usage, 'execution', $request,
                $outcome, 0, 0, null, (object) ['source' => 'provider'], 'provider-request'));
        }
        return [$usage, $request];
    }

    #[DataProvider('dialects')]
    public function testReconciliationDeadlineCadenceReplayAndSuccessfulCharges(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$usage, $request] = $this->pendingCancellation($pdo);
            $service = new Reconciliation($this->lock($pdo));
            $first = $this->reconciliation($usage, $request);
            $before = $pdo->query('SELECT outcome_record, metering, evidence, completed_at, provider_request_id FROM credit_request')->fetch(PDO::FETCH_ASSOC);
            $receipt = $service->recordUnavailable($first);
            $this->assertSame('2026-10-10 12:02:00', $receipt['deadlineAt']);
            $this->assertSame('2026-10-10 12:01:00', $receipt['nextAttemptAt']);
            $this->assertSame($receipt, $service->recordUnavailable($first));
            try {
                $service->recordUnavailable($this->reconciliation($usage, $request, 'too-soon'));
                $this->fail('Cadence ignored.');
            } catch (Conflict) {}
            $this->now = '2026-10-10 12:01:00';
            $this->assertSame('pending', $service->recordUnavailable($this->reconciliation($usage, $request, 'second'))['billingState']);
            $this->now = '2026-10-10 12:02:00';
            $receipt = $service->recordUnavailable($this->reconciliation($usage, $request, 'third'));
            $this->assertSame('unrecoverable', $receipt['meteringState']);
            $this->assertSame('unrecoverable_cancellation', $receipt['waiverReason']);
            $this->assertSame(3, $receipt['attemptCount']);
            $this->assertNull($receipt['nextAttemptAt']);
            $this->assertSame($receipt, $service->recordUnavailable($first));
            $this->assertSame($before, $pdo->query('SELECT outcome_record, metering, evidence, completed_at, provider_request_id FROM credit_request')->fetch(PDO::FETCH_ASSOC));
            $this->assertHeld($pdo, '2.0000');
            $this->assertConsistent($pdo, '10.0000');
            $funding = new Funding($this->lock($pdo));
            $success = (new Requests($this->lock($pdo), $funding))->authorize($this->request($usage, 'success'))['requestId'];
            (new Outcomes($this->lock($pdo)))->record(new OutcomeInput('tenant', $usage, 'execution', $success, 'success', 0, 0, 1000,
                (object) ['source' => 'provider']));
            $settlement = (new Settlements($this->lock($pdo), $funding))->settle('tenant', $usage, 'execution');
            $this->assertSame('0.1875', $settlement['settledCredits']);
            $this->assertSame($receipt, $service->recordUnavailable($first));
            $this->assertConsistent($pdo, '9.8125');
            $this->assertHeld($pdo, '0.0000');
            try {
                (new Outcomes($this->lock($pdo)))->record(new OutcomeInput('tenant', $usage, 'execution', $request, 'cancelled', 0, 0, 1,
                    (object) ['source' => 'late'], 'provider-request'));
                $this->fail('Late metering rewrote waiver.');
            } catch (Conflict) {}
        });
    }

    #[DataProvider('dialects')]
    public function testReconciliationRequiresAttemptsAfterDeadlineAndExpiresOriginalLots(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input('monthly', '2', expires: '2026-10-11 00:00:00'));
            $usage = $reservations->reserve($this->admission())['usageId'];
            $request = (new Requests($this->lock($pdo), $funding))->authorize($this->request($usage))['requestId'];
            (new Outcomes($this->lock($pdo)))->record(new OutcomeInput('tenant', $usage, 'execution', $request, 'superseded', null, null, null,
                (object) ['source' => 'provider']));
            $this->now = '2026-10-11 00:00:00';
            $service = new Reconciliation($this->lock($pdo));
            $this->assertSame('pending', $service->recordUnavailable($this->reconciliation($usage, $request))['billingState']);
            try {
                (new Settlements($this->lock($pdo), $funding))->settle('tenant', $usage, 'execution');
                $this->fail('Deadline alone waived request.');
            } catch (Conflict) {}
            $this->now = '2026-10-11 00:01:00';
            $this->assertSame('waived', $service->recordUnavailable($this->reconciliation($usage, $request, 'second'))['billingState']);
            $this->assertSame('0.0000', (new Settlements($this->lock($pdo), $funding))->settle('tenant', $usage, 'execution')['settledCredits']);
            $this->assertSame('-2.0000', $pdo->query("SELECT SUM(credits) FROM credit_transaction WHERE type = 'expiration'")->fetchColumn());
            $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type = 'debit'")->fetchColumn());
            $this->assertConsistent($pdo, '0.0000');
            $this->assertHeld($pdo, '0.0000');
        });
    }

    #[DataProvider('dialects')]
    public function testReconciliationRejectsChangedPolicyEvidenceOwnershipAndObservation(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$usage, $request] = $this->pendingCancellation($pdo);
            $service = new Reconciliation($this->lock($pdo));
            $service->recordUnavailable($this->reconciliation($usage, $request));
            $this->now = '2026-10-10 12:02:00';
            $policy = new ReconciliationPolicy('test-v1', 'operator', 120, 60, 2);
            $evidence = (object) ['source' => 'provider', 'reference' => 'second', 'reason' => 'usage unavailable'];
            $invalid = [
                $this->reconciliation($usage, $request, 'first'), // changed observation under same key
                $this->reconciliation($usage, $request, 'future', '2026-10-10 12:03:00'),
                $this->reconciliation($usage, $request, 'stale', '2026-10-10 11:59:00'),
                new ReconciliationInput('tenant', $usage, 'execution', $request, 'second', 'operator', $this->now,
                    new ReconciliationPolicy('test-v2', 'operator', 60, 60, 2), $evidence),
                new ReconciliationInput('tenant', $usage, 'takeover', $request, 'second', 'operator', $this->now, $policy, $evidence),
                new ReconciliationInput('other', $usage, 'execution', $request, 'second', 'operator', $this->now, $policy, $evidence),
                new ReconciliationInput('tenant', $usage, 'execution', $request, 'second', 'operator', $this->now, $policy,
                    (object) ['source' => 'provider', 'reference' => 'first', 'reason' => 'usage unavailable']),
            ];
            foreach ($invalid as $input) {
                try {
                    $service->recordUnavailable($input);
                    $this->fail('Invalid reconciliation accepted.');
                } catch (Conflict|NotFound) {
                    $this->assertSame('pending', $pdo->query('SELECT billing_state FROM credit_request')->fetchColumn());
                    $this->assertCount(1, json_decode($pdo->query('SELECT reconciliation_record FROM credit_request')->fetchColumn(), true)['attempts']);
                }
            }
            $this->assertConsistent($pdo, '10.0000');
            $this->assertHeld($pdo, '2.0000');
        });
    }

    #[DataProvider('dialects')]
    public function testReconciliationMeasuredResolutionWinsAndPreservesAudit(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$usage, $request] = $this->pendingCancellation($pdo);
            $service = new Reconciliation($this->lock($pdo));
            $first = $this->reconciliation($usage, $request);
            $service->recordUnavailable($first);
            $audit = $pdo->query('SELECT reconciliation_record FROM credit_request')->fetchColumn();
            (new Outcomes($this->lock($pdo)))->record(new OutcomeInput('tenant', $usage, 'execution', $request, 'cancelled', 0, 0, 1000,
                (object) ['source' => 'provider'], 'provider-request'));
            $this->assertSame('billable', $service->recordUnavailable($first)['billingState']);
            $this->assertNull($service->recordUnavailable($first)['nextAttemptAt']);
            $this->now = '2026-10-10 12:02:00';
            try {
                $service->recordUnavailable($this->reconciliation($usage, $request, 'second'));
                $this->fail('Measured request waived.');
            } catch (Conflict) {}
            $this->assertSame($audit, $pdo->query('SELECT reconciliation_record FROM credit_request')->fetchColumn());
            $this->assertSame('0.1875', (new Settlements($this->lock($pdo), new Funding($this->lock($pdo))))
                ->settle('tenant', $usage, 'execution')['settledCredits']);
            $this->assertConsistent($pdo, '9.8125');
        });
    }

    #[DataProvider('dialects')]
    public function testReconciliationRejectsNonCancellationAndClosedOperations(string $dialect): void
    {
        foreach (['success', 'infrastructureFailure', 'inFlight', 'closed', 'deleted', 'wrong-reservation'] as $kind) {
            $this->database($dialect, function (PDO $pdo) use ($kind): void {
                [$usage, $request] = $this->pendingCancellation($pdo, in_array($kind, ['closed', 'deleted', 'wrong-reservation']) ? 'cancelled' : $kind);
                if ($kind === 'closed') {
                    $pdo->exec("UPDATE credit_usage SET state = 'settled'");
                }
                if ($kind === 'deleted') {
                    $pdo->exec('UPDATE credit_request SET deleted = TRUE');
                }
                if ($kind === 'wrong-reservation') {
                    $pdo->exec("UPDATE credit_reservation SET tenant_id = 'other'");
                }
                try {
                    (new Reconciliation($this->lock($pdo)))->recordUnavailable($this->reconciliation($usage, $request));
                    $this->fail('Ineligible request reconciled.');
                } catch (Conflict|NotFound) {
                    $this->assertNull($pdo->query('SELECT reconciliation_record FROM credit_request')->fetchColumn());
                }
            });
        }
    }

    #[DataProvider('dialects')]
    public function testReconciliationWriteFailureRollsBackAuditAndWaiver(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            [$usage, $request] = $this->pendingCancellation($pdo);
            $service = new Reconciliation($this->lock($pdo));
            $service->recordUnavailable($this->reconciliation($usage, $request));
            $before = $pdo->query('SELECT * FROM credit_request')->fetch(PDO::FETCH_ASSOC);
            $this->now = '2026-10-10 12:02:00';
            $input = $this->reconciliation($usage, $request, 'second');
            $pdo->exec("ALTER TABLE credit_request ADD CONSTRAINT injected_failure CHECK (billing_state = 'pending')");
            try {
                $service->recordUnavailable($input);
                $this->fail('Waiver write failure ignored.');
            } catch (PDOException) {
                $this->assertSame($before, $pdo->query('SELECT * FROM credit_request')->fetch(PDO::FETCH_ASSOC));
                $this->assertHeld($pdo, '2.0000');
                $this->assertConsistent($pdo, '10.0000');
            } finally {
                $pdo->exec('ALTER TABLE credit_request DROP ' . ($dialect === 'Mysql' ? 'CHECK' : 'CONSTRAINT') . ' injected_failure');
            }
            $this->assertSame('waived', $service->recordUnavailable($input)['billingState']);
        });
    }

    #[DataProvider('dialects')]
    public function testConcurrentReconciliationReplayAndMeasuredResolution(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            [$usage, $request] = $this->pendingCancellation($pdo);
            $results = $this->race($pdo, $dialect, [
                ['reconcile', $usage, 'first', $request], ['reconcile', $usage, 'first', $request],
            ]);
            $this->assertSame($results[0], $results[1]);
            $this->assertArrayHasKey('result', $results[0]);
            $this->now = '2026-10-10 12:02:00';
            $results = $this->race($pdo, $dialect, [
                ['reconcile', $usage, 'second', $request], ['cancelled-outcome', $usage, '1000', $request],
                ['settle', $usage, '', ''], ['expire', '', '', ''],
            ]);
            $this->assertCount(1, array_column([$results[0], $results[1]], 'result'));
            $this->assertSame([Conflict::class], array_values(array_column([$results[0], $results[1]], 'error')));
            $waived = $pdo->query('SELECT billing_state FROM credit_request')->fetchColumn() === 'waived';
            $receipt = (new Settlements($this->lock($pdo), new Funding($this->lock($pdo))))->settle('tenant', $usage, 'execution');
            $this->assertSame($waived ? '0.0000' : '0.1875', $receipt['settledCredits']);
            $this->assertConsistent($pdo, $waived ? '10.0000' : '9.8125');
            $this->assertHeld($pdo, '0.0000');
        });
    }

    #[DataProvider('dialects')]
    public function testConcurrentReconciliationDistinctAttemptsRespectCadence(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            [$usage, $request] = $this->pendingCancellation($pdo);
            $results = $this->race($pdo, $dialect, [
                ['reconcile', $usage, 'first', $request], ['reconcile', $usage, 'second', $request],
            ]);
            $this->assertCount(1, array_column($results, 'result'));
            $this->assertSame([Conflict::class], array_values(array_column($results, 'error')));
            $this->assertCount(1, json_decode($pdo->query('SELECT reconciliation_record FROM credit_request')->fetchColumn(), true)['attempts']);
            $this->assertHeld($pdo, '2.0000');
            $this->assertConsistent($pdo, '10.0000');
        });
    }

    #[DataProvider('dialects')]
    public function testConcurrentFinalWaiverSettlementExpirationAndRenewal(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input('monthly', '2', expires: '2026-10-11 00:00:00'));
            $usage = $reservations->reserve($this->admission())['usageId'];
            $request = (new Requests($this->lock($pdo), $funding))->authorize($this->request($usage))['requestId'];
            (new Outcomes($this->lock($pdo)))->record(new OutcomeInput('tenant', $usage, 'execution', $request, 'cancelled', null, null, null,
                (object) ['source' => 'provider']));
            (new Reconciliation($this->lock($pdo)))->recordUnavailable($this->reconciliation($usage, $request));
            $this->now = '2026-10-11 00:00:00';
            $results = $this->race($pdo, $dialect, [
                ['reconcile', $usage, 'second', $request], ['reconcile', $usage, 'second', $request],
                ['settle', $usage, '', ''], ['expire', '', '', ''],
                ['grant', 'renewal', '3', '2026-11-11 00:00:00'],
            ]);
            $this->assertArrayHasKey('result', $results[0]);
            $this->assertSame($results[0], $results[1]);
            $this->assertArrayHasKey('result', $results[3]);
            $this->assertArrayHasKey('result', $results[4]);
            $this->assertCount(2, json_decode($pdo->query('SELECT reconciliation_record FROM credit_request')->fetchColumn(), true)['attempts']);
            $settlement = new Settlements($this->lock($pdo), $funding);
            $receipt = $settlement->settle('tenant', $usage, 'execution');
            $this->assertSame('0.0000', $receipt['settledCredits']);
            $this->assertSame($receipt, $settlement->settle('tenant', $usage, 'execution'));
            $this->assertSame('-2.0000', $pdo->query("SELECT SUM(credits) FROM credit_transaction WHERE type = 'expiration'")->fetchColumn());
            $this->assertConsistent($pdo, '3.0000');
            $this->assertHeld($pdo, '0.0000');
        });
    }

    /** Hold the real parent row lock until both independent service calls are contending for it. */
    private function race(PDO $pdo, string $dialect, array $commands): array
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('Requires proc_open for separate-connection concurrency tests.');
        }
        $workers = [];
        $pdo->beginTransaction();
        $pdo->query("SELECT id FROM tenant WHERE id = 'tenant' FOR UPDATE")->fetchColumn();
        try {
            foreach ($commands as [$operation, $key, $credits, $expires]) {
                $process = proc_open([
                    PHP_BINARY, __DIR__ . '/fixtures/funding-worker.php', $dialect,
                    $operation, $key, $credits, $this->now, $expires,
                ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes];
                stream_set_timeout($pipes[1], 15);
                $this->assertSame("ready\n", fgets($pipes[1]));
            }
            foreach ($workers as [$process, $pipes]) {
                $read = [$pipes[1]];
                $write = $except = [];
                $this->assertSame(0, stream_select($read, $write, $except, 0, 150000),
                    'A service call completed while another connection owned the tenant lock.');
                $this->assertTrue(proc_get_status($process)['running']);
            }
            $pdo->commit();
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $line = fgets($pipes[1]);
                $this->assertIsString($line, 'Worker failed or timed out.');
                $results[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame('', stream_get_contents($pipes[2]));
            }
            return $results;
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($workers as [$process, $pipes]) {
                if (proc_get_status($process)['running']) {
                    proc_terminate($process);
                }
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                proc_close($process);
            }
        }
    }

    #[DataProvider('dialects')]
    public function testConcurrentFirstWalletFundingReplayAndConflictingInputs(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $results = $this->race($pdo, $dialect, [
                ['grant', 'payment-1', '10', ''], ['grant', 'payment-1', '10', ''],
            ]);
            $this->assertArrayHasKey('result', $results[0]);
            $this->assertSame($results[0], $results[1]);
            $this->assertConsistent($pdo, '10.0000');
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM tenant_credit_balance')->fetchColumn());
            $results = $this->race($pdo, $dialect, [
                ['grant', 'payment-2', '1', ''], ['grant', 'payment-2', '2', ''],
            ]);
            $this->assertCount(1, array_filter($results, static fn ($result) => isset($result['result'])));
            $this->assertSame([Conflict::class], array_values(array_column($results, 'error')));
            $winner = array_values(array_filter($results, static fn ($result) => isset($result['result'])))[0];
            $this->assertConsistent($pdo, $winner['result']['grantedCredits'] === '1.0000' ? '11.0000' : '12.0000');
        });
    }

    #[DataProvider('dialects')]
    public function testConcurrentDistinctGrantsCannotLoseUpdatesOrOverflow(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $results = $this->race($pdo, $dialect, [
                ['grant', 'first', '1', ''], ['grant', 'second', '2', ''],
            ]);
            $this->assertCount(2, array_column($results, 'result'));
            $this->assertConsistent($pdo, '3.0000');
            (new Funding($this->lock($pdo)))->grant($this->input('fill', '9999999996.9998'));
            $results = $this->race($pdo, $dialect, [
                ['grant', 'last-a', '0.0001', ''], ['grant', 'last-b', '0.0001', ''],
            ]);
            $this->assertCount(1, array_column($results, 'result'));
            $this->assertSame([OverflowException::class], array_values(array_column($results, 'error')));
            $this->assertConsistent($pdo, '9999999999.9999');
        });
    }

    #[DataProvider('dialects')]
    public function testConcurrentExpirationAndRenewalPostOnce(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            (new Funding($this->lock($pdo)))->grant($this->input('old', '10', expires: '2026-10-11 00:00:00'));
            $this->now = '2026-10-11 00:00:00';
            $results = $this->race($pdo, $dialect, [
                ['expire', '', '', ''], ['grant', 'renewal', '5', '2026-11-11 00:00:00'],
                ['grant', 'renewal', '5', '2026-11-11 00:00:00'],
            ]);
            $this->assertCount(3, array_column($results, 'result'));
            $this->assertSame($results[1], $results[2]);
            $this->assertConsistent($pdo, '5.0000');
            $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type = 'expiration'")->fetchColumn());
        });
    }
}
