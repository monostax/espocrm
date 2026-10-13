<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Modules\FeatureCredits\Billing\Asaas\{Connection, Connections, Json, Response, Transport};

final class AsaasTestConnections extends Connections
{
    public bool $enabled = true;
    public bool $available = true;
    public string $environment = 'sandbox';
    public function __construct() {}
    public function get(string $id): Connection
    {
        if (!$this->available) throw new \Espo\Core\Exceptions\ServiceUnavailable('Test integration unavailable.');
        return new Connection($id, $this->environment, 'test-only-key', $this->enabled);
    }
    public function authenticate(string $id, string $token): string
    {
        if ($token !== 'fixture-webhook-token') throw new \Espo\Core\Exceptions\Forbidden('Invalid fixture token.');
        return $this->environment;
    }
}

/** In-memory provider simulator; never used as a live/sandbox-provider acceptance claim. */
final class AsaasTestTransport extends Transport
{
    public array $calls = [];
    public array $objects = [];
    public string $mode = 'success';
    public ?\Closure $onSend = null;

    public static function profile(): array
    {
        return ['name' => 'Sandbox Payer', 'cpfCnpj' => '52998224725', 'email' => 'payer@example.test', 'notificationDisabled' => true];
    }

    public function send(Connection $connection, string $method, string $path, ?string $json = null, array $query = []): Response
    {
        $this->calls[] = [$connection->id, $connection->environment, $method, $path, $json, $query];
        if ($this->onSend) ($this->onSend)();
        if ($method === 'POST') {
            $body = Json::decode($json);
            $prefix = match ($path) { '/customers' => 'cus_', '/payments' => 'pay_', '/subscriptions' => 'sub_' };
            $object = $body + ['id' => $prefix . (count($this->objects) + 1), 'deleted' => false];
            $this->objects[$body['externalReference']] = $object;
            if ($this->mode === 'throw') throw new \RuntimeException('Simulated process failure after provider creation.');
            return $this->mode === 'lost' ? new Response(0, null) : new Response(200, $object);
        }
        if (str_starts_with($path, '/subscriptions/')) {
            foreach ($this->objects as $key => $object) if ($object['id'] === substr($path, 15)) {
                if ($method === 'DELETE') { $object['deleted'] = true; $this->objects[$key] = $object; }
                return $this->mode === 'lost' ? new Response(0, null) : new Response(200, $object + ['status' => 'ACTIVE']);
            }
            return new Response(404, null);
        }
        if (str_starts_with($path, '/payments/')) {
            foreach ($this->objects as $object) if ($object['id'] === substr($path, 10)) {
                return $this->mode === 'lost' ? new Response(0, null) : new Response(200, $object);
            }
            return new Response(404, null);
        }
        $object = $this->objects[$query['externalReference']] ?? null;
        $rows = $object && $this->mode !== 'empty' ? [$object] : [];
        if ($this->mode === 'duplicate') $rows[] = $object;
        return new Response(200, ['object' => 'list', 'totalCount' => (string) count($rows), 'offset' => '0', 'hasMore' => false, 'data' => $rows]);
    }
}
