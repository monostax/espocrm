<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureLiveRecords;

use DateTimeImmutable;
use Espo\Modules\FeatureLiveRecords\Outbox\Intent;
use Espo\Modules\FeatureLiveRecords\Outbox\Store;
use PDO;
use PHPUnit\Framework\TestCase;

final class FreshnessTest extends TestCase
{
    private PDO $pdo;
    private Store $store;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->pdo = new PDO(getenv('LIVE_RECORD_TEST_DSN') ?: 'sqlite::memory:',
            getenv('LIVE_RECORD_TEST_USER') ?: null, getenv('LIVE_RECORD_TEST_PASSWORD') ?: null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE live_record_outbox (
            id VARCHAR(17) PRIMARY KEY, tenant_id VARCHAR(64), intent TEXT,
            created_at TIMESTAMP, due_at TIMESTAMP, attempts INTEGER DEFAULT 0,
            lease_token VARCHAR(64), lease_until TIMESTAMP, completed_at TIMESTAMP, failure_code VARCHAR(32))');
        $this->store = new Store($this->pdo);
        $this->now = new DateTimeImmutable('2026-10-11 20:00:00 UTC');
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DROP TABLE live_record_outbox');
    }

    private function append(int $age, string $kind = 'update'): string
    {
        $this->pdo->beginTransaction();
        $id = $this->store->append(new Intent('tenant-a', $kind,
            $kind === 'access' ? [] : [['type' => 'Task', 'id' => 'task']]), $this->now->modify("-$age seconds"));
        $this->pdo->commit();
        return $id;
    }

    public function testClaimNeverReplaysFiveSecondOldEventsEvenBeforeCleanup(): void
    {
        $this->append(3600);
        $this->append(5);
        $this->append(5, 'access');
        $fresh = $this->append(4);
        $leases = $this->store->claim($this->now);
        self::assertCount(1, $leases);
        self::assertSame($fresh, $leases[0]->id);
        self::assertSame(3, $this->store->expire($this->now));
        self::assertSame(1, $this->store->pendingMetrics($this->now)['pending']);
        self::assertSame(3, (int) $this->pdo->query("SELECT COUNT(*) FROM live_record_outbox WHERE failure_code = 'expired'")->fetchColumn());
    }

    public function testExpiredBackoffAndMalformedRowsAreRetiredWithoutSummaries(): void
    {
        $this->append(10);
        $this->append(10);
        $this->pdo->exec("UPDATE live_record_outbox SET intent = '{}', due_at = '2030-01-01 00:00:00', failure_code = 'malformed_intent'");
        self::assertSame(2, $this->store->expire($this->now));
        self::assertSame(0, $this->store->pendingMetrics($this->now)['pending']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM live_record_outbox')->fetchColumn());
        self::assertSame([], $this->store->claim($this->now));
    }

    public function testCrashRecoveryDoesNotReplayExpiredLeases(): void
    {
        $this->append(0);
        $lease = $this->store->claim($this->now)[0];
        self::assertSame(0, $this->store->expire($this->now->modify('+5 seconds')));
        self::assertSame([], $this->store->claim($this->now->modify('+30 seconds')));
        self::assertSame(1, $this->store->expire($this->now->modify('+30 seconds')));
        self::assertFalse($this->store->complete($lease, $this->now->modify('+30 seconds')));
        self::assertSame(0, $this->store->pendingMetrics($this->now)['pending']);
    }
}
