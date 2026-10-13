<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Billing;

use Espo\Core\Exceptions\ServiceUnavailable;
use Espo\Core\Utils\Config;
use Espo\Modules\FeatureCredits\Billing\Asaas\Connections;

/** Explicit server-owned new-sale selection; historical processing uses its original connection. */
class Providers
{
    public function __construct(private Config $config, private Connections $connections) {}

    public function capability(?string $provider): ?ProviderCapability
    {
        // No fallback to Asaas for deferred Stripe or unrecognized routes.
        if ($provider !== 'asaas') return null;
        $entries = $this->config->get('featureCreditsBillingProviders');
        $entry = is_array($entries) ? ($entries['asaas'] ?? null) : null;
        $keys = ['integrationId', 'environment', 'salesEnabled', 'operations'];
        if (!is_array($entry) || array_diff(array_keys($entry), $keys) || array_diff($keys, array_keys($entry)) ||
            $entry['salesEnabled'] !== true || !is_string($entry['integrationId']) ||
            !in_array($entry['environment'], ['sandbox', 'production'], true) ||
            !is_array($entry['operations']) || !array_is_list($entry['operations']) || $entry['operations'] === []) return null;
        foreach ($entry['operations'] as $operation) {
            if (!in_array($operation, ['purchase', 'subscription'], true)) return null;
        }
        if (count(array_unique($entry['operations'])) !== count($entry['operations'])) return null;
        try {
            $capability = new ProviderCapability('asaas', $entry['integrationId'], true, ['BRL'], $entry['operations']);
            $connection = $this->connections->get($entry['integrationId']);
            if (!$connection->creationEnabled || $connection->id !== $entry['integrationId'] ||
                $connection->environment !== $entry['environment']) return null;
            return $capability;
        } catch (ServiceUnavailable | \InvalidArgumentException) {
            // Financial context remains readable when a new-sale connection is unavailable.
            return null;
        }
    }
}
