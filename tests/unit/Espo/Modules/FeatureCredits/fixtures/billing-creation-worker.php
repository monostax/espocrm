<?php

declare(strict_types=1);

use Espo\Core\Utils\{Config, Crypt};
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Billing\{Accounts, Catalog, Creation, CreationStore};
use Espo\Modules\FeatureCredits\Billing\Asaas\Adapter;
use Espo\ORM\EntityManager;
use tests\unit\Espo\Modules\FeatureCredits\{AsaasTestConnections, AsaasTestTransport, BillingTestProviders};

require 'vendor/autoload.php';
require __DIR__ . '/../AsaasFixtures.php';
require __DIR__ . '/../BillingAccountFixtures.php';
[$script, $dialect, $obligation, $action, $now, $customer] = $argv;
$pdo = new PDO(getenv('FEATURE_CREDITS_' . strtoupper($dialect) . '_DSN'), 'credits_test', 'credits_test', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($pdo->query($dialect === 'Mysql' ? 'SELECT DATABASE()' : 'SELECT current_database()')->fetchColumn() !== 'feature_credits_schema_test') throw new RuntimeException('Disposable test database required.');
$pdo->exec($dialect === 'Mysql' ? 'SET SESSION innodb_lock_wait_timeout = 10' : "SET lock_timeout = '10s'");
$manager = new class ($pdo) extends EntityManager {
    public function __construct(private PDO $connection) {}
    public function getPDO(): PDO { return $this->connection; }
};
$clock = new class ($now) extends Clock {
    public function __construct(private string $time) {}
    public function now(): string { return $this->time; }
};
$config = new class extends Config {
    public function __construct() {}
    public function get($name, $default = null): mixed { return 'billing-creation-test-crypt-key'; }
};
$transport = new AsaasTestTransport();
$transport->onSend = static function () use ($pdo): void { if ($pdo->inTransaction()) throw new RuntimeException('External call inside transaction.'); };
$accounts = new Accounts($manager, $clock, new Catalog($manager, $clock), new BillingTestProviders());
$creation = new Creation(new CreationStore($accounts, new Crypt($config), new AsaasTestConnections()), new Adapter($transport));
fwrite(STDOUT, "ready\n"); fflush(STDOUT);
try {
    $result = match ($action) {
        'create' => $creation->customer('tenant', $obligation, AsaasTestTransport::profile()),
        'charge' => $creation->charge('tenant', $obligation, $customer, 'PIX', '2026-10-15'),
        'cancel' => $accounts->cancel('tenant', 'cancel', $obligation, 'admin'),
        default => throw new RuntimeException('Unknown test action.'),
    };
    $output = ['result' => $result];
} catch (Throwable $e) { $output = ['error' => get_class($e)]; }
$output['posts'] = count(array_filter($transport->calls, static fn ($call) => $call[2] === 'POST'));
print json_encode($output, JSON_THROW_ON_ERROR) . "\n";
