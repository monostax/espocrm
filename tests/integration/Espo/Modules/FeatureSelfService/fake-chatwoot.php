<?php

/**
 * A stand-in for the Chatwoot Platform and account APIs that the CRM calls
 * while provisioning a workspace. Run it with PHP's built-in server:
 *
 *     FAKE_CHATWOOT_STATE=/tmp/fake-chatwoot.json php -S 0.0.0.0:8080 fake-chatwoot.php
 *
 * Every call is logged. Tests read the log and queue failures through:
 *
 *     GET  /__calls                    the call log
 *     POST /__fault {"call": "POST /platform/api/v1/users", "times": 1, "status": 500}
 *     POST /__reset
 */

$stateFile = getenv('FAKE_CHATWOOT_STATE') ?: sys_get_temp_dir() . '/fake-chatwoot.json';
$method = $_SERVER['REQUEST_METHOD'];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];

$lock = fopen($stateFile . '.lock', 'c');
flock($lock, LOCK_EX);

$state = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : null;
$state ??= ['nextId' => 100, 'accounts' => [], 'users' => [], 'accountUsers' => [], 'calls' => [], 'faults' => []];

$reply = function (int $status, array $payload) use (&$state, $stateFile, $lock): never {
    file_put_contents($stateFile, json_encode($state));
    flock($lock, LOCK_UN);
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
};

if ($path === '/__calls') {
    $reply(200, $state['calls']);
}

if ($path === '/__reset') {
    $state = null;
    @unlink($stateFile);
    flock($lock, LOCK_UN);
    echo '{}';
    exit;
}

if ($path === '/__fault') {
    $state['faults'][] = $body;
    $reply(200, []);
}

$call = $method . ' ' . preg_replace('#/\d+#', '/:id', $path);
$state['calls'][] = $call;

foreach ($state['faults'] as $i => $fault) {
    if ($fault['call'] === $call && $fault['times'] > 0) {
        $state['faults'][$i]['times']--;
        // "afterCreate" lets the request take effect and then loses the reply.
        if (empty($fault['afterCreate'])) {
            $reply((int) $fault['status'], ['error' => 'injected fault']);
        }
        $state['lostReply'] = (int) $fault['status'];
    }
}

$finish = function (int $status, array $payload) use (&$state, $reply): never {
    if (isset($state['lostReply'])) {
        $lost = $state['lostReply'];
        unset($state['lostReply']);
        $reply($lost, ['error' => 'injected fault after the change was made']);
    }
    $reply($status, $payload);
};

$id = function () use (&$state): int {
    return $state['nextId']++;
};

if (preg_match('#^/platform/api/v1/accounts$#', $path) && $method === 'POST') {
    $accountId = $id();
    $state['accounts'][$accountId] = ['id' => $accountId, 'name' => $body['name'] ?? ''];
    $finish(200, $state['accounts'][$accountId]);
}

if (preg_match('#^/platform/api/v1/accounts/(\d+)$#', $path, $m)) {
    $account = $state['accounts'][$m[1]] ?? null;
    $account ? $finish(200, $account) : $finish(404, ['error' => 'not found']);
}

if (preg_match('#^/platform/api/v1/accounts/(\d+)/account_users$#', $path, $m) && $method === 'POST') {
    $key = $m[1] . ':' . $body['user_id'];
    $state['accountUsers'][$key] ??= ['id' => $id(), 'account_id' => (int) $m[1], 'user_id' => (int) $body['user_id']];
    $state['accountUsers'][$key]['role'] = $body['role'] ?? 'agent';
    $finish(200, $state['accountUsers'][$key]);
}

if (preg_match('#^/platform/api/v1/users$#', $path) && $method === 'POST') {
    foreach ($state['users'] as $user) {
        if ($user['email'] === ($body['email'] ?? null)) {
            $finish(409, ['error' => 'User already exists', 'id' => $user['id']]);
        }
    }
    $userId = $id();
    $state['users'][$userId] = [
        'id' => $userId,
        'name' => $body['name'] ?? '',
        'email' => $body['email'] ?? '',
        'access_token' => 'token-' . $userId,
        'custom_attributes' => $body['custom_attributes'] ?? [],
    ];
    $finish(200, $state['users'][$userId] + ['created' => true]);
}

if (preg_match('#^/platform/api/v1/users/(\d+)$#', $path, $m)) {
    $user = $state['users'][$m[1]] ?? null;
    $user ? $finish(200, $user) : $finish(404, ['error' => 'not found']);
}

if (preg_match('#^/platform/api/v1/users/(\d+)/login$#', $path, $m)) {
    $finish(200, ['url' => 'http://chatwoot.test/app/login?sso=' . $m[1]]);
}

// Account API calls made after the account exists (webhooks, profile, ...).
$finish(200, ['payload' => []]);
