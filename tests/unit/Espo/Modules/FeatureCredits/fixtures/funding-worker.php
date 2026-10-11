<?php

declare(strict_types=1);

use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Accounting\Funding;
use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use Espo\Modules\FeatureCredits\Accounting\ReservationInput;
use Espo\Modules\FeatureCredits\Accounting\Reservations;
use Espo\Modules\FeatureCredits\Accounting\SourceReference;
use Espo\Modules\FeatureCredits\Accounting\RequestInput;
use Espo\Modules\FeatureCredits\Accounting\Requests;
use Espo\Modules\FeatureCredits\Accounting\OutcomeInput;
use Espo\Modules\FeatureCredits\Accounting\Outcomes;
use Espo\Modules\FeatureCredits\Accounting\Settlements;
use Espo\Modules\FeatureCredits\Accounting\Reconciliation;
use Espo\Modules\FeatureCredits\Accounting\ReconciliationInput;
use Espo\Modules\FeatureCredits\Accounting\ReconciliationPolicy;
use Espo\Modules\FeatureCredits\Pricing\AiRate;
use Espo\Modules\FeatureCredits\Accounting\WalletLock;
use Espo\Modules\FeatureCredits\Accounting\CutoverInput;
use Espo\Modules\FeatureCredits\Accounting\Cutovers;
use Espo\Modules\FeatureCredits\Accounting\ExecutionIdentity;
use Espo\ORM\EntityManager;

require 'vendor/autoload.php';

// Independent process/connection; no inherited transaction/socket or simulated database locks.
[$script, $dialect, $operation, $key, $credits, $now, $expires] = $argv;
$pdo = new PDO(getenv('FEATURE_CREDITS_' . strtoupper($dialect) . '_DSN'), 'credits_test', 'credits_test',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($pdo->query($dialect === 'Mysql' ? 'SELECT DATABASE()' : 'SELECT current_database()')->fetchColumn() !==
    'feature_credits_schema_test') {
    throw new RuntimeException('Disposable test database required.');
}
if ($dialect === 'Mysql') {
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 10');
} else {
    $pdo->exec("SET lock_timeout = '10s'");
}
$manager = new class ($pdo) extends EntityManager {
    public function __construct(private PDO $connection) {}
    public function getPDO(): PDO { return $this->connection; }
};
$clock = new class ($now) extends Clock {
    public function __construct(private string $time) {}
    public function now(): string { return $this->time; }
};
$lock = new WalletLock($manager, $clock);
$funding = new Funding($lock);
fwrite(STDOUT, "ready\n");
fflush(STDOUT);
try {
    $result = match ($operation) {
        'agreement' => (new \Espo\Modules\FeatureCredits\Services\Configuration($manager, $clock,
            new \Espo\Modules\FeatureCredits\Pricing\AiCatalog($manager)))
            ->publishAgreement(json_decode(base64_decode($key), false, 32, JSON_THROW_ON_ERROR)),
        'dispatch' => (new \Espo\Modules\FeatureCredits\Accounting\DispatchStore($manager, $clock, new Outcomes($lock),
            new Reservations($lock, $funding), new Settlements($lock, $funding)))
            ->execute(new \Espo\Modules\FeatureCredits\Accounting\DispatchInput(json_decode(base64_decode($key), false, 32, JSON_THROW_ON_ERROR))),
        'cutover' => (new Cutovers($manager, $clock))->schedule(new CutoverInput('tenant', $expires ?: $now, (object) ['approval' => $credits])),
        'request-execution' => (new Requests($lock, $funding))->authorize(
            (new \Espo\Modules\FeatureCredits\Pricing\AiModelPolicy(['provider' => 'fixture-provider', 'model' => 'fixture-model',
                'multiplier' => '1', 'inputTokenBound' => 1000, 'outputTokenLimit' => (int) $credits, 'boundProfile' => 'fixture-text-v1']))
                ->request('tenant', $key, '00000000-0000-4000-8000-000000000000', $expires),
            new ExecutionIdentity('aaaaaaaaaaaaaaaaa', 'workflow', '00000000-0000-4000-8000-000000000000')),
        'reserve-execution' => (new Reservations($lock, $funding))->reserve(new ReservationInput(
            'tenant', 'ai', 'ai-run:' . $key, '00000000-0000-4000-8000-000000000000', 'rate', $credits,
            execution: new ExecutionIdentity($key, $expires ?: 'workflow', '00000000-0000-4000-8000-000000000000'))),
        'reconcile' => (new Reconciliation($lock))->recordUnavailable(new ReconciliationInput(
            'tenant', $key, 'execution', $expires, $credits, 'operator', $now,
            new ReconciliationPolicy('test-v1', 'operator', 120, 60, 2),
            (object) ['source' => 'provider', 'reference' => $credits, 'reason' => 'usage unavailable'],
        )),
        'cancelled-outcome' => (new Outcomes($lock))->record(new OutcomeInput(
            'tenant', $key, 'execution', $expires, 'cancelled', 0, 0, (int) $credits, (object) ['source' => 'provider'], 'provider-request',
        )),
        'settle' => (new Settlements($lock, $funding))->settle('tenant', $key, 'execution'),
        'outcome' => (new Outcomes($lock))->record(new OutcomeInput(
            'tenant', $key, 'execution', $expires, 'success', 0, 0, (int) $credits, (object) ['source' => 'provider'],
        )),
        'request' => (new Requests($lock, $funding))->authorize(new RequestInput(
            'tenant', $key, 'execution', $expires, new AiRate('model-v1', 'provider', 'model', '1'), 0, (int) $credits,
        )),
        'reserve' => (new Reservations($lock, $funding))->reserve(new ReservationInput('tenant', 'ai', $key, 'execution', 'rate', $credits)),
        'reserve-source' => (new Reservations($lock, $funding))->reserve(new ReservationInput(
            'tenant', 'ai', $key, 'execution', 'rate', $credits, new SourceReference('Opportunity', $expires))),
        'release' => (new Reservations($lock, $funding))->release('tenant', $key, 'execution'),
        'expire' => $funding->expire('tenant'),
        default => $funding->grant(new GrantInput(
        'tenant', $expires ? 'subscription' : 'purchase', $key, $credits,
        '2026-10-01 00:00:00', $expires ?: null, (object) ['verifiedSource' => $key],
        )),
    };
    fwrite(STDOUT, json_encode(['result' => $result], JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $e) {
    fwrite(STDOUT, json_encode(['error' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR) . "\n");
}
