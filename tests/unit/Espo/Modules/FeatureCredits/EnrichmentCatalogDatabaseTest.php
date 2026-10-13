<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\ServiceUnavailable;
use Espo\Entities\User;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Controllers\CreditEnrichmentConfiguration;
use Espo\Modules\FeatureCredits\Enrichment\Catalog;
use Espo\Modules\FeatureCredits\Enrichment\RateCard;
use Espo\ORM\EntityManager;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SchemaTest.php';
require_once __DIR__ . '/EnrichmentFixtures.php';

class EnrichmentCatalogDatabaseTest extends TestCase
{
    use SchemaFixture;

    private string $now = '2026-10-11 12:00:00';

    public static function dialects(): iterable
    {
        yield 'MySQL' => ['Mysql'];
        yield 'PostgreSQL' => ['Postgresql'];
    }

    public static function catalogs(): iterable
    {
        foreach (self::dialects() as $name => [$dialect]) {
            yield "$name fields" => [$dialect, false];
            yield "$name discovery" => [$dialect, true];
        }
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

    #[DataProvider('dialects')]
    public function testPublicationApiReplaysAndSelectionChangesWithoutRepricingOriginal(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $catalog = $this->catalog($pdo);
            $user = $this->createStub(User::class); $user->method('isAdmin')->willReturn(true);
            $user->method('getId')->willReturn('platform-admin');
            $controller = new CreditEnrichmentConfiguration($user, $catalog);
            $input = EnrichmentFixtures::definition();
            $request = $this->createStub(Request::class);
            $request->method('getBodyContents')->willReturn(json_encode($input, JSON_THROW_ON_ERROR));
            $response = $this->createStub(Response::class);
            $first = (array) $controller->postActionPublish($request, $response);
            $this->assertEquals($first, (array) $controller->postActionPublish($request, $response));
            $this->assertSame(17, strlen($first['id']));
            $this->assertSame('platform-admin', $pdo->query('SELECT created_by_id FROM enrichment_credit_catalog')->fetchColumn());
            $next = EnrichmentFixtures::discoveryDefinition();
            $next['rates'][0]['credits'] = '3.0000';
            $second = $catalog->publish((object) $next, 'second-admin');
            try { $catalog->current(); $this->fail('Future price selected.'); } catch (ServiceUnavailable) {}
            $this->now = '2026-10-12 00:00:00';
            $this->assertSame($first['policy'], $catalog->current()->receipt());
            $this->now = '2026-10-12 23:59:59';
            $this->assertSame($first['policy']['policyId'], $catalog->current()->id);
            $this->now = '2026-10-13 00:00:00';
            $this->assertSame($second['policy'], $catalog->current()->receipt());
            $this->assertSame($first['policy'], $catalog->resolve($first['policy']['policyId'])->receipt());
            $this->assertSame($first, $catalog->publish((object) $input, 'another-admin'));
            $read = $this->createStub(Request::class);
            $read->method('getQueryParams')->willReturn(['policyId' => $first['policy']['policyId']]);
            $this->assertEquals((object) $first['policy'], $controller->getActionRead($read, $response));
            $readCurrent = $this->createStub(Request::class); $readCurrent->method('getQueryParams')->willReturn([]);
            $this->assertEquals((object) $second['policy'], $controller->getActionRead($readCurrent, $response));
            foreach (['tenant_credit_balance', 'credit_usage', 'credit_grant', 'credit_transaction', 'credit_reservation'] as $table) {
                $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn());
            }
        });
    }

    #[DataProvider('dialects')]
    public function testConflictingVersionAndInstantAndBackdatingFailWithoutMutation(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $catalog = $this->catalog($pdo);
            $input = EnrichmentFixtures::definition();
            $receipt = $catalog->publish((object) $input, 'admin');
            $changed = $input; $changed['rates'][0]['credits'] = '9.0000';
            $sameInstant = $input; $sameInstant['version'] = 'v2';
            $backdated = EnrichmentFixtures::definition('v3', $this->now);
            foreach ([$changed, $sameInstant, $backdated] as $bad) {
                try { $catalog->publish((object) $bad, 'admin'); $this->fail('Conflicting publication accepted.'); }
                catch (Conflict) { $this->assertFalse($pdo->inTransaction()); }
            }
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM enrichment_credit_catalog')->fetchColumn());
            $this->assertSame($receipt, $catalog->publish((object) $input, 'admin'));
        });
    }

    #[DataProvider('dialects')]
    public function testOutOfOrderFuturePublicationHasUnambiguousEffectiveWindows(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $catalog = $this->catalog($pdo);
            $third = $catalog->publish((object) EnrichmentFixtures::definition('v3', '2026-10-14 00:00:00'), 'admin');
            $first = $catalog->publish((object) EnrichmentFixtures::definition(), 'admin');
            $second = $catalog->publish((object) EnrichmentFixtures::definition('v2', '2026-10-13 00:00:00'), 'admin');
            foreach ([$first, $second, $third] as $receipt) {
                $this->now = $receipt['policy']['effectiveFrom'];
                $this->assertSame($receipt['policy']['policyId'], $catalog->current()->id);
                $this->assertSame($first['policy'], $catalog->resolve($first['policy']['policyId'])->receipt());
            }
        });
    }

    #[DataProvider('dialects')]
    public function testDeletedOrCorruptSelectedCatalogCannotFallBackOrRepublish(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $catalog = $this->catalog($pdo);
            $first = $catalog->publish((object) EnrichmentFixtures::definition(), 'admin');
            $input = EnrichmentFixtures::definition('v2', '2026-10-13 00:00:00');
            $second = $catalog->publish((object) $input, 'admin');
            $this->now = '2026-10-13 00:00:00';
            $repriced = $input; $repriced['rates'][0]['credits'] = '9.0000';
            foreach ([['definition', '{}'], ['definition', json_encode($repriced, JSON_THROW_ON_ERROR)],
                ['version', 'corrupt'], ['effective_from', '2026-10-12 01:00:00']] as [$field, $value]) {
                $pdo->prepare("UPDATE enrichment_credit_catalog SET $field=? WHERE id=?")->execute([$value, $second['id']]);
                try { $catalog->current(); $this->fail('Corrupt catalog selected.'); } catch (ServiceUnavailable) {}
                try { $catalog->resolve($second['policy']['policyId']); $this->fail('Corrupt snapshot resolved.'); } catch (ServiceUnavailable) {}
                $restore = match ($field) {
                    'definition' => json_encode((new RateCard($input))->definition, JSON_THROW_ON_ERROR),
                    'version' => $input['version'], 'effective_from' => $input['effectiveFrom'],
                };
                $pdo->prepare("UPDATE enrichment_credit_catalog SET $field=? WHERE id=?")->execute([$restore, $second['id']]);
            }
            $pdo->prepare('UPDATE enrichment_credit_catalog SET deleted=TRUE WHERE id=?')->execute([$second['id']]);
            try { $catalog->current(); $this->fail('Deleted catalog fell back to old price.'); } catch (ServiceUnavailable) {}
            try { $catalog->publish((object) $input, 'admin'); $this->fail('Deleted catalog republished.'); } catch (Conflict) {}
            $this->assertSame($first['policy'], $catalog->resolve($first['policy']['policyId'])->receipt());
            $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM enrichment_credit_catalog')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testClockCrossingAndWriteFailureRollBackPublication(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $clock = $this->createStub(Clock::class);
            $clock->method('now')->willReturnOnConsecutiveCalls($this->now, $this->now, '2026-10-12 00:00:00');
            try {
                $this->catalog($pdo, $clock)->publish((object) EnrichmentFixtures::definition(), 'admin');
                $this->fail('Late publication committed.');
            } catch (Conflict) {}
            $this->assertFalse($pdo->inTransaction());
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM enrichment_credit_catalog')->fetchColumn());
            $pdo->exec('ALTER TABLE enrichment_credit_catalog ADD CONSTRAINT injected_failure CHECK (1=0)');
            try {
                $this->catalog($pdo)->publish((object) EnrichmentFixtures::definition(), 'admin');
                $this->fail('Write failure swallowed.');
            } catch (PDOException) {
                $this->assertFalse($pdo->inTransaction());
                $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM enrichment_credit_catalog')->fetchColumn());
            } finally {
                $pdo->exec('ALTER TABLE enrichment_credit_catalog DROP ' . self::dropCheckKeyword($pdo) . ' injected_failure');
            }
        });
    }

    #[DataProvider('catalogs')]
    public function testConcurrentPublicationReplaysOrConflictsAfterRealUniqueIndexWait(string $dialect, bool $discovery): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect, $discovery): void {
            $input = $discovery ? EnrichmentFixtures::discoveryDefinition() : EnrichmentFixtures::definition();
            $card = new RateCard($input);
            $differentPrice = $input; $differentPrice['rates'][0]['credits'] = '9.0000';
            $differentVersion = $input; $differentVersion['version'] = 'v2';
            $workers = [];
            $pdo->beginTransaction();
            // Pause the winning publication after INSERT and before COMMIT.
            $pdo->prepare('INSERT INTO enrichment_credit_catalog (id, version, policy_id, effective_from, definition, created_at)
                VALUES (?,?,?,?,?,?)')->execute(['paused-winner', $card->version, $card->id, $card->effectiveFrom,
                    json_encode($card->definition, JSON_THROW_ON_ERROR), $this->now]);
            try {
                foreach ([$input, $input, $differentPrice, $differentVersion] as $payload) {
                    $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/enrichment-catalog-worker.php', $dialect,
                        base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), $this->now],
                        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                    $this->assertIsResource($process);
                    $workers[] = [$process, $pipes];
                    stream_set_timeout($pipes[1], 15);
                    $this->assertSame("ready\n", fgets($pipes[1]));
                }
                foreach ($workers as [$process, $pipes]) {
                    $read = [$pipes[1]]; $write = $except = [];
                    $this->assertSame(0, stream_select($read, $write, $except, 0, 150000));
                    $this->assertTrue(proc_get_status($process)['running']);
                }
                $pdo->commit();
                $results = [];
                foreach ($workers as [$process, $pipes]) {
                    $results[] = json_decode(fgets($pipes[1]), true, 32, JSON_THROW_ON_ERROR);
                    $this->assertSame('', stream_get_contents($pipes[2]));
                }
                $this->assertSame(['result' => ['id' => 'paused-winner', 'policy' => $card->receipt()]], $results[0]);
                $this->assertSame($results[0], $results[1]);
                $this->assertSame(['error' => Conflict::class], $results[2]);
                $this->assertSame(['error' => Conflict::class], $results[3]);
                $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM enrichment_credit_catalog')->fetchColumn());
            } finally {
                if ($pdo->inTransaction()) $pdo->rollBack();
                foreach ($workers as [$process, $pipes]) {
                    if (proc_get_status($process)['running']) proc_terminate($process);
                    foreach ($pipes as $pipe) fclose($pipe);
                    proc_close($process);
                }
            }
        });
    }
}
