<?php

declare(strict_types=1);

// Actual React subprocess/timeout regression, without a database or credentials.
$source = dirname(__DIR__, 3);
require $source . '/vendor/autoload.php';
require $source . '/application/Espo/Core/WebSocket/QuerySubscriptions.php';

$directory = sys_get_temp_dir() . '/live-query-stream-' . bin2hex(random_bytes(6));
mkdir($directory);
file_put_contents($directory . '/command.php', <<<'PHP'
<?php
$input = json_decode(stream_get_contents(STDIN), true);
if (($input['stream'] ?? false) !== true) exit(2);
$first = !is_file('started');
file_put_contents('started', '1');
foreach ($input['topics'] as $index => $topic) {
    $line = json_encode(['index' => $index, 'result' => ['fingerprint' => str_repeat($first ? 'a' : 'b', 64)]]) . "\n";
    // Deliberately split a JSON line across pipe chunks.
    fwrite(STDOUT, substr($line, 0, 17));
    usleep(20000);
    fwrite(STDOUT, substr($line, 17));
    if ($first) sleep(30); // Killed by the real 15-second inactivity timeout.
}
PHP);
$cwd = getcwd();
chdir($directory);
$loop = new React\EventLoop\StreamSelectLoop();
$connection = new class implements Ratchet\ConnectionInterface {
    public int $resourceId = 1;
    public array $events = [];
    public function send($data) { return $this; }
    public function close() {}
    public function event($topic, $event): void { $this->events[] = [$topic, $event, microtime(true)]; }
};
$subscriptions = new Espo\Core\WebSocket\QuerySubscriptions(PHP_BINARY, $loop);
$config = ['queryCheckCommand' => 'projection', 'invalidationTopic' => 'liveRecordChange'];
$start = microtime(true);
try {
    $subscriptions->subscribe($connection, 'viewer', 'test-token', 'account', $config);
    $subscriptions->subscribe($connection, 'viewer', 'test-token', 'contact', $config);
    $loop->addTimer(16, fn () => $subscriptions->invalidate('liveRecordChange'));
    $loop->addTimer(18, fn () => $loop->stop());
    $loop->run();
    $events = $connection->events;
    if (count($events) !== 3) throw new RuntimeException('Expected retained partial result and two recovered results.');
    if ($events[0][0] !== 'account' || $events[0][1]->signal !== 'ready' || $events[0][2] - $start >= 5) {
        throw new RuntimeException('Completed Account projection waited for the stalled batch.');
    }
    if ($events[1][0] !== 'contact' || $events[1][1]->signal !== 'ready') {
        throw new RuntimeException('Unfinished projection was not prioritized on recovery.');
    }
    if ($events[2][0] !== 'account' || $events[2][1]->signal !== 'invalidate' || $events[2][1]->fingerprint !== str_repeat('b', 64)) {
        throw new RuntimeException('Account rename fingerprint was not delivered after recovery.');
    }
    echo "4 streamed projection/timeout/recovery checks passed.\n";
} finally {
    $subscriptions->unsubscribe($connection);
    chdir($cwd);
    foreach (glob($directory . '/*') as $file) unlink($file);
    rmdir($directory);
}
