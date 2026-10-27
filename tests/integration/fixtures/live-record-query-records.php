<?php

declare(strict_types=1);

// Record-scoped invalidation against the real React subprocess path, without a database.
// Run: php tests/integration/fixtures/live-record-query-records.php
$source = dirname(__DIR__, 3);
require $source . '/vendor/autoload.php';
require $source . '/application/Espo/Core/WebSocket/QuerySubscriptions.php';

$directory = sys_get_temp_dir() . '/live-query-records-' . bin2hex(random_bytes(6));
mkdir($directory);
// Two relationship panels declare dependencies; the aggregate declares none.
file_put_contents($directory . '/command.php', <<<'PHP'
<?php
$input = json_decode(stream_get_contents(STDIN), true);
$deps = [
    'tasks' => ['types' => ['Task'], 'ids' => ['conv1', 'task1', 'contact1']],
    'opportunities' => ['types' => ['Opportunity'], 'ids' => ['conv1', 'opp1']],
];
foreach ($input['topics'] as $index => $topic) {
    file_put_contents('checks.log', $topic . "\n", FILE_APPEND);
    $result = $topic === 'tasks' && is_file('deny-tasks')
        ? ['denied' => true, 'tenantIds' => ['tenant-a']]
        : ['fingerprint' => str_repeat('a', 64), 'tenantIds' => ['tenant-a']] + (isset($deps[$topic]) ? ['deps' => $deps[$topic]] : []);
    echo json_encode(['index' => $index, 'result' => $result]), "\n";
}
PHP);
$cwd = getcwd();
chdir($directory);
$loop = new React\EventLoop\StreamSelectLoop();
$connection = new class implements Ratchet\ConnectionInterface {
    public int $resourceId = 1;
    public function send($data) { return $this; }
    public function close() {}
    public function event($topic, $event): void {}
};
$checks = static fn (): array => array_count_values(array_filter(explode("\n", (string) @file_get_contents('checks.log'))));
$subscriptions = new Espo\Core\WebSocket\QuerySubscriptions(PHP_BINARY, $loop);
$config = ['queryCheckCommand' => 'projection', 'invalidationTopic' => 'liveRecordChange'];
$failures = [];
$step = static function (float $at, string $label, ?string $tenant, ?array $records, array $expected, ?callable $before = null)
    use ($loop, $subscriptions, $checks, &$failures): void {
    $loop->addTimer($at, function () use ($subscriptions, $checks, $tenant, $records, $expected, $label, $before, $loop, &$failures) {
        if ($before) $before();
        $start = $checks();
        $subscriptions->invalidate('liveRecordChange', $tenant, $records);
        $loop->addTimer(2, function () use ($checks, $start, $expected, $label, &$failures) {
            $now = $checks();
            $ran = array_values(array_filter(['tasks', 'opportunities', 'counts'],
                static fn ($topic) => ($now[$topic] ?? 0) > ($start[$topic] ?? 0)));
            if ($ran !== $expected) $failures[] = "$label: re-checked [" . implode(', ', $ran) . '], expected [' . implode(', ', $expected) . ']';
        });
    });
};
$contact = static fn (string $id) => [['type' => 'Contact', 'id' => $id]];
try {
    foreach (['tasks', 'opportunities', 'counts'] as $topic) $subscriptions->subscribe($connection, 'viewer', 'token', $topic, $config);
    $step(6, 'unrelated contact', 'tenant-a', $contact('contact2'), ['counts']);
    $step(12, 'contact shown in tasks', 'tenant-a', $contact('contact1'), ['tasks', 'counts']);
    $step(18, 'new task (possible member)', 'tenant-a', [['type' => 'Task', 'id' => 'task9'], ['type' => 'Contact', 'id' => 'contact7']], ['tasks', 'counts']);
    $step(24, 'access reset', 'tenant-a', null, ['tasks', 'opportunities', 'counts']);
    $step(30, 'other tenant', 'tenant-b', $contact('contact1'), []);
    $step(36, 'access reset while tasks becomes denied', 'tenant-a', null, ['tasks', 'opportunities', 'counts'],
        static fn () => touch('deny-tasks'));
    $step(42, 'denied panel lost its dependencies', 'tenant-a', $contact('contact2'), ['tasks', 'counts']);
    $loop->addTimer(45, fn () => $loop->stop());
    $loop->run();
    if ($failures) throw new RuntimeException(implode("\n", $failures));
    echo "Record-scoped invalidation: 7 checks passed.\n";
} finally {
    $subscriptions->unsubscribe($connection);
    chdir($cwd);
    foreach (glob($directory . '/*') as $file) unlink($file);
    rmdir($directory);
}
