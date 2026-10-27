<?php

declare(strict_types=1);

// Tenant-scoped invalidation against the real React subprocess path, without a database.
// Run: php tests/integration/fixtures/live-record-query-tenants.php
$source = dirname(__DIR__, 3);
require $source . '/vendor/autoload.php';
require $source . '/application/Espo/Core/WebSocket/QuerySubscriptions.php';

$directory = sys_get_temp_dir() . '/live-query-tenants-' . bin2hex(random_bytes(6));
mkdir($directory);
// Each viewer's projection reads its own tenant's state file; checks are logged per viewer.
file_put_contents($directory . '/command.php', <<<'PHP'
<?php
$input = json_decode(stream_get_contents(STDIN), true);
$tenants = ['viewer-a' => ['tenant-a'], 'viewer-b' => ['tenant-b'], 'admin' => []][$input['userId']];
file_put_contents('checks.log', $input['userId'] . "\n", FILE_APPEND);
$state = $tenants ? (string) @file_get_contents('state-' . $tenants[0]) : (string) @file_get_contents('state-tenant-a') . (string) @file_get_contents('state-tenant-b');
foreach ($input['topics'] as $index => $topic) {
    echo json_encode(['index' => $index, 'result' => ['fingerprint' => hash('sha256', $state), 'tenantIds' => $tenants]]), "\n";
}
PHP);
$cwd = getcwd();
chdir($directory);
$loop = new React\EventLoop\StreamSelectLoop();
$connection = static fn (int $id) => new class($id) implements Ratchet\ConnectionInterface {
    public array $events = [];
    public function __construct(public int $resourceId) {}
    public function send($data) { return $this; }
    public function close() {}
    public function event($topic, $event): void { $this->events[] = $event->signal; }
};
$checks = static fn (): array => array_count_values(array_filter(explode("\n", (string) @file_get_contents('checks.log'))));
$subscriptions = new Espo\Core\WebSocket\QuerySubscriptions(PHP_BINARY, $loop);
$config = ['queryCheckCommand' => 'projection', 'invalidationTopic' => 'liveRecordChange'];
$a = $connection(1);
$b = $connection(2);
$admin = $connection(3);
$failures = [];
$expect = static function (bool $ok, string $message) use (&$failures): void { if (!$ok) $failures[] = $message; };
try {
    $subscriptions->subscribe($a, 'viewer-a', 'token', 'list', $config);
    $subscriptions->subscribe($b, 'viewer-b', 'token', 'list', $config);
    $subscriptions->subscribe($admin, 'admin', 'token', 'list', $config);
    // t=6: tenant-a changes. Viewer B must not be re-checked; A and the tenant-less admin must.
    $loop->addTimer(6, function () use ($subscriptions, $checks, &$before) {
        $before = $checks();
        file_put_contents('state-tenant-a', 'a1');
        $subscriptions->invalidate('liveRecordChange', 'tenant-a');
    });
    $loop->addTimer(8, function () use ($checks, &$before, $expect, $a, $b, $admin) {
        $now = $checks();
        $expect(($now['viewer-a'] ?? 0) === ($before['viewer-a'] ?? 0) + 1, 'tenant-a hint did not re-check viewer A');
        $expect(($now['viewer-b'] ?? 0) === ($before['viewer-b'] ?? 0), 'tenant-a hint re-checked viewer B');
        $expect(($now['admin'] ?? 0) === ($before['admin'] ?? 0) + 1, 'tenant-a hint skipped the tenant-less viewer');
        $expect($a->events === ['ready', 'invalidate'], 'viewer A did not receive the tenant-a change');
        $expect($b->events === ['ready'], 'viewer B received a signal for tenant-a');
        $expect($admin->events === ['ready', 'invalidate'], 'tenant-less viewer missed the tenant-a change');
    });
    // t=14: an unscoped sweep re-checks everyone, so a missed hint still surfaces.
    $loop->addTimer(14, function () use ($subscriptions, $checks, &$before) {
        $before = $checks();
        file_put_contents('state-tenant-b', 'b1');
        $subscriptions->invalidate();
    });
    $loop->addTimer(16, function () use ($checks, &$before, $expect, $b) {
        $now = $checks();
        foreach (['viewer-a', 'viewer-b', 'admin'] as $viewer) {
            $expect(($now[$viewer] ?? 0) === ($before[$viewer] ?? 0) + 1, "sweep did not re-check $viewer");
        }
        $expect($b->events === ['ready', 'invalidate'], 'sweep did not surface the tenant-b change');
    });
    $loop->addTimer(17, fn () => $loop->stop());
    $loop->run();
    if ($failures) throw new RuntimeException(implode("\n", $failures));
    echo "Tenant-scoped invalidation: 10 checks passed.\n";
} finally {
    $subscriptions->unsubscribe($a);
    $subscriptions->unsubscribe($b);
    $subscriptions->unsubscribe($admin);
    chdir($cwd);
    foreach (glob($directory . '/*') as $file) unlink($file);
    rmdir($directory);
}
