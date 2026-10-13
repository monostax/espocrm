<?php

declare(strict_types=1);

namespace Monostax\CreditBillingSandbox;

use Espo\Modules\FeatureCredits\Billing\Asaas\{Connection, Json, Response};
use RuntimeException;

/** Operator-only sandbox endpoints. Ordinary customer/payment Transport remains unchanged. */
class Http
{
    public function send(Connection $connection, string $method, string $path, ?array $body = null, array $query = []): Response
    {
        self::validate($connection, $method, $path, $body, $query);
        $content = ''; $curl = curl_init();
        try {
            curl_setopt_array($curl, [CURLOPT_URL => $connection->baseUrl() . $path . ($query ? '?' . http_build_query($query) : ''),
                CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $connection->headers(),
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT_MS => 5000, CURLOPT_TIMEOUT_MS => 30000,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$content): int {
                    if (strlen($content) + strlen($chunk) > 1048576) return 0;
                    $content .= $chunk; return strlen($chunk);
                }]);
            if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode((object) $body, JSON_THROW_ON_ERROR));
            if (curl_exec($curl) === false) return new Response(0, null);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            try { $data = Json::decode($content); } catch (\Throwable) { $data = null; }
            return new Response($status, $data);
        } finally { curl_close($curl); }
    }

    public static function validate(Connection $connection, string $method, string $path, ?array $body, array $query): void
    {
        if ($connection->environment !== 'sandbox' || $connection->baseUrl() !== 'https://api-sandbox.asaas.com/v3') {
            throw new RuntimeException('sandbox_only');
        }
        $allowed = match ($method) {
            'GET' => preg_match('#^/(wallets/|webhooks(/[a-zA-Z0-9_-]{1,100})?|subscriptions/sub_[a-zA-Z0-9_-]{1,100}/payments)$#D', $path) === 1 && $body === null,
            'POST' => ($path === '/webhooks' || preg_match('#^/sandbox/payment/pay_[a-zA-Z0-9_-]{1,100}/confirm$#D', $path)) && $body !== null && $query === [] && $connection->creationEnabled,
            default => false,
        };
        if (!$allowed || array_diff(array_keys($query), ['limit', 'offset'])) throw new RuntimeException('unsupported_sandbox_operation');
    }

    public function all(Connection $connection, string $path): array
    {
        $items = []; $total = null;
        for ($page = 0; $page < 5; $page++) {
            $response = $this->send($connection, 'GET', $path, query: ['limit' => 100, 'offset' => count($items)]);
            $data = $response->data;
            if ($response->status !== 200 || !is_array($data) || !is_array($data['data'] ?? null) || !array_is_list($data['data']) ||
                !is_bool($data['hasMore'] ?? null) || !ctype_digit((string) ($data['totalCount'] ?? '')) ||
                (string) ($data['offset'] ?? '') !== (string) count($items)) throw new RuntimeException('incomplete_provider_listing');
            $count = (int) $data['totalCount'];
            if ($total !== null && $total !== $count) throw new RuntimeException('changing_provider_listing');
            $total = $count;
            foreach ($data['data'] as $item) {
                if (!is_array($item) || !is_string($item['id'] ?? null) || isset($items[$item['id']])) throw new RuntimeException('ambiguous_provider_listing');
                $items[$item['id']] = $item;
            }
            if (!$data['hasMore']) {
                if (count($items) !== $total) throw new RuntimeException('incomplete_provider_listing');
                return array_values($items);
            }
            if ($data['data'] === []) throw new RuntimeException('empty_provider_page');
        }
        throw new RuntimeException('provider_listing_limit');
    }
}

/** Operational journal only; CRM financial identities stay in the existing billing tables. */
final class Journal
{
    private $file;
    public array $state;

    public function __construct(string $path, string $accountHash)
    {
        $this->file = fopen($path, 'c+b');
        if (!$this->file || !flock($this->file, LOCK_EX | LOCK_NB)) throw new RuntimeException('sandbox_run_already_active');
        chmod($path, 0600);
        $raw = stream_get_contents($this->file);
        $this->state = $raw === '' ? ['version' => 1, 'accountHash' => $accountHash, 'steps' => []] : json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (($this->state['version'] ?? null) !== 1 || ($this->state['accountHash'] ?? null) !== $accountHash) throw new RuntimeException('sandbox_journal_binding_changed');
    }

    public function save(): void
    {
        $json = json_encode($this->state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        rewind($this->file);
        if (fwrite($this->file, $json) !== strlen($json) || !ftruncate($this->file, strlen($json)) || !fflush($this->file) || !fsync($this->file)) {
            throw new RuntimeException('sandbox_journal_write_failed');
        }
    }

    public function claim(string $key, string $hash): bool
    {
        $prior = $this->state['steps'][$key] ?? null;
        if ($prior !== null) {
            if ($prior['hash'] !== $hash) throw new RuntimeException('sandbox_step_input_changed');
            return false;
        }
        $this->state['steps'][$key] = ['hash' => $hash, 'state' => 'claimed', 'claimedAt' => gmdate('c')];
        $this->save();
        return true;
    }

    public function complete(string $key, array $receipt): void
    {
        if (!isset($this->state['steps'][$key])) throw new RuntimeException('sandbox_step_not_claimed');
        $this->state['steps'][$key]['state'] = 'complete';
        $this->state['steps'][$key]['receipt'] = $receipt;
        $this->save();
    }

    public function __destruct()
    {
        if (is_resource($this->file)) { flock($this->file, LOCK_UN); fclose($this->file); }
    }
}

final class Webhook
{
    public const URL = 'https://app.monostax.ai/api/v1/CreditAsaasEvents/asaas-sandbox';
    public const NAME = 'Monostax CRM billing sandbox';
    public const EMAIL = 'am@monostax.com';
    public const EVENTS = ['PAYMENT_CREATED', 'PAYMENT_UPDATED', 'PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED',
        'PAYMENT_OVERDUE', 'PAYMENT_DELETED', 'PAYMENT_RESTORED', 'PAYMENT_REFUNDED', 'PAYMENT_PARTIALLY_REFUNDED',
        'PAYMENT_REFUND_IN_PROGRESS', 'PAYMENT_REFUND_DENIED', 'PAYMENT_CHARGEBACK_REQUESTED',
        'PAYMENT_CHARGEBACK_DISPUTE', 'PAYMENT_AWAITING_CHARGEBACK_REVERSAL'];

    public static function ensure(Http $http, Connection $connection, Journal $journal, #[\SensitiveParameter] string $token): array
    {
        if (strlen($token) < 32 || strlen($token) > 255) throw new RuntimeException('invalid_webhook_token');
        $input = ['name' => self::NAME, 'url' => self::URL, 'email' => self::EMAIL, 'enabled' => true,
            'interrupted' => false, 'apiVersion' => 3, 'sendType' => 'SEQUENTIALLY', 'events' => self::EVENTS, 'authToken' => $token];
        $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        $prior = $journal->state['steps']['webhook'] ?? null;
        if ($prior !== null && $prior['hash'] !== $hash) throw new RuntimeException('webhook_configuration_changed');
        $matches = array_values(array_filter($http->all($connection, '/webhooks'), static fn ($row) => ($row['url'] ?? null) === self::URL || ($row['name'] ?? null) === self::NAME));
        if (count($matches) > 1) throw new RuntimeException('duplicate_webhook_configuration');
        if ($matches !== []) {
            if ($prior === null) throw new RuntimeException('existing_webhook_requires_explicit_adoption');
            $receipt = self::verify($matches[0]);
            if (isset($prior['receipt']['id']) && $prior['receipt']['id'] !== $receipt['id']) throw new RuntimeException('webhook_identity_changed');
            $journal->complete('webhook', $receipt);
            return $receipt;
        }
        if (!$journal->claim('webhook', $hash)) throw new RuntimeException('webhook_creation_unresolved_lookup_only');
        $response = $http->send($connection, 'POST', '/webhooks', $input);
        if (!in_array($response->status, [200, 201], true) || !is_array($response->data)) throw new RuntimeException('webhook_creation_unresolved_lookup_only');
        $receipt = self::verify($response->data);
        $journal->complete('webhook', $receipt);
        return $receipt;
    }

    private static function verify(array $row): array
    {
        $events = $row['events'] ?? []; $expected = self::EVENTS;
        if (!is_array($events) || !array_is_list($events)) throw new RuntimeException('webhook_verification_failed');
        sort($events); sort($expected);
        if (!is_string($row['id'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $row['id']) ||
            ($row['name'] ?? null) !== self::NAME || ($row['url'] ?? null) !== self::URL || ($row['email'] ?? null) !== self::EMAIL ||
            ($row['enabled'] ?? null) !== true || ($row['interrupted'] ?? null) !== false ||
            (string) ($row['apiVersion'] ?? '') !== '3' || ($row['hasAuthToken'] ?? null) !== true ||
            ($row['sendType'] ?? null) !== 'SEQUENTIALLY' || $events !== $expected) throw new RuntimeException('webhook_verification_failed');
        return ['id' => $row['id'], 'url' => self::URL, 'email' => self::EMAIL, 'events' => count($expected), 'verifiedAt' => gmdate('c')];
    }
}
