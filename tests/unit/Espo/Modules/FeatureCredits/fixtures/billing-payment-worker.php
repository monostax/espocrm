<?php

declare(strict_types=1);

use tests\unit\Espo\Modules\FeatureCredits\BillingPaymentFixtures;

require 'vendor/autoload.php';
require __DIR__ . '/../BillingPaymentFixtures.php';
[$script, $dialect, $id, $now, $mode] = $argv;
$pdo = new PDO(getenv('FEATURE_CREDITS_' . strtoupper($dialect) . '_DSN'), 'credits_test', 'credits_test', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($pdo->query($dialect === 'Mysql' ? 'SELECT DATABASE()' : 'SELECT current_database()')->fetchColumn() !== 'feature_credits_schema_test') throw new RuntimeException('Disposable test database required.');
$pdo->exec($dialect === 'Mysql' ? 'SET SESSION innodb_lock_wait_timeout = 10' : "SET lock_timeout = '10s'");
$f = new BillingPaymentFixtures($pdo, $now);
$f->transport->objects = json_decode(fgets(STDIN), true, 32, JSON_THROW_ON_ERROR);
if ($mode === 'interrupt') $f->transport->onSend = static function () use ($pdo): void {
    if ($pdo->inTransaction()) throw new RuntimeException('I/O under transaction.');
    fwrite(STDOUT, "lookup\n"); fflush(STDOUT); sleep(30);
};
fwrite(STDOUT, "ready\n"); fflush(STDOUT);
try { $output = ['result' => $mode === 'event' ? $f->events->processBatch() : $f->read($id)]; }
catch (Throwable $e) { $output = ['error' => get_class($e), 'message' => $e->getMessage()]; }
$output['reads'] = count($f->transport->calls);
print json_encode($output, JSON_THROW_ON_ERROR) . "\n";
