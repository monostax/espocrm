<?php

declare(strict_types=1);

/**
 * Isolated storage contract test. Defaults to SQLite; LIVE_RECORD_TEST_DSN can
 * select a disposable MySQL/PostgreSQL database. Never point at application data.
 * Run: php tests/integration/fixtures/live-record-outbox.php
 */
require __DIR__ . '/../../../vendor/autoload.php';

use Espo\Modules\FeatureLiveRecords\Outbox\Intent;
use Espo\Modules\FeatureLiveRecords\Outbox\Lease;
use Espo\Modules\FeatureLiveRecords\Outbox\Store;

$pdo = new PDO(getenv('LIVE_RECORD_TEST_DSN') ?: 'sqlite::memory:',
    getenv('LIVE_RECORD_TEST_USER') ?: null, getenv('LIVE_RECORD_TEST_PASSWORD') ?: null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$checks = 0;
$check = static function (bool $condition, string $description) use (&$checks): void {
    if (!$condition) throw new RuntimeException($description);
    $checks++;
};
$throws = static function (callable $call, string $class) use ($check): void {
    try { $call(); } catch (Throwable $e) {
        $check($e instanceof $class, 'Unexpected exception type: ' . $e::class);
        return;
    }
    throw new RuntimeException('Expected ' . $class);
};

// CREATE (not DROP/IF NOT EXISTS) intentionally refuses an occupied database.
$pdo->exec('CREATE TABLE live_record_outbox (
    id VARCHAR(17) PRIMARY KEY, tenant_id VARCHAR(64) NOT NULL, intent TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL, due_at TIMESTAMP NOT NULL, attempts INTEGER NOT NULL DEFAULT 0,
    lease_token VARCHAR(64), lease_until TIMESTAMP NULL, completed_at TIMESTAMP NULL, failure_code VARCHAR(32)
)');
$pdo->exec('CREATE TABLE live_record_test_mutation (id INTEGER PRIMARY KEY, version INTEGER NOT NULL, value VARCHAR(64))');
$pdo->exec("INSERT INTO live_record_test_mutation VALUES (1, 1, 'original')");
$store = new Store($pdo);
$now = new DateTimeImmutable('2026-10-11T12:00:00Z');
$intent = new Intent('tenant-a', 'update', [['type' => 'Contact', 'id' => 'contact-a']]);
$append = static function (Intent $value) use ($pdo, $store, $now): string {
    $pdo->beginTransaction();
    try {
        $id = $store->append($value, $now);
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
};

try {
    $throws(fn () => $store->append($intent, $now), LogicException::class);
    $throws(fn () => new Intent('tenant-a', 'arbitrary', $intent->records), InvalidArgumentException::class);
    $throws(fn () => new Intent('tenant-a', 'update', [['type' => 'Contact', 'id' => 'a', 'body' => 'secret']]), InvalidArgumentException::class);

    // A rolled-back mutation cannot leave deliverable notification intent.
    $pdo->beginTransaction();
    $pdo->exec("UPDATE live_record_test_mutation SET value = 'rolled-back'");
    $store->append($intent, $now);
    $throws(fn () => $store->claim($now), LogicException::class);
    $pdo->rollBack();
    $check($store->claim($now) === [], 'Rollback left notification intent');
    $check($pdo->query('SELECT value FROM live_record_test_mutation')->fetchColumn() === 'original', 'Mutation did not roll back');

    // A real SQL outbox insertion failure must roll back the business mutation.
    // Portable SQL failure: temporarily rename the table within this disposable database.
    $pdo->exec('ALTER TABLE live_record_outbox RENAME TO live_record_outbox_unavailable');
    $throws(function () use ($pdo, $store, $intent, $now): void {
        $pdo->beginTransaction();
        try {
            $pdo->exec("UPDATE live_record_test_mutation SET value = 'must-rollback'");
            $store->append($intent, $now);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }, PDOException::class);
    $pdo->exec('ALTER TABLE live_record_outbox_unavailable RENAME TO live_record_outbox');
    $check($pdo->query('SELECT value FROM live_record_test_mutation')->fetchColumn() === 'original', 'Outbox failure committed mutation');

    $id = $append($intent);
    $leases = $store->claim($now);
    $check(count($leases) === 1 && $leases[0]->id === $id && $leases[0]->attempts === 1, 'Initial claim failed');
    $first = $leases[0];
    $check($store->claim($now) === [], 'Concurrent claimant stole a live lease');
    $check(json_decode($first->intent, true, flags: JSON_THROW_ON_ERROR)['records'] === $intent->records, 'Intent changed');
    $forged = new Lease($first->id, 'tenant-b', $first->token, $first->intent, 1);
    $check(!$store->complete($forged, $now), 'Cross-tenant completion accepted');
    $check(!$store->fail($forged, $now, 'internal_error'), 'Cross-tenant retry accepted');

    // Fencing/retry still works inside the five-second freshness window.
    $pdo->exec("UPDATE live_record_outbox SET lease_until = '2026-10-11 12:00:01'");
    $later = $now->modify('+1 second');
    $second = $store->claim($later)[0];
    $check($second->attempts === 2 && $second->token !== $first->token, 'Expired lease not reclaimed');
    $check(!$store->complete($first, $later), 'Stale worker acknowledged newer work');
    $check(!$store->fail($first, $later, 'internal_error'), 'Stale worker rescheduled newer work');
    $check($store->fail($second, $later, 'bridge_unavailable'), 'Bridge failure was not retained');
    $check($store->claim($later) === [], 'Retry backoff was ignored');
    $third = $store->claim($now->modify('+4 seconds'))[0];
    $check($third->attempts === 3, 'Retry lost attempt count');
    $throws(fn () => $store->fail($third, $later, 'exception with secret data'), InvalidArgumentException::class);
    $check($store->complete($third, $now->modify('+4 seconds')), 'Completion failed');
    $check(!$store->complete($third, $now->modify('+4 seconds')), 'Duplicate completion accepted');

    // Retention starts at retirement; expiry is separate from physical cleanup.
    $append(new Intent('tenant-b', 'access', [['type' => 'User', 'id' => 'viewer-b']]));
    $check($store->purgeCompleted($now->modify('+7 days')) === 0, 'Retention measured from creation');
    $check($store->purgeCompleted($now->modify('+8 days')) === 1, 'Completed work not purged');
    $metrics = $store->pendingMetrics($now->modify('+8 days'));
    $check($metrics['pending'] === 1 && $metrics['oldestAgeSeconds'] === 8 * 86400, 'Pending work disappeared');
    $check($store->claim($now->modify('+8 days')) === [], 'Old reset replayed');
    $check($store->expire($now->modify('+8 days')) === 1, 'Old reset was not expired');

    // Malformed hints can retry while fresh, then expire like all other hints.
    $pdo->beginTransaction();
    $store->append($intent, $now->modify('+8 days'));
    $pdo->commit();
    $pdo->exec("UPDATE live_record_outbox SET intent = '{invalid' WHERE completed_at IS NULL");
    $malformed = $store->claim($now->modify('+8 days'))[0];
    $check($store->fail($malformed, $now->modify('+8 days'), 'malformed_intent'), 'Malformed work was discarded');
    $check($store->pendingMetrics($now)['pending'] === 1, 'Malformed intent no longer pending');
    $check($store->expire($now->modify('+8 days +31 seconds')) === 1, 'Malformed hint did not expire');
    $check(Store::retryDelayMilliseconds(1) === 1000, 'Initial retry is not one second');
    foreach ([2, 3, 8, 100, PHP_INT_MAX] as $attempt) {
        $delay = Store::retryDelayMilliseconds($attempt);
        $check($delay >= 1000 && $delay <= 60000, 'Retry delay outside D20 bounds');
    }
    fwrite(STDOUT, "Live record outbox: $checks checks passed (" . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . ").\n");
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->exec('DROP TABLE live_record_outbox');
    $pdo->exec('DROP TABLE live_record_test_mutation');
}
