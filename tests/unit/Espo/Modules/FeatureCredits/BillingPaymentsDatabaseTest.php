<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Doctrine\DBAL\Platforms\{MySQL80Platform, PostgreSQLPlatform};
use Espo\Core\Exceptions\{Conflict, Forbidden, ServiceUnavailable};
use Espo\Modules\FeatureCredits\Accounting\ConsistencyAudit;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SchemaTest.php';
require_once __DIR__ . '/BillingPaymentFixtures.php';

final class BillingPaymentsDatabaseTest extends TestCase
{
    use SchemaFixture;
    private string $now = '2026-10-12 12:00:00';

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
            $pdo->exec("INSERT INTO tenant (id) VALUES ('tenant'), ('other')");
            $fixture = new BillingPaymentFixtures($pdo, $this->now);
            $fixture->catalog->publish(BillingFixtures::definition(), 'admin'); $this->now = '2026-10-13 00:00:00';
            $test($pdo, $fixture);
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack(); $pdo->exec('DROP TABLE IF EXISTS tenant');
            foreach (array_reverse($schema->getTables()) as $table) $pdo->exec('DROP TABLE IF EXISTS ' . $platform->quoteIdentifier($table->getName()));
        }
    }

    private function audit(BillingPaymentFixtures $f, string $tenant = 'tenant'): void
    {
        $result = (new ConsistencyAudit($f->manager, $f->clock))->inspect($tenant);
        $this->assertTrue($result['consistent'], json_encode($result));
    }

    private function rows(PDO $pdo, string $table): int { return (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn(); }
    private function balance(PDO $pdo): string { return (string) $pdo->query("SELECT balance FROM tenant_credit_balance WHERE tenant_id='tenant'")->fetchColumn(); }

    #[DataProvider('dialects')]
    public function testPurchaseRequiresAuthoritativePaymentAndReplaysAcrossCountryCatalogAndSalesChanges(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, BillingPaymentFixtures $f): void {
            [$accepted, $charge] = $f->charge(); $id = $f->payment($charge, status: 'PENDING');
            $this->assertSame('pending', $f->read($id)['state']); $this->assertSame(0, $this->rows($pdo, 'credit_grant'));
            $f->payment($charge); $paid = $f->read($id);
            $evidence = $pdo->query('SELECT * FROM credit_billing_verification ORDER BY revision')->fetchAll(PDO::FETCH_ASSOC);
            $this->assertSame('funded', $paid['state']); $this->assertNull($paid['funding']['expiresAt']); $this->assertSame('50.0000', $this->balance($pdo));
            $f->accounts->changeCountry('tenant', 'us', 2, 'US', 'admin');
            $f->accounts->changeOverride('tenant', 'stripe', 3, 'stripe', 'admin');
            $f->connections->enabled = false;
            $definition = BillingFixtures::definition('withdrawn', '2026-10-14 00:00:00'); $definition->products = []; $definition->offers = [];
            $f->catalog->publish($definition, 'admin'); $this->now = '2026-12-01 00:00:00';
            $this->assertSame($paid, $f->read($id)); $this->assertSame(1, $this->rows($pdo, 'credit_grant'));
            $this->assertSame($evidence, $pdo->query('SELECT * FROM credit_billing_verification WHERE revision<=2 ORDER BY revision')->fetchAll(PDO::FETCH_ASSOC));
            $this->assertSame(3, $this->rows($pdo, 'credit_billing_verification'));
            $this->assertSame('completed', $f->accounts->readObligation('tenant', $accepted['obligationId'])['state']);
            $this->assertSame(2, count(array_filter($f->transport->calls, static fn ($c) => $c[2] === 'POST')));
            $this->audit($f);
        });
    }

    #[DataProvider('dialects')]
    public function testRenewalsAndDifferentPaymentIdsConvergeOnOriginalPeriodWithPurchaseSurviving(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, BillingPaymentFixtures $f): void {
            [, $purchase] = $f->charge(); $f->read($f->payment($purchase));
            [$accepted, $charge] = $f->charge(true, key: 'subscription');
            $id = $f->payment($charge); $this->assertSame('awaiting_period', $f->read($id, 'sub_forged')['state']);
            $this->assertSame(1, $this->rows($pdo, 'credit_grant'));
            $this->now = '2026-10-20 12:00:00'; $first = $f->read($id);
            $this->assertSame('2026-11-15 03:00:00', $first['funding']['expiresAt']); $this->assertSame('150.0000', $this->balance($pdo));
            $otherId = $f->payment($charge, 'pay_alternate'); $alternate = $f->read($otherId);
            $this->assertSame($first['funding'], $alternate['funding']); $this->assertSame($first['periodId'], $alternate['periodId']);
            $next = $f->payment($charge, 'pay_next', '2026-11-15'); $this->now = '2026-11-20 00:00:00';
            $second = $f->read($next); $this->assertNotSame($first['periodId'], $second['periodId']);
            $this->assertSame('150.0000', $this->balance($pdo)); $this->assertSame(3, $this->rows($pdo, 'credit_grant'));
            $this->assertSame('active', $f->accounts->readObligation('tenant', $accepted['obligationId'])['state']);
            $this->assertSame('2026-12-15 03:00:00', $second['funding']['expiresAt']); $this->audit($f);
        });
    }

    #[DataProvider('dialects')]
    public function testEndedPeriodGrantAndExpirationAreAtomicAtExactBoundary(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, BillingPaymentFixtures $f): void {
            [, $charge] = $f->charge(true); $id = $f->payment($charge); $this->now = '2026-11-15 03:00:00';
            $result = $f->read($id); $this->assertTrue($result['endedPeriod']); $this->assertSame('0.0000', $this->balance($pdo));
            $this->assertSame('0.0000', $pdo->query('SELECT remaining_credits FROM credit_grant')->fetchColumn());
            $this->assertSame(2, $this->rows($pdo, 'credit_transaction')); $this->assertSame($result, $f->read($id)); $this->audit($f);
        });
    }

    #[DataProvider('dialects')]
    public function testAuthenticatedInboxIgnoresClaimedPaidAndDeduplicatesWithoutSynchronousProviderIo(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, BillingPaymentFixtures $f): void {
            [, $charge] = $f->charge(); $id = $f->payment($charge, status: 'PENDING');
            $data = ['id' => 'evt_Abc&123', 'event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => $id, 'status' => 'RECEIVED', 'value' => '999999', 'customer' => 'cus_forged']];
            $raw = json_encode($data); $calls = count($f->transport->calls);
            try { $f->events->receive('asaas-br', 'wrong', $raw); $this->fail('Bad token accepted.'); } catch (Forbidden) {}
            $this->assertSame(0, $this->rows($pdo, 'credit_billing_event'));
            $received = $f->events->receive('asaas-br', 'fixture-webhook-token', $raw);
            $this->assertSame($received, $f->events->receive('asaas-br', 'fixture-webhook-token', $raw));
            $this->assertSame($calls, count($f->transport->calls)); $this->assertSame(1, $this->rows($pdo, 'credit_billing_event'));
            $this->assertSame(1, $f->events->processBatch()); $this->assertSame(0, $this->rows($pdo, 'credit_grant'));
            $this->assertSame(0, $f->events->processBatch());
            $f->payment($charge); $data['id'] = 'evt_second';
            $f->events->receive('asaas-br', 'fixture-webhook-token', json_encode($data)); $f->events->processBatch();
            $this->assertSame('50.0000', $this->balance($pdo));
            $data['payment']['id'] = 'pay_changed';
            try { $f->events->receive('asaas-br', 'fixture-webhook-token', json_encode($data)); $this->fail('Event ID reused.'); } catch (Conflict) {}
            $this->assertSame(1, $this->rows($pdo, 'credit_grant')); $this->audit($f);
        });
    }

    #[DataProvider('dialects')]
    public function testReviewAndUnresolvedEventsRemainRecoverableAndNeverRepeatPost(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, BillingPaymentFixtures $f): void {
            [, $charge] = $f->charge(); $id = $f->payment($charge, status: 'CONFIRMED');
            $raw = json_encode(['id' => 'evt_review', 'event' => 'PAYMENT_CONFIRMED', 'payment' => ['id' => $id]]);
            $f->events->receive('asaas-br', 'fixture-webhook-token', $raw);
            $f->transport->mode = 'lost'; $f->events->processBatch();
            $this->assertSame('lookup_unresolved', $pdo->query('SELECT last_reason FROM credit_billing_event')->fetchColumn());
            $f->transport->mode = 'success'; $this->now = '2026-10-13 00:02:00'; $f->events->processBatch();
            $this->assertSame('review_required', $pdo->query('SELECT last_reason FROM credit_billing_event')->fetchColumn());
            $this->assertSame(0, $this->rows($pdo, 'credit_grant')); $f->payment($charge);
            $f->events->receive('asaas-br', 'fixture-webhook-token', json_encode(['id' => 'evt_received', 'event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => $id]]));
            $this->now = '2026-10-13 00:04:00'; $f->events->processBatch();
            $this->assertSame(1, $this->rows($pdo, 'credit_grant')); $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM credit_billing_event WHERE state='processed'")->fetchColumn());
            $this->assertSame(2, count(array_filter($f->transport->calls, static fn ($c) => $c[2] === 'POST'))); $this->audit($f);
        });
    }

    #[DataProvider('dialects')]
    public function testProviderIntegrationScopeAndCorruptOrChangedBindingsFailClosed(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, BillingPaymentFixtures $f): void {
            [, $charge] = $f->charge(true); $id = $f->payment($charge); $this->now = '2026-10-20 00:00:00';
            $first = $f->read($id); $this->assertSame(1, $this->rows($pdo, 'credit_grant'));
            $f->payment($charge, $id, '2026-11-15');
            try { $f->read($id); $this->fail('Payment changed original period.'); } catch (Conflict) {}
            $f->payment($charge, $id);
            $f->connections->environment = 'production';
            try { $f->read($id); $this->fail('Changed environment read allowed.'); } catch (Conflict) {}
            $f->connections->environment = 'sandbox';
            $row = $pdo->query('SELECT * FROM credit_billing_payment')->fetch(PDO::FETCH_ASSOC);
            foreach (['tenant_id' => 'other', 'creation_id' => 'bad', 'binding_hash' => str_repeat('a', 64)] as $field => $value) {
                $pdo->prepare("UPDATE credit_billing_payment SET $field=? WHERE id=?")->execute([$value, $row['id']]);
                try { $f->read($id); $this->fail('Corrupt payment read allowed.'); } catch (Conflict) {}
                $pdo->prepare("UPDATE credit_billing_payment SET $field=? WHERE id=?")->execute([$row[$field], $row['id']]);
            }
            $this->assertSame($first, $f->read($id)); $this->assertSame(1, $this->rows($pdo, 'credit_grant')); $this->audit($f);
        });
    }

    #[DataProvider('dialects')]
    public function testAllFinancialAndBillingWriteFailuresRollbackTogether(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, BillingPaymentFixtures $f) use ($dialect): void {
            [, $charge] = $f->charge(true); $id = $f->payment($charge); $this->now = '2026-12-01 00:00:00';
            foreach (['credit_billing_period' => 'receipt IS NULL', 'credit_billing_obligation' => "state<>'active'",
                'credit_billing_payment' => 'identity_hash IS NULL', 'credit_billing_verification' => '1=0',
                'credit_transaction' => "type<>'expiration'", 'credit_grant' => '1=0'] as $table => $condition) {
                $pdo->exec("ALTER TABLE $table ADD CONSTRAINT injected_failure CHECK ($condition)");
                try { $f->read($id); $this->fail('Partial payment funding committed.'); } catch (PDOException) {}
                finally { $pdo->exec("ALTER TABLE $table DROP " . self::dropCheckKeyword($pdo) . ' injected_failure'); }
                foreach (['credit_grant', 'credit_transaction', 'credit_billing_period', 'credit_billing_verification', 'tenant_credit_balance'] as $empty) $this->assertSame(0, $this->rows($pdo, $empty), $empty);
            }
            $this->assertSame('funded', $f->read($id)['state']); $this->assertSame('0.0000', $this->balance($pdo)); $this->audit($f);
        });
    }

    #[DataProvider('dialects')]
    public function testNewerAuthoritativeReadFencesOlderPaidResponse(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, BillingPaymentFixtures $f): void {
            [, $charge] = $f->charge(); $id = $f->payment($charge);
            $f->transport->onSend = function () use ($pdo, $f, $charge, $id): void {
                $this->assertFalse($pdo->inTransaction()); $f->transport->onSend = null;
                $f->payment($charge, status: 'REFUNDED'); $this->assertSame('reversed_unfunded', $f->read($id)['state']);
                $f->payment($charge); // Simulates the older in-flight paid response arriving last.
            };
            $this->assertSame('superseded', $f->read($id)['state']); $this->assertSame(0, $this->rows($pdo, 'credit_grant'));
            $this->assertSame(['reversed_unfunded', 'superseded'], $pdo->query('SELECT state FROM credit_billing_verification ORDER BY revision DESC')->fetchAll(PDO::FETCH_COLUMN));
            $this->assertSame('reversed_unfunded', $f->read($id)['state']);
            $this->assertSame(0, $this->rows($pdo, 'credit_grant'));
        });
    }

    public static function races(): iterable
    {
        foreach (self::dialects() as $name => [$dialect]) foreach (['same-payment', 'same-period', 'event', 'refund', 'refund-event'] as $race) yield "$name $race" => [$dialect, $race];
    }

    #[DataProvider('races')]
    public function testIndependentProcessesConvergeOnOneGrant(string $dialect, string $race): void
    {
        $this->database($dialect, function (PDO $pdo, BillingPaymentFixtures $f) use ($dialect, $race): void {
            [, $charge] = $f->charge($race === 'same-period'); $first = $f->payment($charge);
            $second = $race === 'same-period' ? $f->payment($charge, 'pay_second') : $first;
            $refund = in_array($race, ['refund', 'refund-event'], true);
            $event = in_array($race, ['event', 'refund-event'], true);
            if ($refund) { $f->read($first); $f->payment($charge, status: 'REFUNDED'); }
            $this->now = '2026-10-20 00:00:00'; $workers = [];
            if ($event) $f->events->receive('asaas-br', 'fixture-webhook-token', json_encode([
                'id' => 'evt_race', 'event' => $refund ? 'PAYMENT_REFUNDED' : 'PAYMENT_RECEIVED', 'payment' => ['id' => $first],
            ]));
            $pdo->beginTransaction(); $pdo->query("SELECT id FROM tenant WHERE id='tenant' FOR UPDATE");
            try {
                foreach ([$first, $second] as $id) {
                    $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/billing-payment-worker.php', $dialect, $id, $this->now, $event ? 'event' : 'normal'],
                        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                    $this->assertIsResource($process); $workers[] = [$process, $pipes]; stream_set_timeout($pipes[1], 15);
                    fwrite($pipes[0], json_encode($f->transport->objects) . "\n"); fflush($pipes[0]);
                    $this->assertSame("ready\n", fgets($pipes[1]));
                }
                foreach ($event ? [] : $workers as [$process, $pipes]) {
                    $read = [$pipes[1]]; $write = $except = [];
                    $this->assertSame(0, stream_select($read, $write, $except, 0, 150000)); $this->assertTrue(proc_get_status($process)['running']);
                }
                $pdo->commit(); $results = [];
                foreach ($workers as [$process, $pipes]) {
                    $results[] = json_decode(fgets($pipes[1]), true, 32, JSON_THROW_ON_ERROR); $this->assertSame('', stream_get_contents($pipes[2]));
                }
                $this->assertCount(2, array_column($results, 'result'), json_encode($results));
                if ($event) {
                    $this->assertSame(1, array_sum(array_column($results, 'result')));
                    $this->assertSame(1, array_sum(array_column($results, 'reads')));
                } else $this->assertContains($refund ? 'reversed' : 'funded', array_column(array_column($results, 'result'), 'state'));
                $this->assertSame(1, $this->rows($pdo, 'credit_grant')); $this->assertSame(1, $this->rows($pdo, 'credit_billing_period'));
                $this->assertSame($refund ? '0.0000' : ($race === 'same-period' ? '100.0000' : '50.0000'), $this->balance($pdo));
                if ($refund) $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type='purchaseReversal'")->fetchColumn());
                $this->audit($f);
            } finally {
                if ($pdo->inTransaction()) $pdo->rollBack();
                foreach ($workers as [$process, $pipes]) { if (proc_get_status($process)['running']) proc_terminate($process); foreach ($pipes as $pipe) fclose($pipe); proc_close($process); }
            }
        });
    }

    #[DataProvider('dialects')]
    public function testKilledPaymentLookupRecoversOriginalClaimWithoutCreatingCharges(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, BillingPaymentFixtures $f) use ($dialect): void {
            [, $charge] = $f->charge(); $id = $f->payment($charge);
            $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/billing-payment-worker.php', $dialect, $id, $this->now, 'interrupt'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process); stream_set_timeout($pipes[1], 15);
            try {
                fwrite($pipes[0], json_encode($f->transport->objects) . "\n"); fflush($pipes[0]);
                $this->assertSame("ready\n", fgets($pipes[1])); $this->assertSame("lookup\n", fgets($pipes[1]));
                $this->assertSame(1, $this->rows($pdo, 'credit_billing_payment')); $this->assertSame(0, $this->rows($pdo, 'credit_grant'));
                $this->assertTrue(proc_terminate($process, 9));
                $this->assertSame('funded', $f->read($id)['state']); $this->assertSame('50.0000', $this->balance($pdo));
                $this->assertSame(2, count(array_filter($f->transport->calls, static fn ($c) => $c[2] === 'POST'))); $this->audit($f);
            } finally {
                if (proc_get_status($process)['running']) proc_terminate($process, 9);
                foreach ($pipes as $pipe) fclose($pipe); proc_close($process);
            }
        });
    }

    #[DataProvider('dialects')]
    public function testSameExternalPaymentInAnotherIntegrationCannotCrossTenants(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, BillingPaymentFixtures $f): void {
            [, $charge] = $f->charge(); $id = $f->payment($charge); $first = $f->read($id);
            $other = new BillingPaymentFixtures($pdo, $this->now); $other->providers->integration = 'asaas-other';
            [, $otherCharge] = $other->charge(tenant: 'other'); $otherId = $other->payment($otherCharge);
            $this->assertSame($id, $otherId);
            $second = $other->read($otherId, integration: 'asaas-other');
            $this->assertNotSame($first['paymentId'], $second['paymentId']); $this->assertNotSame($first['funding']['grantId'], $second['funding']['grantId']);
            $this->assertSame(2, $this->rows($pdo, 'credit_billing_payment')); $this->assertSame('50.0000', $this->balance($pdo));
            $this->assertSame('50.0000', $pdo->query("SELECT balance FROM tenant_credit_balance WHERE tenant_id='other'")->fetchColumn());
            $this->audit($f); $this->audit($f, 'other');
        });
    }

    #[DataProvider('dialects')]
    public function testUncertainCreationEventWaitsForLookupOnlyBindingRecovery(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, BillingPaymentFixtures $f): void {
            $f->transport->onSend = function () use ($pdo, $f): void {
                $this->assertFalse($pdo->inTransaction());
                if (count($f->transport->calls) === 2) $f->transport->mode = 'lost';
            };
            [, $charge] = $f->charge(); $this->assertSame('unknown', $charge['state']);
            $row = $pdo->query("SELECT * FROM credit_billing_creation WHERE kind='payment'")->fetch(PDO::FETCH_ASSOC);
            $object = $f->transport->objects[$row['external_reference']]; $id = $object['id'];
            $f->transport->objects[$row['external_reference']] += ['object' => 'payment', 'status' => 'RECEIVED', 'originalDueDate' => '2026-10-15'];
            $raw = json_encode(['id' => 'evt_early', 'event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => $id]]);
            $f->events->receive('asaas-br', 'fixture-webhook-token', $raw); $f->transport->mode = 'success';
            $f->events->processBatch(); $this->assertSame('unmatched', $pdo->query('SELECT last_reason FROM credit_billing_event')->fetchColumn());
            $this->assertSame(0, $this->rows($pdo, 'credit_grant'));
            $f->creation->reconcile('tenant', $charge['creationId']); $this->now = '2026-10-13 00:02:00';
            $f->events->processBatch(); $this->assertSame('50.0000', $this->balance($pdo));
            $this->assertSame(2, count(array_filter($f->transport->calls, static fn ($c) => $c[2] === 'POST'))); $this->audit($f);
        });
    }

    #[DataProvider('dialects')]
    public function testPaymentCompletionUnpinsCountryRouteAtomically(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, BillingPaymentFixtures $f) use ($dialect): void {
            [, $charge] = $f->charge(); $id = $f->payment($charge);
            $f->accounts->changeCountry('tenant', 'us', 1, 'US', 'admin');
            $this->assertSame('asaas', $f->accounts->context('tenant')['effectiveProvider']);
            $pdo->exec("ALTER TABLE tenant_billing_assignment ADD CONSTRAINT injected_failure CHECK (effective_provider='asaas')");
            try { $f->read($id); $this->fail('Funding committed without its route change.'); } catch (PDOException) {}
            finally { $pdo->exec('ALTER TABLE tenant_billing_assignment DROP ' . self::dropCheckKeyword($pdo) . ' injected_failure'); }
            $this->assertSame(0, $this->rows($pdo, 'credit_grant')); $f->read($id);
            $this->assertSame('stripe', $f->accounts->context('tenant')['effectiveProvider']);
            $this->assertSame('stripe', $pdo->query('SELECT effective_provider FROM tenant_billing_assignment')->fetchColumn());
            $this->assertSame('50.0000', $this->balance($pdo)); $this->audit($f);
        });
    }

    #[DataProvider('dialects')]
    public function testZeroAllowancePaidPeriodHasDurableReceiptWithoutZeroValueGrant(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, BillingPaymentFixtures $f): void {
            $definition = BillingFixtures::definition('zero', '2026-10-14 00:00:00');
            $definition->products[0]->productVersion = 'v2'; $definition->products[0]->credits = '0';
            foreach ([$definition->offers[0], $definition->offers[1]] as $offer) { $offer->version = 'v2'; $offer->productVersion = 'v2'; }
            $f->catalog->publish($definition, 'admin'); $this->now = '2026-10-14 00:00:00';
            [, $charge] = $f->charge(true); $id = $f->payment($charge); $this->now = '2026-10-15 03:00:00';
            $result = $f->read($id); $this->assertSame('funded', $result['state']); $this->assertNull($result['funding']['grantId']);
            $this->assertSame('0.0000', $result['funding']['grantedCredits']); $this->assertSame($result, $f->read($id));
            $this->assertSame(0, $this->rows($pdo, 'credit_grant')); $this->assertSame(0, $this->rows($pdo, 'credit_transaction'));
            $this->assertSame(1, $this->rows($pdo, 'credit_billing_period')); $this->audit($f);
        });
    }

    #[DataProvider('dialects')]
    public function testWebhookControllerPersistsBeforeAcknowledgementAndRejectsInvalidBodies(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, BillingPaymentFixtures $f): void {
            $controller = new \Espo\Modules\FeatureCredits\Controllers\CreditAsaasEvents($f->events);
            $request = $this->createStub(\Espo\Core\Api\Request::class);
            $request->method('getRouteParam')->with('integrationId')->willReturn('asaas-br');
            $request->method('getHeader')->with('asaas-access-token')->willReturn('fixture-webhook-token');
            $request->method('getBodyContents')->willReturn('{"id":"evt_unknown","event":"PAYMENT_RECEIVED","payment":{"id":"pay_unknown"}}');
            $this->assertTrue($controller->postActionReceive($request)->accepted);
            $this->assertSame(1, $this->rows($pdo, 'credit_billing_event')); $this->assertSame([], $f->transport->calls);
            foreach ([null, false, 'true'] as $disabled) {
                $config = $this->createStub(\Espo\Core\Utils\Config::class); $config->method('get')->willReturn($disabled);
                (new \Espo\Modules\FeatureCredits\Jobs\ReconcileBillingEvents($f->events, $config))->run();
                $this->assertSame([], $f->transport->calls);
            }
            foreach (['{}', 'null', str_repeat(' ', 1048577), '{"id":"evt_bad","event":"PAYMENT_RECEIVED","payment":{"id":"../../bad"}}'] as $body) {
                try { $f->events->receive('asaas-br', 'fixture-webhook-token', $body); $this->fail('Invalid body accepted.'); }
                catch (\Espo\Core\Exceptions\BadRequest) { $this->assertSame(1, $this->rows($pdo, 'credit_billing_event')); }
            }
            $pdo->exec("UPDATE credit_billing_event SET integration_id='other'");
            $f->events->processBatch(); $this->assertSame([], $f->transport->calls);
            $this->assertSame('verification_conflict', $pdo->query('SELECT last_reason FROM credit_billing_event')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testInterruptedInboxLeaseCanBeRetakenWithoutEarlyDuplicateLookup(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, BillingPaymentFixtures $f): void {
            [, $charge] = $f->charge(); $id = $f->payment($charge);
            $f->events->receive('asaas-br', 'fixture-webhook-token', json_encode(['id' => 'evt_interrupt', 'event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => $id]]));
            $f->transport->onSend = function () use ($pdo): void {
                $this->assertFalse($pdo->inTransaction()); throw new \RuntimeException('Interrupted inbox executor.');
            };
            try { $f->events->processBatch(); $this->fail('Interruption did not escape.'); } catch (\RuntimeException) {}
            $this->assertNotNull($pdo->query('SELECT lease_token FROM credit_billing_event')->fetchColumn());
            $this->assertSame(0, $f->events->processBatch()); $this->assertSame(0, $this->rows($pdo, 'credit_grant'));
            $this->now = '2026-10-13 00:02:00'; $f->transport->onSend = null;
            $this->assertSame(1, $f->events->processBatch()); $this->assertSame('50.0000', $this->balance($pdo));
            $this->assertNull($pdo->query('SELECT lease_token FROM credit_billing_event')->fetchColumn()); $this->audit($f);
        });
    }
}
