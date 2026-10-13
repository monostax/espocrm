<?php

declare(strict_types=1);

use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Enrichment\Catalog;
use Espo\ORM\EntityManager;

require 'vendor/autoload.php';

[$script, $dialect, $payload, $now] = $argv;
$pdo = new PDO(getenv('FEATURE_CREDITS_' . strtoupper($dialect) . '_DSN'), 'credits_test', 'credits_test',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($pdo->query($dialect === 'Mysql' ? 'SELECT DATABASE()' : 'SELECT current_database()')->fetchColumn() !== 'feature_credits_schema_test') {
    throw new RuntimeException('Disposable test database required.');
}
$pdo->exec($dialect === 'Mysql' ? 'SET SESSION innodb_lock_wait_timeout = 10' : "SET lock_timeout = '10s'");
$manager = new class ($pdo) extends EntityManager {
    public function __construct(private PDO $connection) {}
    public function getPDO(): PDO { return $this->connection; }
};
$clock = new class ($now) extends Clock {
    public function __construct(private string $time) {}
    public function now(): string { return $this->time; }
};
fwrite(STDOUT, "ready\n");
fflush(STDOUT);
try {
    $result = (new Catalog($manager, $clock))->publish(json_decode(base64_decode($payload), false, 32, JSON_THROW_ON_ERROR), 'admin');
    print json_encode(['result' => $result], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    print json_encode(['error' => get_class($e)], JSON_THROW_ON_ERROR) . "\n";
}
