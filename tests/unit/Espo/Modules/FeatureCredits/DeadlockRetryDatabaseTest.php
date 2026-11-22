<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Doctrine\DBAL\Platforms\{MySQL80Platform, PostgreSQLPlatform};
use Espo\Modules\FeatureCredits\Accounting\{Clock, WalletLock};
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SchemaTest.php';

/**
 * 2026-11-21: bulk sócios linking (actor row, then tenant row, raw statements inside
 * TransactionManager::run()) and the enrichment workers (WalletLock: tenant row first) deadlocked
 * on one workspace. Neither side ran again, so 14 of 22 companies went to manual review and a worker
 * crashed. Here a real database cancels one side of such a cycle, each side in turn.
 */
final class DeadlockRetryDatabaseTest extends TestCase
{
    use SchemaFixture;

    public static function cases(): iterable
    {
        foreach (['MySQL' => 'Mysql', 'PostgreSQL' => 'Postgresql'] as $label => $dialect) {
            yield "$label: the transaction manager side is cancelled" => [$dialect, 'worker'];
            yield "$label: the wallet lock side is cancelled" => [$dialect, 'wallet'];
        }
    }

    #[DataProvider('cases')]
    public function testTheCancelledSideOfALockCycleRunsAgainAndBothCommitOnce(string $dialect, string $victim): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect, $victim): void {
            // PostgreSQL cancels the side that waited first; InnoDB the one with less work to undo.
            [$workerBallast, $workerDelay, $walletBallast, $walletDelay] = $victim === 'worker' ? [0, 0, 20, 700] : [20, 700, 0, 0];
            $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/deadlock-worker.php', $dialect, (string) $workerBallast,
                (string) $workerDelay], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, getcwd());
            $this->assertIsResource($process);
            try {
                $ready = fgets($pipes[1]);
                if ($ready === false || trim($ready) !== 'locked') {
                    $this->fail('Worker did not lock: ' . $ready . stream_get_contents($pipes[2]));
                }
                $manager = new class ($pdo) extends EntityManager {
                    public function __construct(private PDO $connection) {}
                    public function getPDO(): PDO { return $this->connection; }
                };
                $clock = new class extends Clock { public function now(): string { return '2026-11-22 12:00:00'; } };
                $attempts = 0;
                (new WalletLock($manager, $clock))->run('tenant', function (PDO $locked) use (&$attempts, $pipes, $walletBallast, $walletDelay): void {
                    $attempts++;
                    if ($attempts === 1) {
                        $insert = $locked->prepare('INSERT INTO deadlock_ballast (id) VALUES (?)');
                        for ($i = 0; $i < $walletBallast; $i++) $insert->execute(["wallet-$i"]);
                        fwrite($pipes[0], "go\n");
                        fflush($pipes[0]);
                        usleep($walletDelay * 1000);
                    }
                    $locked->query("SELECT id FROM deadlock_probe WHERE id='actor' FOR UPDATE")->fetchColumn();
                    $locked->exec("UPDATE deadlock_probe SET writes = writes + 10 WHERE id='actor'");
                });
                $worker = trim((string) stream_get_contents($pipes[1]));
                $errors = (string) stream_get_contents($pipes[2]);
            } finally {
                foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
                proc_close($process);
            }
            $this->assertSame(['committed ' . ($victim === 'worker' ? 2 : 1), $victim === 'wallet' ? 2 : 1], [$worker, $attempts], $errors);
            $this->assertSame(11, (int) $pdo->query("SELECT writes FROM deadlock_probe WHERE id='actor'")->fetchColumn());
        });
    }

    private function database(string $dialect, callable $test): void
    {
        $dsn = getenv('FEATURE_CREDITS_' . strtoupper($dialect) . '_DSN');
        if (!$dsn) $this->markTestSkipped('Requires an empty feature_credits_schema_test database.');
        $pdo = new PDO($dsn, 'credits_test', 'credits_test', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->assertSame('feature_credits_schema_test', $pdo->query($dialect === 'Mysql' ? 'SELECT DATABASE()' : 'SELECT current_database()')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query($dialect === 'Mysql' ? 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()' :
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public'")->fetchColumn(), 'Refusing a nonempty database.');
        $pdo->exec($dialect === 'Mysql' ? 'SET SESSION innodb_lock_wait_timeout = 10' : "SET lock_timeout = '10s'");
        $schema = $this->schema($dialect); $platform = $dialect === 'Mysql' ? new MySQL80Platform() : new PostgreSQLPlatform();
        try {
            foreach ($schema->toSql($platform) as $sql) $pdo->exec($sql);
            $pdo->exec('CREATE TABLE tenant (id VARCHAR(24) PRIMARY KEY, deleted BOOLEAN NOT NULL DEFAULT FALSE)');
            $pdo->exec("INSERT INTO tenant (id) VALUES ('tenant')");
            $pdo->exec('CREATE TABLE deadlock_probe (id VARCHAR(24) PRIMARY KEY, writes INT NOT NULL DEFAULT 0)');
            $pdo->exec("INSERT INTO deadlock_probe (id) VALUES ('actor')");
            $pdo->exec('CREATE TABLE deadlock_ballast (id VARCHAR(24) PRIMARY KEY)');
            $test($pdo);
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach (['deadlock_ballast', 'deadlock_probe', 'tenant'] as $table) $pdo->exec("DROP TABLE IF EXISTS $table");
            foreach (array_reverse($schema->getTables()) as $table) $pdo->exec('DROP TABLE IF EXISTS ' . $platform->quoteIdentifier($table->getName()));
        }
    }
}
