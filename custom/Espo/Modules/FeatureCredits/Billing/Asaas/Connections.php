<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Billing\Asaas;

use Espo\Core\Exceptions\ServiceUnavailable;
use Espo\Core\Utils\Config;

/** Administrator-owned config references environment secrets; no token in jobs, commands or records. */
class Connections
{
    public function __construct(private Config $config) {}

    public function get(string $id): Connection
    {
        $entries = $this->config->get('featureCreditsAsaasConnections');
        $entry = is_array($entries) ? ($entries[$id] ?? null) : null;
        if (!is_array($entry) || array_diff(array_keys($entry), ['environment', 'apiKeyEnv', 'creationEnabled', 'webhookTokenEnv']) ||
            !is_string($entry['environment'] ?? null) || !is_string($entry['apiKeyEnv'] ?? null) ||
            !preg_match('/^[A-Z][A-Z0-9_]{0,127}$/D', $entry['apiKeyEnv']) || !is_bool($entry['creationEnabled'] ?? false)) {
            throw new ServiceUnavailable('Asaas integration is unavailable.');
        }
        $key = getenv($entry['apiKeyEnv']);
        if (!is_string($key)) throw new ServiceUnavailable('Asaas integration is unavailable.');
        try { return new Connection($id, $entry['environment'], $key, $entry['creationEnabled'] ?? false); }
        catch (\InvalidArgumentException) { throw new ServiceUnavailable('Asaas integration is unavailable.'); }
    }

    /** Webhook authentication has its own secret and does not depend on new-sale/API-key availability. */
    public function authenticate(string $id, #[\SensitiveParameter] string $token): string
    {
        $entries = $this->config->get('featureCreditsAsaasConnections');
        $entry = is_array($entries) ? ($entries[$id] ?? null) : null;
        $name = is_array($entry) ? ($entry['webhookTokenEnv'] ?? null) : null;
        $secret = is_string($name) && preg_match('/^[A-Z][A-Z0-9_]{0,127}$/D', $name) ? getenv($name) : false;
        $apiName = is_array($entry) ? ($entry['apiKeyEnv'] ?? null) : null;
        $apiKey = is_string($apiName) ? getenv($apiName) : false;
        if (!is_string($secret) || strlen($secret) < 32 || strlen($secret) > 255 ||
            $secret === $apiKey || !hash_equals($secret, $token) || !in_array($entry['environment'] ?? null, ['sandbox', 'production'], true)) {
            throw new \Espo\Core\Exceptions\Forbidden('Invalid Asaas webhook authentication.');
        }
        return $entry['environment'];
    }
}
