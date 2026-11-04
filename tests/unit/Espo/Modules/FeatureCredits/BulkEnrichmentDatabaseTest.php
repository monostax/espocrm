<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Job\JobScheduler;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Enrichment\Access;
use Espo\Modules\FeatureCredits\Enrichment\ActorScope;
use Espo\Modules\FeatureCredits\Enrichment\BatchRunner;
use Espo\Modules\FeatureCredits\Enrichment\Batches;
use Espo\Modules\FeatureCredits\Enrichment\EnrichmentStatus;
use Espo\Modules\FeatureCredits\Enrichment\Quotes;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\TransactionManager;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SchemaTest.php';

class BulkEnrichmentDatabaseTest extends TestCase
{
    use SchemaFixture;

    private const NOW = '2026-11-04 12:00:00';

    public static function dialects(): iterable
    {
        yield 'MySQL' => ['Mysql'];
        yield 'PostgreSQL' => ['Postgresql'];
    }

    #[DataProvider('dialects')]
    public function testStatusKeepsTheLatestCompletedSearchPerProduct(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $this->quote($pdo, 'q-new', 'Contact', 'c1', ['email', 'phone'], 'u-new', '2026-11-03 10:00:00');
            $this->attempt($pdo, 'a1', 'q-new', 'email', 'matched');
            $this->attempt($pdo, 'a2', 'q-new', 'phone', 'no_match');
            $this->quote($pdo, 'q-old', 'Contact', 'c1', ['email'], 'u-old', '2026-11-01 10:00:00');
            $this->attempt($pdo, 'a3', 'q-old', 'email', 'no_match');
            $this->quote($pdo, 'q-fail', 'Contact', 'c1', ['phone'], 'u-fail', '2026-11-04 09:00:00');
            $this->attempt($pdo, 'a4', 'q-fail', 'phone', 'failed');
            $this->quote($pdo, 'q-socios', 'Account', 'a1', ['socios'], 'u-socios', '2026-11-02 10:00:00', 'route_exhausted');
            $this->purchase($pdo, 'p1', 'q-socios', 'u-socios');
            $this->quote($pdo, 'q-empty', 'Account', 'a2', ['socios'], 'u-empty', '2026-11-02 11:00:00', 'route_exhausted');

            $this->assertSame(5, EnrichmentStatus::backfill($pdo));
            $contact = $pdo->query("SELECT email_enriched_at, email_enrichment_result, phone_enriched_at, phone_enrichment_result
                FROM contact WHERE id='c1'")->fetch(PDO::FETCH_ASSOC);
            // The older email search and the failed phone search do not replace the newer completed ones.
            $this->assertSame(['2026-11-03 10:00:00', 'found', '2026-11-03 10:00:00', 'not_found'], self::values($contact));
            $this->assertSame(['2026-11-02 10:00:00', 'found'], self::values($pdo->query(
                "SELECT socios_enriched_at, socios_enrichment_result FROM account WHERE id='a1'")->fetch(PDO::FETCH_ASSOC)));
            $this->assertSame(['2026-11-02 11:00:00', 'not_found'], self::values($pdo->query(
                "SELECT socios_enriched_at, socios_enrichment_result FROM account WHERE id='a2'")->fetch(PDO::FETCH_ASSOC)));
            $this->assertSame([null, null], self::values($pdo->query(
                "SELECT email_enriched_at, email_enrichment_result FROM contact WHERE id='c2'")->fetch(PDO::FETCH_ASSOC)));

            // Rebuilds re-run the backfill; unchanged rows are not rewritten.
            $pdo->exec("UPDATE contact SET first_name='edited' WHERE id='c1'");
            EnrichmentStatus::backfill($pdo);
            $this->assertSame('edited', $pdo->query("SELECT first_name FROM contact WHERE id='c1'")->fetchColumn());
            $row = $pdo->query("SELECT * FROM enrichment_quote WHERE id='q-old'")->fetch(PDO::FETCH_ASSOC);
            EnrichmentStatus::recordLocked($pdo, $row, '2026-11-01 10:00:00');
            $this->assertSame('found', $pdo->query("SELECT email_enrichment_result FROM contact WHERE id='c1'")->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testRunsAreCreatedIdempotentlyAndCancelled(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $batches = $this->batches($pdo, $scheduled);
            $body = ['tenantId' => 'tenant', 'targetType' => 'Contact', 'product' => 'email', 'sources' => [3],
                'targetIds' => ['c1', 'c2', 'c1'], 'requestKey' => 'run-1'];
            $created = $batches->create((object) $body);
            $this->assertSame(2, $created['total']);
            $this->assertSame(2, $created['counts']['pending']);
            $this->assertSame('running', $created['state']);
            $this->assertSame($created['id'], $batches->create((object) $body)['id']);
            $this->assertSame(['batchId' => $created['id']], $scheduled[0]);
            try {
                $batches->create((object) (['sources' => [1]] + $body));
                $this->fail('Changed settings reused a request key.');
            } catch (Conflict) {}
            $this->assertSame([$created['id']], array_column($batches->list(['tenantId' => 'tenant'])['list'], 'id'));
            try {
                $batches->resume((object) ['tenantId' => 'tenant', 'batchId' => $created['id']]);
                $this->fail('A running run was resumed.');
            } catch (Conflict) {}
            $cancelled = $batches->cancel((object) ['tenantId' => 'tenant', 'batchId' => $created['id']]);
            $this->assertSame('cancelled', $cancelled['state']);
            $this->assertSame(2, $cancelled['counts']['cancelled']);
            // Another user's run is invisible, and losing workspace access closes the endpoints.
            $this->assertSame([], $this->batches($pdo, $scheduled, 'other-user')->list(['tenantId' => 'tenant'])['list']);
            try {
                $this->batches($pdo, $scheduled, 'actor', false)->status(['tenantId' => 'tenant', 'batchId' => $created['id']]);
                $this->fail('A user outside the workspace read a run.');
            } catch (Forbidden) {}
        });
    }

    #[DataProvider('dialects')]
    public function testRunnerClosesStuckAndRevokedWorkAndFindsLostRuns(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $this->batch($pdo, 'b-stuck', 'running', '2026-11-04 11:00:00');
            $this->item($pdo, 'i1', 'b-stuck', 0, 'running', '2026-11-04 09:00:00');
            $this->item($pdo, 'i2', 'b-stuck', 1, 'pending');
            $runner = $this->runner($pdo, false);
            // The stuck record times out; a pending one still needs another step.
            $this->assertTrue($runner->step('b-stuck'));
            $this->assertSame(['failed', 'timeout'], self::values($pdo->query(
                "SELECT state, reason FROM enrichment_batch_item WHERE id='i1'")->fetch(PDO::FETCH_ASSOC)));

            $revoked = $this->runner($pdo, true);
            $this->item($pdo, 'i3', 'b-stuck', 2, 'running', '2026-11-04 11:59:00');
            $this->assertFalse($revoked->step('b-stuck'));
            $this->assertSame(['i1' => 'failed', 'i2' => 'skipped', 'i3' => 'failed'],
                $pdo->query("SELECT id, state FROM enrichment_batch_item ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR));
            $this->assertSame(['completed', self::NOW], self::values($pdo->query(
                "SELECT state, completed_at FROM enrichment_batch WHERE id='b-stuck'")->fetch(PDO::FETCH_ASSOC)));
            $this->assertFalse($runner->step('b-stuck'));

            $this->batch($pdo, 'b-lost', 'running', '2026-11-04 11:50:00');
            $this->batch($pdo, 'b-fresh', 'running', '2026-11-04 11:59:30');
            $this->batch($pdo, 'b-paused', 'paused', '2026-11-04 11:00:00');
            $this->batch($pdo, 'b-paused-busy', 'paused', '2026-11-04 11:00:00');
            $this->item($pdo, 'i4', 'b-paused-busy', 0, 'running', '2026-11-04 11:00:00');
            $this->assertEqualsCanonicalizing(['b-lost', 'b-paused-busy'], $runner->stale());
        });
    }

    private function database(string $dialect, callable $test): void
    {
        $dsn = getenv('FEATURE_CREDITS_' . strtoupper($dialect) . '_DSN');
        if (!$dsn) $this->markTestSkipped('Requires an empty feature_credits_schema_test database.');
        $pdo = new PDO($dsn, 'credits_test', 'credits_test', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $schema = $this->schema($dialect);
        $platform = $dialect === 'Mysql' ? new MySQL80Platform() : new PostgreSQLPlatform();
        try {
            foreach ($schema->toSql($platform) as $sql) $pdo->exec($sql);
            $status = 'email_enriched_at VARCHAR(19), email_enrichment_result VARCHAR(16), phone_enriched_at VARCHAR(19),
                phone_enrichment_result VARCHAR(16)';
            $pdo->exec("CREATE TABLE contact (id VARCHAR(17) PRIMARY KEY, tenant_id VARCHAR(17) NOT NULL,
                deleted BOOLEAN NOT NULL DEFAULT FALSE, first_name VARCHAR(64), $status)");
            $pdo->exec("INSERT INTO contact (id, tenant_id) VALUES ('c1', 'tenant'), ('c2', 'tenant')");
            $pdo->exec('CREATE TABLE account (id VARCHAR(17) PRIMARY KEY, tenant_id VARCHAR(17) NOT NULL,
                deleted BOOLEAN NOT NULL DEFAULT FALSE, socios_enriched_at VARCHAR(19), socios_enrichment_result VARCHAR(16))');
            $pdo->exec("INSERT INTO account (id, tenant_id) VALUES ('a1', 'tenant'), ('a2', 'tenant')");
            $test($pdo);
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->exec('DROP TABLE IF EXISTS account');
            $pdo->exec('DROP TABLE IF EXISTS contact');
            foreach (array_reverse($schema->getTables()) as $table) $pdo->exec('DROP TABLE IF EXISTS ' . $platform->quoteIdentifier($table->getName()));
        }
    }

    private function quote(PDO $pdo, string $id, string $type, string $target, array $products, string $usage, string $settled,
        ?string $reason = null): void
    {
        $request = ['tenantId' => 'tenant', 'targetType' => $type, 'targetId' => $target, 'products' => $products,
            'requestKey' => 'k-' . $id, 'waterfall' => false, 'budget' => null, 'refresh' => true,
            ...($products === ['socios'] ? ['count' => 10, 'filters' => []] : ['sources' => [3]])];
        $snapshot = ['request' => $request, 'actorId' => 'actor'];
        $pdo->prepare('INSERT INTO enrichment_quote (id, tenant_id, target_type, target_id, request_key, snapshot_hash, snapshot,
            created_at, created_by_id, confirmed_at, admitted_at, usage_id, discovery_stop_reason, deleted)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,FALSE)')->execute([$id, 'tenant', $type, $target, 'k-' . $id, Quotes::hash($snapshot),
                json_encode($snapshot), $settled, 'actor', $settled, $settled, $usage, $reason]);
        $pdo->prepare("INSERT INTO credit_usage (id, tenant_id, operation_type, operation_key, input_hash, execution_id, billing_regime,
            billing_rate_id, state, admitted_at, settled_at, deleted) VALUES (?,?,'enrichment',?,?,?,'unified-prepaid-v1','rate','settled',?,?,FALSE)")
            ->execute([$usage, 'tenant', 'enrichment:' . $id, str_repeat('0', 64), 'enrichment:' . $id, $settled, $settled]);
    }

    private function attempt(PDO $pdo, string $id, string $quote, string $product, string $state): void
    {
        $pdo->prepare("INSERT INTO enrichment_attempt (id, tenant_id, quote_id, product, provider, state, claim_token, claimed_at,
            deadline_at, lease_until, credits, deleted) VALUES (?,?,?,?,'hub',?,'t','2026-11-01 00:00:00','2026-11-01 00:00:00',
            '2026-11-01 00:00:00','3.0000',FALSE)")->execute([$id, 'tenant', $quote, $product, $state]);
    }

    private function purchase(PDO $pdo, string $id, string $quote, string $usage): void
    {
        $pdo->prepare("INSERT INTO enrichment_purchase (id, tenant_id, quote_id, usage_id, purchase_key, input_hash, provider, product,
            policy_id, credits, values_ciphertext, acquired_at, purchased_at, purchase_kind, deleted)
            VALUES (?,?,?,?,?,'h','hub','socios','p','6.0000','x','2026-11-02 10:00:00','2026-11-02 10:00:00','candidate',FALSE)")
            ->execute([$id, 'tenant', $quote, $usage, 'key-' . $id]);
    }

    private function batch(PDO $pdo, string $id, string $state, string $stepped): void
    {
        $pdo->prepare("INSERT INTO enrichment_batch (id, tenant_id, created_by_id, request_key, target_type, product, settings, state,
            total, created_at, stepped_at, deleted) VALUES (?,'tenant','actor',?,'Contact','email',?,?,1,?,?,FALSE)")
            ->execute([$id, 'k-' . $id, '{"products":["email"],"refresh":true,"sources":[3]}', $state, $stepped, $stepped]);
    }

    private function item(PDO $pdo, string $id, string $batch, int $position, string $state, ?string $started = null): void
    {
        $pdo->prepare('INSERT INTO enrichment_batch_item (id, batch_id, tenant_id, target_id, position, state, started_at, deleted)
            VALUES (?,?,?,?,?,?,?,FALSE)')->execute([$id, $batch, 'tenant', 'c-' . $id, $position, $state, $started]);
    }

    private function batches(PDO $pdo, ?array &$scheduled, string $actor = 'actor', bool $member = true): Batches
    {
        $scheduled = [];
        $access = $this->createMock(Access::class);
        $access->method('actorId')->willReturn($actor);
        if (!$member) $access->method('tenant')->willThrowException(new Forbidden());
        $access->method('record')->willReturn($this->createStub(Entity::class));
        $quotes = (new \ReflectionClass(Quotes::class))->newInstanceWithoutConstructor();
        return new Batches($this->manager($pdo), $access, $quotes, $this->clock(), $this->scheduler($scheduled));
    }

    private function runner(PDO $pdo, bool $revoked): BatchRunner
    {
        $actors = $this->createMock(ActorScope::class);
        if ($revoked) $actors->method('run')->willThrowException(new Forbidden());
        $scheduled = [];
        return new BatchRunner($this->manager($pdo), $actors, $this->scheduler($scheduled), $this->clock(), $this->createStub(Log::class));
    }

    private function manager(PDO $pdo): EntityManager
    {
        $transactions = $this->createMock(TransactionManager::class);
        $transactions->method('run')->willReturnCallback(static function (callable $work) use ($pdo) {
            $pdo->beginTransaction();
            try { $result = $work(); $pdo->commit(); return $result; }
            catch (\Throwable $e) { $pdo->rollBack(); throw $e; }
        });
        $manager = $this->createStub(EntityManager::class);
        $manager->method('getPDO')->willReturn($pdo);
        $manager->method('getTransactionManager')->willReturn($transactions);
        return $manager;
    }

    private function clock(): Clock
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(self::NOW);
        return $clock;
    }

    private function scheduler(array &$scheduled): JobSchedulerFactory
    {
        $job = $this->createMock(JobScheduler::class);
        foreach (['setClassName', 'setGroup', 'setDelay'] as $method) $job->method($method)->willReturnSelf();
        $job->method('setData')->willReturnCallback(function ($data) use ($job, &$scheduled) { $scheduled[] = $data; return $job; });
        $factory = $this->createStub(JobSchedulerFactory::class);
        $factory->method('create')->willReturn($job);
        return $factory;
    }

    private static function values(array|false $row): array
    {
        return array_map(static fn ($value) => $value === null ? null : (string) $value, array_values($row ?: []));
    }
}
