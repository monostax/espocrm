<?php

/**
 * A stand-in for the Chatwoot Platform and account APIs that the CRM calls
 * while provisioning and offboarding a workspace, and for the WAHA session
 * API (any call not matched below answers 200, so WAHA stop and delete
 * succeed and show in the log). Run it with PHP's built-in server:
 *
 *     FAKE_CHATWOOT_STATE=/tmp/fake-chatwoot.json php -S 0.0.0.0:8080 fake-chatwoot.php
 *
 * Every call is logged. Tests read the log and queue failures through:
 *
 *     GET  /__calls                    the call log
 *     GET  /__users                    the users, with their `confirmed` flag
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
$fresh = ['nextId' => 100, 'accounts' => [], 'users' => [], 'accountUsers' => [], 'calls' => [], 'faults' => []];
$state ??= $fresh;

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
    // Ids keep counting: records from earlier tests still point at them.
    $state = ['nextId' => $state['nextId']] + $fresh;
    $reply(200, []);
}

// Seeds an account's inboxes and conversations: {accountId, inboxes?, conversations?, deniedConversations?}.
if ($path === '/__seed') {
    foreach (['inboxes', 'conversations', 'deniedConversations'] as $kind) {
        if (isset($body[$kind])) {
            $state[$kind][(string) $body['accountId']] = $body[$kind];
        }
    }
    $reply(200, []);
}

if ($path === '/__fault') {
    $state['faults'][] = $body;
    $reply(200, []);
}

$call = $method . ' ' . preg_replace('#/\d+#', '/:id', $path);
$state['calls'][] = $call;

foreach ($state['faults'] as $i => $fault) {
    // Optional `path`: only that exact path, not every id behind the call.
    if ($fault['call'] === $call && $fault['times'] > 0 && (!isset($fault['path']) || $fault['path'] === $path)) {
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

if (preg_match('#^/platform/api/v1/accounts/(\d+)$#', $path, $m) && $method === 'PATCH' && isset($state['accounts'][$m[1]])) {
    // Like Chatwoot: custom_attributes merge, a null value removes the key.
    $merged = array_merge($state['accounts'][$m[1]]['custom_attributes'] ?? [], $body['custom_attributes'] ?? []);
    $state['accounts'][$m[1]]['custom_attributes'] = array_filter($merged, fn ($value) => $value !== null);
    $finish(200, $state['accounts'][$m[1]]);
}

// Like Chatwoot: deleting an account or user answers 200, and 404 once it's gone.
if (preg_match('#^/platform/api/v1/(accounts|users)/(\d+)$#', $path, $m) && $method === 'DELETE') {
    if (!isset($state[$m[1]][$m[2]])) {
        $finish(404, ['error' => 'not found']);
    }
    unset($state[$m[1]][$m[2]]);
    $finish(200, []);
}

if (preg_match('#^/platform/api/v1/accounts/(\d+)$#', $path, $m)) {
    $account = $state['accounts'][$m[1]] ?? null;
    if (!$account) {
        $finish(404, ['error' => 'not found']);
    }
    // Like the Platform API's account view: Em dia's opt-in as `catch_up`.
    $account['catch_up'] = [
        'enabled' => ($account['settings']['catch_up_enabled'] ?? null) === true,
        'instance' => 'http://chatwoot.test',
        'accountId' => (string) $account['id'],
        'dailyLimit' => 300,
    ];
    $account['status'] ??= 'active';
    $finish(200, $account);
}

// Like Chatwoot: a managed AI identity per operation, confirmed, with its own user.
if (preg_match('#^/platform/api/v1/accounts/(\d+)/machine_identities$#', $path, $m) && $method === 'POST') {
    $key = (string) ($body['operation_id'] ?? '');
    if (!isset($state['machineIdentities'][$key])) {
        $userId = $id();
        $state['users'][$userId] = [
            'id' => $userId, 'name' => $body['name'] ?? '', 'email' => $body['email'] ?? '',
            'access_token' => 'token-' . $userId, 'custom_attributes' => [], 'confirmed' => true,
        ];
        $state['machineIdentities'][$key] = [
            'machine_identity_id' => $body['machine_identity_id'] ?? null, 'operation_id' => $key,
            'email' => $body['email'] ?? null, 'account_id' => (int) $m[1], 'confirmed' => true, 'user_id' => $userId,
        ];
    }
    $finish(200, $state['machineIdentities'][$key]);
}

// The Account API's account update: settings merge (Em dia's opt-in lives there).
if (preg_match('#^/api/v1/accounts/(\d+)$#', $path, $m) && $method === 'PATCH' && isset($state['accounts'][$m[1]])) {
    $state['accounts'][$m[1]]['settings'] = array_merge($state['accounts'][$m[1]]['settings'] ?? [], $body);
    $finish(200, $state['accounts'][$m[1]]);
}

// Like Chatwoot's conversation_access: which of these display ids the user may open (all but the denied ones).
if (preg_match('#^/platform/api/v1/accounts/(\d+)/conversation_access$#', $path, $m) && $method === 'POST') {
    $denied = array_map('intval', $state['deniedConversations'][$m[1]] ?? []);
    $finish(200, ['conversation_ids' => array_values(array_diff(array_map('intval', $body['conversation_ids'] ?? []), $denied))]);
}

if ($path === '/__accounts') {
    $reply(200, $state['accounts']);
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
        'confirmed' => !empty($body['email_verified']),
    ];
    $finish(200, $state['users'][$userId] + ['created' => true]);
}

if (preg_match('#^/platform/api/v1/users/(\d+)/confirm$#', $path, $m) && $method === 'POST') {
    $user = $state['users'][$m[1]] ?? null;
    if (!$user || strcasecmp($user['email'], (string) ($body['email'] ?? '')) !== 0) {
        $finish(422, ['error' => 'Email mismatch']);
    }
    $state['users'][$m[1]]['confirmed'] = true;
    $finish(200, ['id' => (int) $m[1], 'confirmed' => true]);
}

if ($path === '/__users') {
    $reply(200, $state['users']);
}

if (preg_match('#^/platform/api/v1/users/(\d+)$#', $path, $m)) {
    $user = $state['users'][$m[1]] ?? null;
    $user ? $finish(200, $user) : $finish(404, ['error' => 'not found']);
}

if (preg_match('#^/platform/api/v1/users/(\d+)/login$#', $path, $m)) {
    $finish(200, ['url' => 'http://chatwoot.test/app/login?sso=' . $m[1]]);
}

// A stand-in OAuth token endpoint for social sign-in. POST /__idtoken
// {"claims": {...}} chooses the ID token claims it answers with.
if ($path === '/__idtoken') {
    $state['idTokenClaims'] = $body['claims'] ?? null;
    $reply(200, []);
}

// The provider's consent screen, approved at once: back to redirect_uri.
if ($path === '/oauth/authorize') {
    file_put_contents($stateFile, json_encode($state));
    flock($lock, LOCK_UN);
    header('Location: ' . $_GET['redirect_uri'] . '?' . http_build_query(['code' => 'fake-code', 'state' => $_GET['state']]));
    http_response_code(302);
    exit;
}

if ($path === '/oauth/token' && $method === 'POST') {
    $claims = $state['idTokenClaims'] ?? null;
    $encode = fn (array $data) => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');
    $claims
        ? $finish(200, ['access_token' => 'access', 'id_token' => $encode(['alg' => 'RS256']) . '.' . $encode($claims) . '.signature'])
        : $finish(400, ['error' => 'invalid_grant']);
}

if (preg_match('#^/api/v1/accounts/(\d+)/inboxes$#', $path, $m) && $method === 'GET') {
    $finish(200, ['payload' => $state['inboxes'][$m[1]] ?? []]);
}

// Like Chatwoot: newest activity first, 25 a page, optional last_activity_at filter.
if (preg_match('#^/api/v1/accounts/(\d+)/conversations/filter$#', $path, $m) && $method === 'POST') {
    $conversations = $state['conversations'][$m[1]] ?? [];
    foreach ($body['payload'] ?? [] as $filter) {
        if (($filter['attribute_key'] ?? '') === 'last_activity_at') {
            $after = strtotime((string) $filter['values'][0] . ' UTC');
            $conversations = array_values(array_filter($conversations, fn ($c) => $c['last_activity_at'] > $after));
        }
    }
    usort($conversations, fn ($a, $b) => $b['last_activity_at'] <=> $a['last_activity_at']);
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $finish(200, [
        'meta' => ['all_count' => count($conversations)],
        'payload' => array_slice($conversations, ($page - 1) * 25, 25),
    ]);
}

// Account API calls made after the account exists (webhooks, profile, ...).
$finish(200, ['payload' => []]);
