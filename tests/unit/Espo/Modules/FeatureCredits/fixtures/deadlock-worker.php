<?php

declare(strict_types=1);

use Espo\ORM\QueryComposer\MysqlQueryComposer;
use Espo\ORM\TransactionManager;

require 'vendor/autoload.php';

/*
 * The other side of DeadlockRetryDatabaseTest's lock cycle. Like CandidateApplication, it locks the
 * actor row first and the tenant row second, with raw PDO statements inside TransactionManager::run().
 * On its first attempt it reports "locked" and waits for "go"; later attempts run straight through.
 */
[, $dialect, $ballast, $delayMs] = $argv;
$pdo = new PDO(getenv('FEATURE_CREDITS_' . strtoupper($dialect) . '_DSN'), 'credits_test', 'credits_test',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($pdo->query($dialect === 'Mysql' ? 'SELECT DATABASE()' : 'SELECT current_database()')->fetchColumn() !== 'feature_credits_schema_test') {
    throw new RuntimeException('Disposable test database required.');
}
$pdo->exec($dialect === 'Mysql' ? 'SET SESSION innodb_lock_wait_timeout = 10' : "SET lock_timeout = '10s'");
// Only nested scopes compose savepoint SQL; this transaction is never nested.
$manager = new TransactionManager($pdo, (new ReflectionClass(MysqlQueryComposer::class))->newInstanceWithoutConstructor());
$attempts = 0;
try {
    $manager->run(function () use ($pdo, &$attempts, $ballast, $delayMs): void {
        $attempts++;
        $pdo->query("SELECT id FROM deadlock_probe WHERE id='actor' FOR UPDATE")->fetchColumn();
        if ($attempts === 1) {
            $insert = $pdo->prepare('INSERT INTO deadlock_ballast (id) VALUES (?)');
            for ($i = 0; $i < (int) $ballast; $i++) $insert->execute(["worker-$i"]);
            fwrite(STDOUT, "locked\n");
            if (trim((string) fgets(STDIN)) !== 'go') throw new RuntimeException('Expected go.');
            usleep((int) $delayMs * 1000);
        }
        $pdo->query("SELECT id FROM tenant WHERE id='tenant' FOR UPDATE")->fetchColumn();
        $pdo->exec("UPDATE deadlock_probe SET writes = writes + 1 WHERE id='actor'");
    });
    fwrite(STDOUT, "committed $attempts\n");
} catch (Throwable $e) {
    fwrite(STDOUT, "failed $attempts " . get_class($e) . ': ' . $e->getMessage() . "\n");
}
