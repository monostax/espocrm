<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Doctrine\DBAL\Platforms\{MySQL80Platform, PostgreSQLPlatform};
use Espo\Core\Api\{Request, Response};
use Espo\Core\Exceptions\{Conflict, ServiceUnavailable};
use Espo\Entities\User;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Billing\{Catalog, CatalogDefinition};
use Espo\Modules\FeatureCredits\Controllers\CreditBillingConfiguration;
use Espo\ORM\EntityManager;
use InvalidArgumentException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SchemaTest.php';
require_once __DIR__ . '/BillingFixtures.php';

final class BillingCatalogDatabaseTest extends TestCase
{
    use SchemaFixture;

    private string $now = '2026-10-12 12:00:00';
    private const TABLES = ['credit_billing_catalog', 'credit_billing_product', 'credit_market_offer'];

    public static function dialects(): iterable
    {
        yield 'MySQL' => ['Mysql'];
        yield 'PostgreSQL' => ['Postgresql'];
    }

    private function database(string $dialect, callable $test): void
    {
        $dsn = getenv('FEATURE_CREDITS_' . strtoupper($dialect) . '_DSN');
        if (!$dsn) $this->markTestSkipped('Requires an empty feature_credits_schema_test database.');
        $pdo = new PDO($dsn, 'credits_test', 'credits_test', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->assertSame('feature_credits_schema_test', $pdo->query($dialect === 'Mysql' ?
            'SELECT DATABASE()' : 'SELECT current_database()')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query($dialect === 'Mysql' ?
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()' :
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public'")->fetchColumn(),
            'Refusing to modify a nonempty database.');
        $schema = $this->schema($dialect);
        $platform = $dialect === 'Mysql' ? new MySQL80Platform() : new PostgreSQLPlatform();
        try {
            foreach ($schema->toSql($platform) as $sql) $pdo->exec($sql);
            $test($pdo);
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach (array_reverse($schema->getTables()) as $table) {
                $pdo->exec('DROP TABLE IF EXISTS ' . $platform->quoteIdentifier($table->getName()));
            }
        }
    }

    private function catalog(PDO $pdo, ?Clock $clock = null): Catalog
    {
        $manager = $this->createStub(EntityManager::class); $manager->method('getPDO')->willReturn($pdo);
        if ($clock === null) {
            $clock = $this->createStub(Clock::class); $clock->method('now')->willReturnCallback(fn () => $this->now);
        }
        return new Catalog($manager, $clock);
    }

    private function snapshot(PDO $pdo): array
    {
        return array_map(static fn ($table) => $pdo->query("SELECT * FROM $table ORDER BY id")->fetchAll(PDO::FETCH_ASSOC), self::TABLES);
    }

    #[DataProvider('dialects')]
    public function testApprovedLaunchArtifactPublishesBothMarketsOnceWithoutFunding(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $input = json_decode(file_get_contents('custom/Espo/Modules/FeatureCredits/Resources/billing/launch-2026-10-12.json'), false, 32, JSON_THROW_ON_ERROR);
            $input->effectiveFrom = '2026-10-13 00:00:00';
            $catalog = $this->catalog($pdo);
            $receipt = $catalog->publish($input, 'admin');
            $snapshot = $this->snapshot($pdo);
            $this->assertSame($receipt, $catalog->publish($input, 'admin'));
            $this->assertSame($snapshot, $this->snapshot($pdo));
            $this->now = $input->effectiveFrom;
            $current = $catalog->current();
            $pdo->beginTransaction();
            $this->assertSame($current->id, $catalog->currentForAcceptance()->id);
            $pdo->commit();
            $this->assertCount(3, $current->products);
            $this->assertCount(6, $current->offers);
            $this->assertSame('997.00', $current->offers['monthly-2000-br']->price);
            $this->assertSame('199.00', $current->offers['monthly-2000-us']->price);
            $this->assertSame('15000.0000', $current->offers['monthly-15000-us']->credits);
            $this->assertSame('999.00', $current->offers['monthly-15000-us']->price);
            $this->assertSame($receipt['catalog'], $catalog->resolve($current->id)->receipt());
            $packages = json_decode(file_get_contents('custom/Espo/Modules/FeatureCredits/Resources/billing/launch-2026-10-13.json'), false, 32, JSON_THROW_ON_ERROR);
            $packages->effectiveFrom = '2026-10-14 00:00:00';
            $next = $catalog->publish($packages, 'admin');
            $this->assertSame($next, $catalog->publish($packages, 'admin'));
            $this->now = $packages->effectiveFrom;
            $updated = $catalog->current();
            $this->assertCount(9, $updated->offers);
            foreach ($current->offers as $id => $terms) $this->assertSame($terms->termsId, $updated->offers[$id]->termsId);
            $this->assertSame($receipt['catalog'], $catalog->resolve($current->id)->receipt());
            $this->assertSame('299.00', $updated->offers['package-500-br']->price);
            $this->assertSame('2000.0000', $updated->offers['package-2000-br']->credits);
            $this->assertSame('2499.00', $updated->offers['package-5000-br']->price);
            $this->assertNull($updated->offers['package-5000-br']->interval);
            foreach (['credit_billing_obligation', 'credit_billing_creation', 'credit_billing_payment', 'credit_grant', 'credit_transaction'] as $table) {
                $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn());
            }
        });
    }

    #[DataProvider('dialects')]
    public function testAuthorizedPublicationReplayHistoricalSelectionAndSalesWithdrawal(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $catalog = $this->catalog($pdo);
            $user = $this->createStub(User::class); $user->method('isAdmin')->willReturn(true);
            $user->method('getId')->willReturn('platform-admin');
            $controller = new CreditBillingConfiguration($user, $catalog);
            $input = BillingFixtures::definition();
            $request = $this->createStub(Request::class);
            $request->method('getBodyContents')->willReturn(json_encode($input, JSON_THROW_ON_ERROR));
            $response = $this->createStub(Response::class);
            $first = (array) $controller->postActionPublish($request, $response);
            $saved = $this->snapshot($pdo);
            $this->assertSame($first, $catalog->publish($input, 'other-admin'));
            $this->assertSame($saved, $this->snapshot($pdo));
            foreach (self::TABLES as $table) {
                $this->assertSame(['platform-admin'], $pdo->query("SELECT DISTINCT created_by_id FROM $table")->fetchAll(PDO::FETCH_COLUMN));
                foreach ($pdo->query("SELECT id FROM $table")->fetchAll(PDO::FETCH_COLUMN) as $id) $this->assertSame(17, strlen($id));
            }
            try { $catalog->current(); $this->fail('Future catalog selected.'); } catch (ServiceUnavailable) {}
            $next = BillingFixtures::definition('v2', '2026-10-14 00:00:00');
            $next->offers[0]->version = 'v2'; $next->offers[0]->price = '60';
            $second = $catalog->publish($next, 'admin');
            $withdrawn = BillingFixtures::definition('v3', '2026-10-15 00:00:00');
            $withdrawn->products = $withdrawn->offers = [];
            $third = $catalog->publish($withdrawn, 'admin');
            foreach ([$first, $second, $third] as $receipt) {
                $this->now = $receipt['catalog']['effectiveFrom'];
                $read = $this->createStub(Request::class); $read->method('getQueryParams')->willReturn([]);
                $this->assertEquals((object) $receipt['catalog'], $controller->getActionRead($read, $response));
                $this->assertSame($first['catalog'], $catalog->resolve($first['catalog']['catalogId'])->receipt());
            }
            $this->assertSame([], $catalog->current()->offers);
            $read = $this->createStub(Request::class); $read->method('getQueryParams')->willReturn(['catalogId' => $first['catalog']['catalogId']]);
            $this->assertEquals((object) $first['catalog'], $controller->getActionRead($read, $response));
            $this->assertSame($first, $catalog->publish($input, 'admin'));
            $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM credit_billing_product')->fetchColumn());
            $this->assertSame(4, (int) $pdo->query('SELECT COUNT(*) FROM credit_market_offer')->fetchColumn());
            foreach (['tenant_credit_balance', 'credit_grant', 'credit_transaction', 'credit_reservation', 'credit_usage'] as $table) {
                $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn());
            }
        });
    }

    #[DataProvider('dialects')]
    public function testCommercialVersionsCannotChangeAcrossCatalogsIncludingAfterWithdrawal(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $catalog = $this->catalog($pdo);
            $first = $catalog->publish(BillingFixtures::definition(), 'admin');
            $empty = BillingFixtures::definition('empty', '2026-10-14 00:00:00'); $empty->products = $empty->offers = [];
            $catalog->publish($empty, 'admin');
            $saved = $this->snapshot($pdo);
            foreach (['catalog', 'instant', 'product-credits', 'product-name', 'offer-price', 'offer-market', 'backdate'] as $kind) {
                $bad = BillingFixtures::definition('v3', '2026-10-15 00:00:00');
                switch ($kind) {
                    case 'catalog': $bad->version = 'v1'; break;
                    case 'instant': $bad->effectiveFrom = '2026-10-13 00:00:00'; break;
                    case 'product-credits': $bad->products[0]->credits = '999'; break;
                    case 'product-name': $bad->products[0]->name = 'Changed'; break;
                    case 'offer-price': $bad->offers[0]->price = '99'; break;
                    case 'offer-market': $bad->offers[0]->countries = ['US']; break;
                    case 'backdate': $bad->effectiveFrom = $this->now; break;
                }
                try { $catalog->publish($bad, 'admin'); $this->fail("Conflicting $kind allowed."); }
                catch (Conflict) {
                    $this->assertFalse($pdo->inTransaction());
                    $this->assertSame($saved, $this->snapshot($pdo));
                }
            }
            $changed = BillingFixtures::definition('v3', '2026-10-15 00:00:00');
            $changed->products[0]->productVersion = 'v2'; $changed->products[0]->credits = '200';
            foreach (array_slice($changed->offers, 0, 2) as $offer) { $offer->version = 'v2'; $offer->productVersion = 'v2'; }
            $catalog->publish($changed, 'admin');
            $this->assertSame('100.0000', $catalog->resolve($first['catalog']['catalogId'])->offers['monthly-br']->credits);
            $this->now = '2026-10-15 00:00:00';
            $this->assertSame('200.0000', $catalog->current()->offers['monthly-br']->credits);
        });
    }

    #[DataProvider('dialects')]
    public function testOutOfOrderProspectivePublicationHasExactSelectionBoundaries(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $catalog = $this->catalog($pdo);
            $third = $catalog->publish(BillingFixtures::definition('v3', '2026-10-15 00:00:00'), 'admin');
            $first = $catalog->publish(BillingFixtures::definition(), 'admin');
            $second = $catalog->publish(BillingFixtures::definition('v2', '2026-10-14 00:00:00'), 'admin');
            foreach ([['2026-10-13 00:00:00', $first], ['2026-10-13 23:59:59', $first],
                ['2026-10-14 00:00:00', $second], ['2026-10-15 00:00:00', $third]] as [$now, $receipt]) {
                $this->now = $now;
                $this->assertSame($receipt['catalog'], $catalog->current()->receipt());
            }
        });
    }

    #[DataProvider('dialects')]
    public function testPrivateOfferPublicationRequiresAnActiveTenantAndScopeVersionsAreImmutable(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $pdo->exec('CREATE TABLE tenant (id VARCHAR(24) PRIMARY KEY, deleted BOOLEAN NOT NULL DEFAULT FALSE)');
            try {
                $pdo->exec("INSERT INTO tenant (id,deleted) VALUES ('tenant',FALSE),('other',FALSE),('deleted',TRUE),('123',FALSE)");
                $catalog = $this->catalog($pdo); $input = BillingFixtures::definition();
                $input->offers[0]->tenantId = 'tenant';
                $first = $catalog->publish($input, 'admin');
                $this->assertSame($first, $catalog->publish($input, 'admin'));
                $this->now = $input->effectiveFrom;
                $this->assertSame('tenant', $catalog->current()->offers['monthly-br']->tenantId);
                $before = $this->snapshot($pdo);
                foreach (['other', null] as $tenantId) {
                    $changed = BillingFixtures::definition('changed-private', '2026-10-14 00:00:00');
                    if ($tenantId !== null) $changed->offers[0]->tenantId = $tenantId;
                    try { $catalog->publish($changed, 'admin'); $this->fail('An existing offer version changed its private audience.'); }
                    catch (Conflict) { $this->assertSame($before, $this->snapshot($pdo)); }
                }
                foreach (['absent', 'deleted', 'TENANT'] as $tenantId) {
                    $invalid = BillingFixtures::definition('invalid-private', '2026-10-14 00:00:00');
                    $invalid->offers[0]->version = 'v2'; $invalid->offers[0]->tenantId = $tenantId;
                    try { $catalog->publish($invalid, 'admin'); $this->fail('An unavailable private tenant was published.'); }
                    catch (InvalidArgumentException) {
                        $this->assertFalse($pdo->inTransaction());
                        $this->assertSame($before, $this->snapshot($pdo));
                    }
                }
                $next = BillingFixtures::definition('private-v2', '2026-10-14 00:00:00');
                $next->offers[0]->version = 'v2'; $next->offers[0]->tenantId = '123';
                $catalog->publish($next, 'admin');
                $this->assertSame($first['catalog'], $catalog->resolve($first['catalog']['catalogId'])->receipt());
            } finally { $pdo->exec('DROP TABLE tenant'); }
        });
    }

    #[DataProvider('dialects')]
    public function testCorruptOrDeletedCatalogAndVersionRowsFailClosed(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $catalog = $this->catalog($pdo);
            $first = $catalog->publish(BillingFixtures::definition(), 'admin');
            $input = BillingFixtures::definition('v2', '2026-10-14 00:00:00');
            $second = $catalog->publish($input, 'admin');
            $this->now = '2026-10-14 00:00:00';
            foreach (self::TABLES as $table) {
                $q = $pdo->prepare("SELECT * FROM $table WHERE id=?");
                $id = $table === 'credit_billing_catalog' ? $second['id'] : $pdo->query("SELECT id FROM $table ORDER BY id LIMIT 1")->fetchColumn();
                $q->execute([$id]); $row = $q->fetch(PDO::FETCH_ASSOC);
                $fields = $table === 'credit_billing_catalog' ?
                    ['definition' => '{}', 'version' => 'corrupt', 'effective_from' => '2026-10-13 01:00:00', 'catalog_id' => str_repeat('a', 64)] :
                    ['definition' => '{}', 'terms_id' => str_repeat('a', 64)];
                foreach ($fields as $field => $value) {
                    $pdo->prepare("UPDATE $table SET $field=? WHERE id=?")->execute([$value, $id]);
                    try { $catalog->current(); $this->fail('Corrupt current catalog accepted.'); } catch (ServiceUnavailable) {}
                    try { $catalog->resolve($second['catalog']['catalogId']); $this->fail('Corrupt historical catalog accepted.'); } catch (ServiceUnavailable) {}
                    $pdo->prepare("UPDATE $table SET $field=? WHERE id=?")->execute([$row[$field], $id]);
                }
                $pdo->prepare("UPDATE $table SET deleted=TRUE WHERE id=?")->execute([$id]);
                try { $catalog->current(); $this->fail('Deleted catalog/version accepted.'); } catch (ServiceUnavailable) {}
                $pdo->prepare("UPDATE $table SET deleted=FALSE WHERE id=?")->execute([$id]);
            }
            $this->assertSame($first['catalog'], $catalog->resolve($first['catalog']['catalogId'])->receipt());
        });
    }

    #[DataProvider('dialects')]
    public function testEveryWriteBoundaryAndLatePublicationRollBackAllThreeTables(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            foreach (self::TABLES as $table) {
                $pdo->exec("ALTER TABLE $table ADD CONSTRAINT injected_failure CHECK (1=0)");
                try {
                    $this->catalog($pdo)->publish(BillingFixtures::definition(), 'admin');
                    $this->fail('Publication write failure swallowed.');
                } catch (PDOException) {
                    $this->assertFalse($pdo->inTransaction());
                    $this->assertSame([[], [], []], $this->snapshot($pdo));
                } finally {
                    $pdo->exec("ALTER TABLE $table DROP " . self::dropCheckKeyword($pdo) . ' injected_failure');
                }
            }
            $clock = $this->createStub(Clock::class);
            $clock->method('now')->willReturnCallback(fn () =>
                (int) $pdo->query('SELECT COUNT(*) FROM credit_market_offer')->fetchColumn() === 3 ? '2026-10-13 00:00:00' : $this->now);
            try { $this->catalog($pdo, $clock)->publish(BillingFixtures::definition(), 'admin'); $this->fail('Late publication committed.'); }
            catch (Conflict) { $this->assertSame([[], [], []], $this->snapshot($pdo)); }
        });
    }

    #[DataProvider('dialects')]
    public function testConcurrentCatalogPublicationReplaysOrConflictsAfterUniqueIndexWait(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $this->catalog($pdo)->publish(BillingFixtures::definition('base', '2026-10-12 23:00:00'), 'admin');
            $input = BillingFixtures::definition(); $definition = new CatalogDefinition($input);
            $different = BillingFixtures::definition(); $different->offers[0]->price = '99';
            $instant = BillingFixtures::definition('different');
            $results = $this->race($pdo, $dialect, [$input, $input, $different, $instant], function () use ($pdo, $definition): void {
                $pdo->prepare('INSERT INTO credit_billing_catalog (id, version, catalog_id, effective_from, definition, created_at)
                    VALUES (?,?,?,?,?,?)')->execute(['paused-winner', $definition->version, $definition->id,
                        $definition->effectiveFrom, json_encode($definition->definition, JSON_THROW_ON_ERROR), $this->now]);
            });
            $this->assertSame(['result' => ['id' => 'paused-winner', 'catalog' => $definition->receipt()]], $results[0]);
            $this->assertSame($results[0], $results[1]);
            $this->assertSame(['error' => Conflict::class], $results[2]);
            $this->assertSame(['error' => Conflict::class], $results[3]);
            $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM credit_billing_catalog')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testConcurrentDifferentCatalogsCannotRedefineSharedProductVersion(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $input = BillingFixtures::definition();
            $other = BillingFixtures::definition('v2', '2026-10-14 00:00:00');
            $conflicting = BillingFixtures::definition('v3', '2026-10-15 00:00:00'); $conflicting->products[0]->credits = '999';
            $definition = new CatalogDefinition($input); $product = $definition->products['monthly'];
            $results = $this->race($pdo, $dialect, [$input, $other, $conflicting], function () use ($pdo, $product): void {
                $pdo->prepare('INSERT INTO credit_billing_product (id, commercial_key, version, terms_id, definition, created_at)
                    VALUES (?,?,?,?,?,?)')->execute(['paused-product', $product['productId'], $product['productVersion'],
                        CatalogDefinition::productHash($product), json_encode($product, JSON_THROW_ON_ERROR), $this->now]);
            });
            $this->assertSame($definition->receipt(), $results[0]['result']['catalog']);
            $this->assertSame((new CatalogDefinition($other))->receipt(), $results[1]['result']['catalog']);
            $this->assertSame(['error' => Conflict::class], $results[2]);
            $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM credit_billing_catalog')->fetchColumn());
            $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM credit_billing_product')->fetchColumn());
            $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM credit_market_offer')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testConcurrentDifferentCatalogsCannotRedefineSharedOfferVersion(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $this->catalog($pdo)->publish(BillingFixtures::definition('base', '2026-10-12 23:00:00'), 'admin');
            $input = BillingFixtures::definition(); $input->offers[0]->version = 'v2'; $input->offers[0]->price = '60';
            $other = BillingFixtures::definition('v2', '2026-10-14 00:00:00');
            $other->offers[0]->version = 'v2'; $other->offers[0]->price = '99';
            $definition = new CatalogDefinition($input); $terms = $definition->offers['monthly-br'];
            $results = $this->race($pdo, $dialect, [$input, $other], function () use ($pdo, $terms): void {
                $pdo->prepare('INSERT INTO credit_market_offer (id, commercial_key, version, terms_id, definition, created_at)
                    VALUES (?,?,?,?,?,?)')->execute(['paused-offer', $terms->offerId, $terms->version, $terms->termsId,
                        json_encode($terms, JSON_THROW_ON_ERROR), $this->now]);
            });
            $this->assertSame($definition->receipt(), $results[0]['result']['catalog']);
            $this->assertSame(['error' => Conflict::class], $results[1]);
            $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM credit_billing_catalog')->fetchColumn());
            $this->assertSame(4, (int) $pdo->query('SELECT COUNT(*) FROM credit_market_offer')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testAcceptanceReadsNewCatalogAndVersionsAfterEarlierRepeatableReadSnapshot(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $catalog = $this->catalog($pdo);
            $catalog->publish(BillingFixtures::definition(), 'admin');
            $pdo->beginTransaction();
            $pdo->query('SELECT * FROM credit_billing_catalog')->fetchAll(PDO::FETCH_ASSOC);
            $other = new PDO(getenv('FEATURE_CREDITS_' . strtoupper($dialect) . '_DSN'), 'credits_test', 'credits_test', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $input = BillingFixtures::definition('v2', '2026-10-14 00:00:00');
            $input->products[0]->productVersion = 'v2'; $input->products[0]->credits = '200';
            foreach (array_slice($input->offers, 0, 2) as $offer) { $offer->version = 'v2'; $offer->productVersion = 'v2'; }
            $published = $this->catalog($other)->publish($input, 'admin');
            $this->now = '2026-10-14 00:00:00';
            try {
                $this->assertSame($published['catalog'], $catalog->currentForAcceptance()->receipt());
            } catch (Conflict) {
                $this->assertStringContainsString('MariaDB', $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
                $pdo->rollBack(); $pdo->beginTransaction();
                $this->assertSame($published['catalog'], $catalog->currentForAcceptance()->receipt());
            }
            $pdo->rollBack();
        });
    }

    private function race(PDO $pdo, string $dialect, array $payloads, callable $pauseWinner): array
    {
        $workers = []; $pdo->beginTransaction();
        try {
            $pauseWinner();
            foreach ($payloads as $payload) {
                $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/billing-catalog-worker.php', $dialect,
                    base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), $this->now],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes]; stream_set_timeout($pipes[1], 15);
                $this->assertSame("ready\n", fgets($pipes[1]));
            }
            foreach ($workers as [$process, $pipes]) {
                $read = [$pipes[1]]; $write = $except = [];
                $this->assertSame(0, stream_select($read, $write, $except, 0, 150000));
                $this->assertTrue(proc_get_status($process)['running']);
            }
            $pdo->commit(); $results = [];
            foreach ($workers as [$process, $pipes]) {
                $results[] = json_decode(fgets($pipes[1]), true, 32, JSON_THROW_ON_ERROR);
                $this->assertSame('', stream_get_contents($pipes[2]));
            }
            return $results;
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($workers as [$process, $pipes]) {
                if (proc_get_status($process)['running']) proc_terminate($process);
                foreach ($pipes as $pipe) fclose($pipe);
                proc_close($process);
            }
        }
    }
}
