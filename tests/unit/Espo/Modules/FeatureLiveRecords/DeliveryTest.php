<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureLiveRecords;

use DateTimeImmutable;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Core\Utils\Log;
use Espo\Core\WebSocket\ConfigDataProvider;
use Espo\Core\WebSocket\Sender;
use Espo\Modules\FeatureLiveRecords\Delivery\Gateway;
use Espo\Modules\FeatureLiveRecords\Classes\Console\Commands\LiveRecords;
use Espo\Modules\FeatureLiveRecords\Delivery\Worker;
use Espo\Modules\FeatureLiveRecords\Outbox\Intent;
use Espo\Modules\FeatureLiveRecords\Outbox\Lease;
use Espo\Modules\FeatureLiveRecords\Outbox\Store;
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\TestCase;

/** Production worker + native publisher contract, real SQL/worker processes; no Rails bridge. */
final class DeliveryTest extends TestCase
{
    private PDO $pdo;
    private Worker $worker;
    private Gateway $gateway;
    private Store $store;
    private string $directory;
    private array $workerPids = [];
    private bool $enabled = true;

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork')) self::markTestSkipped('Worker tests require pcntl.');
        $this->directory = sys_get_temp_dir() . '/live-record-native-delivery-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        file_put_contents($this->directory . '/requests', '');
        $this->pdo = new PDO('sqlite:' . $this->directory . '/outbox.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE live_record_outbox (
            id VARCHAR(17) PRIMARY KEY, tenant_id VARCHAR(64), intent TEXT,
            created_at TIMESTAMP, due_at TIMESTAMP, attempts INTEGER DEFAULT 0,
            lease_token VARCHAR(64), lease_until TIMESTAMP, completed_at TIMESTAMP, failure_code VARCHAR(32))');
        $this->store = new Store($this->pdo);
        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturnCallback(fn () => $this->pdo);
        $sender = $this->createMock(Sender::class);
        $sender->method('send')->willReturnCallback(function (string $body): void {
            file_put_contents($this->directory . '/requests', $body . "\n", FILE_APPEND);
            while (is_file($this->directory . '/hold')) { usleep(10000); clearstatcache(); }
            if (is_file($this->directory . '/fail')) throw new \RuntimeException('Native publisher unavailable.');
        });
        $config = $this->createMock(ConfigDataProvider::class);
        $config->method('isEnabled')->willReturnCallback(fn () => $this->enabled);
        $this->gateway = new Gateway($sender, $config);
        $this->worker = new Worker($em, $this->gateway, $this->createMock(Log::class));
    }

    protected function tearDown(): void
    {
        foreach ($this->workerPids as $pid) { posix_kill($pid, SIGKILL); pcntl_waitpid($pid, $status); }
        if (isset($this->directory)) {
            foreach (glob($this->directory . '/*') as $path) unlink($path);
            rmdir($this->directory);
        }
    }

    private function append(): string
    {
        $this->pdo->beginTransaction();
        $id = $this->store->append(new Intent('tenant-a', 'update', [['type' => 'Task', 'id' => 'task-a']]), new DateTimeImmutable());
        $this->pdo->commit();
        return $id;
    }

    private function requests(): array
    {
        return array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($this->directory . '/requests', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    public function testPublishesOneInternalNativeHintAndCompletes(): void
    {
        $id = $this->append();
        self::assertTrue($this->worker->tick());
        self::assertSame([[
            'topicId' => 'liveRecordChange', 'eventId' => $id,
            'intentCreatedAtMs' => (new DateTimeImmutable($this->pdo->query('SELECT created_at FROM live_record_outbox')->fetchColumn()))->getTimestamp() * 1000,
            'tenantId' => 'tenant-a', 'kind' => 'update', 'records' => [['type' => 'Task', 'id' => 'task-a']],
        ]], $this->requests());
        self::assertSame(0, $this->worker->maintain()['pending']);
        self::assertFalse($this->worker->tick());
    }

    public function testSenderFailureRetriesOnlyWhileFresh(): void
    {
        $id = $this->append();
        touch($this->directory . '/fail');
        self::assertTrue($this->worker->tick());
        $row = $this->pdo->query('SELECT * FROM live_record_outbox')->fetch(PDO::FETCH_ASSOC);
        self::assertNull($row['completed_at']);
        self::assertNull($row['lease_token']);
        self::assertSame('bridge_unavailable', $row['failure_code']);
        self::assertGreaterThan($row['created_at'], $row['due_at']);
        unlink($this->directory . '/fail');
        $this->pdo->exec("UPDATE live_record_outbox SET due_at = '2000-01-01 00:00:00'");
        self::assertTrue($this->worker->tick());
        self::assertCount(2, $this->requests());
        self::assertSame($id, $this->requests()[1]['eventId']);
        self::assertSame(0, $this->worker->maintain()['pending']);
    }

    public function testExpiredFloodNeverPublishes(): void
    {
        for ($i = 0; $i < 50; $i++) $this->append();
        $this->pdo->exec("UPDATE live_record_outbox SET created_at = '2000-01-01 00:00:00'");
        self::assertFalse($this->worker->tick());
        self::assertSame([], $this->requests());
        self::assertSame(50, (int) $this->pdo->query("SELECT COUNT(*) FROM live_record_outbox WHERE failure_code = 'expired'")->fetchColumn());
    }

    public function testEventExpiredAfterClaimIsNotPublished(): void
    {
        $this->append();
        $lease = $this->store->claim(new DateTimeImmutable())[0];
        $this->gateway->deliver(new Lease($lease->id, $lease->tenantId, $lease->token, $lease->intent, $lease->attempts, gmdate('Y-m-d H:i:s', time() - 5)));
        self::assertSame([], $this->requests());
    }

    public function testRecoveryDoesNotReplayExpiredFailures(): void
    {
        $this->append();
        touch($this->directory . '/fail');
        $this->worker->tick();
        $this->pdo->exec("UPDATE live_record_outbox SET created_at = '2000-01-01 00:00:00'");
        unlink($this->directory . '/fail');
        self::assertFalse($this->worker->tick());
        self::assertCount(1, $this->requests());
        self::assertSame(0, $this->worker->maintain()['pending']);
    }

    public function testNativeDisabledNeverFallsBackToAccountBroadcast(): void
    {
        $this->enabled = false;
        $this->append();
        self::assertTrue($this->worker->tick());
        self::assertSame([], $this->requests());
        self::assertSame(1, $this->worker->maintain()['pending']);
    }

    public function testAbandonedLeaseIsFenced(): void
    {
        $this->append();
        $lease = $this->store->claim(new DateTimeImmutable())[0];
        self::assertFalse($this->worker->tick());
        $this->pdo->exec("UPDATE live_record_outbox SET lease_until = '2000-01-01 00:00:00'");
        self::assertTrue($this->worker->tick());
        self::assertFalse($this->store->complete($lease, new DateTimeImmutable()));
        self::assertSame(0, $this->worker->maintain()['pending']);
    }

    private function startWorker(): int
    {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $this->pdo = new PDO('sqlite:' . $this->directory . '/outbox.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            (new LiveRecords($this->worker))->run(new Params(null, null, null), $this->createMock(IO::class));
            exit(0);
        }
        $this->workerPids[$pid] = $pid;
        return $pid;
    }

    private function await(callable $condition): void
    {
        $deadline = microtime(true) + 5;
        do {
            clearstatcache();
            if ($condition()) return;
            usleep(10000);
        } while (microtime(true) < $deadline);
        self::fail('Native worker did not reach the expected state.');
    }

    private function stopWorker(int $pid, int $signal): int
    {
        posix_kill($pid, $signal);
        $status = null;
        $this->await(function () use ($pid, &$status): bool { return pcntl_waitpid($pid, $status, WNOHANG) === $pid; });
        unset($this->workerPids[$pid]);
        return $status;
    }

    public function testWorkerHeartbeatAndGracefulStop(): void
    {
        $this->append();
        $pid = $this->startWorker();
        $this->await(fn () => $this->worker->maintain()['pending'] === 0);
        self::assertFileExists('/tmp/live-records-worker-heartbeat');
        self::assertLessThan(5, time() - (int) file_get_contents('/tmp/live-records-worker-heartbeat'));
        $status = $this->stopWorker($pid, SIGTERM);
        self::assertTrue(pcntl_wifexited($status));
        self::assertSame(0, pcntl_wexitstatus($status));
    }

    public function testKilledWorkerRepublishesFreshUnacknowledgedIntent(): void
    {
        $id = $this->append();
        touch($this->directory . '/hold');
        $pid = $this->startWorker();
        $this->await(fn () => count($this->requests()) === 1);
        self::assertTrue(pcntl_wifsignaled($this->stopWorker($pid, SIGKILL)));
        self::assertSame(1, $this->worker->maintain()['pending']);
        unlink($this->directory . '/hold');
        $this->pdo->exec("UPDATE live_record_outbox SET lease_until = '2000-01-01 00:00:00'");
        $restarted = $this->startWorker();
        $this->await(fn () => $this->worker->maintain()['pending'] === 0);
        $this->stopWorker($restarted, SIGTERM);
        self::assertCount(2, $this->requests());
        self::assertSame($id, $this->requests()[1]['eventId']);
    }
}
