<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment\Providers;

/** Server environment only. Never persist credential values in quotes or job data. */
class Credentials
{
    public function get(string $provider): array
    {
        $names = match ($provider) {
            'cpfcnpj' => ['token' => 'ENRICHMENT_CPFCNPJ_TOKEN'],
            'hub' => ['token' => 'ENRICHMENT_HUB_KEY', 'contract' => 'ENRICHMENT_HUB_CONTRACT'],
            'apollo' => ['key' => 'ENRICHMENT_APOLLO_API_KEY'],
            default => throw new \InvalidArgumentException('Unknown enrichment supplier.'),
        };
        $result = [];
        foreach ($names as $key => $name) {
            $value = getenv($name);
            if (!is_string($value) || trim($value) === '' || preg_match('/[\x00-\x1f\x7f]/', $value)) return [];
            $result[$key] = $value;
        }
        return $result;
    }
}
