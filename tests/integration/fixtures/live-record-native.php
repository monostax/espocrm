<?php

declare(strict_types=1);

// Disposable full application + actual native WAMP server. Never uses application credentials.
require __DIR__ . '/live-record-runtime.php';

use Espo\Core\Authentication\AuthToken\Data;
use Espo\Core\Authentication\AuthToken\Manager as TokenManager;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Core\WebSocket\Sender;
use Espo\Modules\FeatureLiveRecords\Subscription\Projection;

$role = $em->createEntity('Role', [
    'name' => 'Native own-record readers',
    'data' => (object) ['Account' => (object) ['read' => 'own', 'edit' => 'no', 'delete' => 'no', 'create' => 'no', 'stream' => 'no']],
]);
$viewers = [];
$tokens = $app->getContainer()->getByClass(TokenManager::class);
foreach ([1, 2] as $number) {
    $viewer = $em->createEntity('User', [
        'userName' => 'native-reader-' . $number, 'emailAddress' => 'native-reader-' . $number . '@example.test',
        'firstName' => 'Native', 'lastName' => (string) $number, 'type' => 'regular', 'isActive' => true,
    ]);
    $em->getRelation($viewer, 'roles')->relate($role);
    $em->getRelation($tenant, 'users')->relate($viewer);
    $viewers[] = [$viewer, $tokens->create(Data::create(['userId' => $viewer->getId(), 'passwordVersion' => $viewer->getPasswordVersion() ?? 0]))];
}
$visible = $em->createEntity('Account', ['name' => 'Native visible', 'tenantId' => $tenant->getId(), 'assignedUserId' => $viewers[0][0]->getId()]);
// Link-multiple fields must have stable IDs even when the configured name
// ordering ties. Otherwise an unchanged detail produces false invalidations.
$tiedTeams = [
    $em->createEntity('Team', ['name' => 'Native order tie']),
    $em->createEntity('Team', ['name' => 'Native order tie']),
];
usort($tiedTeams, static fn ($a, $b) => strcmp($b->getId(), $a->getId()));
foreach ($tiedTeams as $team) $em->getRelation($visible, 'teams')->relate($team);
$visible->loadLinkMultipleField('teams');
$expectedTeamIds = array_map(static fn ($team) => $team->getId(), $tiedTeams);
sort($expectedTeamIds, SORT_STRING);
$check($visible->get('teamsIds') === $expectedTeamIds, 'Tied link-multiple values lack deterministic ID order.');
$em->getRelation($visible, 'teams')->unrelate($tiedTeams[0]);
$em->getRelation($visible, 'teams')->relate($tiedTeams[0]);
$visible->loadLinkMultipleField('teams');
$check($visible->get('teamsIds') === $expectedTeamIds, 'Link reinsertion shuffled the authorized detail.');
$visible = $em->getEntityById('Account', $visible->getId());
$writer = $app->getInjectableFactory()->create(ConfigWriter::class);
foreach (['useWebSocket' => true, 'webSocketPort' => '3080', 'webSocketUrl' => 'ws://127.0.0.1:3080', 'webSocketMessager' => 'ZeroMQ',
    'webSocketZeroMQSubmissionDsn' => 'tcp://127.0.0.1:5777', 'webSocketZeroMQSubscriberDsn' => 'tcp://127.0.0.1:5777'] as $key => $value) $writer->set($key, $value);
$writer->save();
copy($source . '/websocket.php', $runtime . '/websocket.php');
$topic = static function (string $path, string $query = ''): string {
    return 'liveQuery.' . rtrim(strtr(base64_encode(json_encode([
        'path' => $path, 'query' => $query, 'fingerprint' => str_repeat('0', 64),
    ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
};
$run = static function (array $viewer, string $projection) use ($runtime): array {
    $process = proc_open([PHP_BINARY, 'command.php', 'live-record-query'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $runtime);
    fwrite($pipes[0], json_encode(['userId' => $viewer[0]->getId(), 'token' => $viewer[1]->getToken(), 'topic' => $projection]));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) throw new RuntimeException('Projection command failed: ' . $code . ' ' . $output . ' ' . $error);
    return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
};
$listTopic = $topic('/Account', http_build_query(['select' => 'id,name', 'maxSize' => '20', 'orderBy' => 'id', 'order' => 'asc']));
$first = $run($viewers[0], $listTopic);
$second = $run($viewers[1], $listTopic);
$check(isset($first['fingerprint'], $second['fingerprint']) && $first !== $second, 'Viewer-specific projections are not isolated.');
$check(isset($run($viewers[0], $topic('/Account/' . $visible->getId()))['fingerprint']), 'Authorized detail denied.');
$check($run($viewers[1], $topic('/Account/' . $visible->getId())) === ['denied' => true], 'Hidden detail projection allowed.');
$check(!Projection::accepts('/User') && !Projection::accepts('/Account/action/massDelete') && !Projection::accepts('https://example.test/Account'), 'Projection allowlist is not bounded.');

// Start the existing native server, including its real token/ACL subprocesses.
$server = proc_open([PHP_BINARY, 'websocket.php'], [['file', '/dev/null', 'r'], ['file', $runtime . '/socket.log', 'a'], ['file', $runtime . '/socket.log', 'a']], $serverPipes, $runtime);
file_put_contents('/tmp/live-records-native-runtime', $runtime);
$http = proc_open([PHP_BINARY, '-S', '127.0.0.1:3081', $source . '/tests/integration/fixtures/live-record-native-router.php'],
    [['file', '/dev/null', 'r'], ['file', $runtime . '/api.log', 'a'], ['file', $runtime . '/api.log', 'a']], $httpPipes, $runtime);
$sockets = [];
$send = static function ($socket, array $frame): void {
    $body = json_encode($frame, JSON_THROW_ON_ERROR);
    $mask = random_bytes(4);
    $size = strlen($body);
    $header = chr(0x81) . ($size < 126 ? chr(0x80 | $size) : chr(0x80 | 126) . pack('n', $size));
    for ($i = 0; $i < $size; $i++) $body[$i] = $body[$i] ^ $mask[$i % 4];
    fwrite($socket, $header . $mask . $body);
};
$receive = static function ($socket, float $timeout = 10): ?array {
    $read = [$socket]; $write = []; $except = [];
    if (!stream_select($read, $write, $except, (int) $timeout, (int) (($timeout - (int) $timeout) * 1000000))) return null;
    $header = fread($socket, 2);
    if (strlen($header) < 2) return null;
    $length = ord($header[1]) & 127;
    if ($length === 126) $length = unpack('n', fread($socket, 2))[1];
    if ($length === 127) throw new RuntimeException('Oversized socket result.');
    $body = '';
    while (strlen($body) < $length) $body .= fread($socket, $length - strlen($body));
    return json_decode($body, true);
};
try {
    usleep(300000);
    $headers = [];
    $curl = curl_init('http://127.0.0.1:3081/api/v1/Account?' . http_build_query(['select' => 'id,name', 'maxSize' => '20', 'orderBy' => 'id', 'order' => 'asc']));
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_COOKIE => 'auth-token=' . $viewers[0][1]->getToken(),
        CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$headers): int {
            if (str_contains($header, ':')) { [$name, $value] = explode(':', $header, 2); $headers[strtolower(trim($name))] = trim($value); }
            return strlen($header);
        },
    ]);
    $body = curl_exec($curl);
    $check(curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200, 'Real cookie API request failed: ' . (string) $body);
    $check(($headers['x-live-record-fingerprint'] ?? null) === $first['fingerprint'], 'Authenticated API baseline differs from native projection.');
    $check(str_contains($headers['access-control-expose-headers'] ?? '', 'X-Live-Record-Fingerprint'), 'Browser cannot read projection baseline header.');
    curl_setopt($curl, CURLOPT_URL, 'http://127.0.0.1:3081/api/v1/LiveRecords/context');
    $context = json_decode(curl_exec($curl), true);
    $check(($context['userId'] ?? null) === $viewers[0][0]->getId() && ($context['url'] ?? null) === 'ws://127.0.0.1:3080', 'Native context does not use the real CRM viewer/endpoint.');
    curl_close($curl);
    foreach ($viewers as $viewer) {
        $socket = false;
        for ($attempt = 0; $attempt < 100 && !$socket; $attempt++) {
            $socket = @stream_socket_client('tcp://127.0.0.1:3080', $errno, $error, 0.1);
            if (!$socket) usleep(100000);
        }
        if (!$socket) throw new RuntimeException('Native server did not start: ' . file_get_contents($runtime . '/socket.log'));
        stream_set_timeout($socket, 10);
        $path = '/?userId=' . $viewer[0]->getId() . '&authToken=' . $viewer[1]->getToken();
        fwrite($socket, "GET $path HTTP/1.1\r\nHost: localhost:3080\r\nOrigin: https://chat.example.test\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: " . base64_encode(random_bytes(16)) . "\r\nSec-WebSocket-Protocol: wamp\r\nSec-WebSocket-Version: 13\r\n\r\n");
        $status = fgets($socket);
        $check(str_contains($status ?: '', '101'), 'Native WebSocket upgrade failed: ' . $status . ' ' . file_get_contents($runtime . '/socket.log'));
        while (($line = fgets($socket)) !== false && trim($line) !== '') {}
        $check(($receive($socket)[0] ?? null) === 0, 'Native CRM token authentication failed.');
        $send($socket, [5, $listTopic]);
        $frame = $receive($socket);
        $check(($frame[0] ?? null) === 8 && ($frame[2]['signal'] ?? null) === 'ready', 'Projection subscription did not authorize: ' . json_encode($frame));
        $sockets[] = $socket;
    }
    $detailTopic = $topic('/Account/' . $visible->getId());
    $pageTopic = $topic('/Account', 'select=id%2Cname&maxSize=1');
    $send($sockets[0], [5, $detailTopic]);
    $send($sockets[0], [5, $pageTopic]);
    $batchFrames = [$receive($sockets[0]), $receive($sockets[0])];
    $check(array_column($batchFrames, 1) === [$detailTopic, $pageTopic], 'Same-viewer projection batch lost subscriptions.');
    $check(array_map(static fn ($frame) => $frame[2]['signal'] ?? null, $batchFrames) === ['ready', 'ready'], 'Projection batch did not authorize both reads.');
    $send($sockets[0], [6, $detailTopic]);
    $send($sockets[0], [6, $pageTopic]);
    $nativeTopic = 'recordUpdate.Account.' . $visible->getId();
    foreach ($sockets as $socket) $send($socket, [5, $nativeTopic]);
    usleep(800000);
    $sender = $app->getInjectableFactory()->create(\Espo\Core\WebSocket\SenderFactory::class)->create();
    $sender->send(json_encode(['topicId' => $nativeTopic]));
    $check(($receive($sockets[0])[1] ?? null) === $nativeTopic, 'Authorized native topic did not receive.');
    $check($receive($sockets[1], 0.5) === null, 'Rejected subscriber received native broadcast.');
    $send($sockets[0], [6, $nativeTopic]);
    usleep(100000);
    $sender->send(json_encode(['topicId' => $nativeTopic]));
    $check($receive($sockets[0], 0.5) === null, 'Unsubscribed native topic still received.');
    $visible->set('name', 'Changed for viewer one');
    $pdo->exec('DELETE FROM live_record_outbox');
    $em->saveEntity($visible);
    $worker = $app->getInjectableFactory()->create(\Espo\Modules\FeatureLiveRecords\Delivery\Worker::class);
    $check($worker->tick(), 'Committed mutation did not reach native publisher through the worker.');
    $check(($receive($sockets[0])[2]['signal'] ?? null) === 'invalidate', 'Authorized list update missed.');
    $check($receive($sockets[1], 0.8) === null, 'Hidden mutation caused a collection hint.');
    $visible = $em->getEntityById('Account', $visible->getId());
    $visible->set('assignedUserId', $viewers[1][0]->getId());
    $em->saveEntity($visible);
    $sender->send(json_encode(['topicId' => 'liveRecordChange', 'intentCreatedAtMs' => (int) (microtime(true) * 1000)]));
    foreach ($sockets as $socket) $check(($receive($socket)[2]['signal'] ?? null) === 'invalidate', 'Uncached entry/removal missed.');
    $tokens->inactivate($viewers[1][1]);
    $sender->send(json_encode(['topicId' => 'liveRecordChange', 'intentCreatedAtMs' => (int) (microtime(true) * 1000)]));
    $check(($receive($sockets[1])[2]['signal'] ?? null) === 'sessionExpired', 'Revoked token remained subscribed.');
    fwrite(STDOUT, "Native CRM socket/projection checks passed; total with runtime fixture: $checks.\n");
} finally {
    foreach ($sockets as $socket) fclose($socket);
    proc_terminate($server);
    proc_close($server);
    proc_terminate($http);
    proc_close($http);
}
