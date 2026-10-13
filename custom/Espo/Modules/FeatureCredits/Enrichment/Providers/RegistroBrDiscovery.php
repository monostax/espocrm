<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment\Providers;

use Espo\Modules\FeatureCredits\Enrichment\{DiscoverySupplier, DiscoveryResult, Quotes};
use Espo\Modules\Global\Tools\BrazilianIdentifier;

final class RegistroBrDiscovery implements DiscoverySupplier
{
    public function __construct(private Transport $transport) {}

    public function retentionSeconds(string $product): int { return 90 * 86400; }

    public function revision(string $tenantId, string $targetType, string $product, array $identity): ?string
    {
        if ($targetType !== 'Account' || $product !== 'cnpj' || RegistroBrDomain::normalize($identity['website'] ?? null) === null) return null;
        // Separate paid-reveal/cache permission confirmed by the user on 2026-10-12.
        return Quotes::hash(['registrobr-registrant-v1', 'separate-rights-2026-10-12', $tenantId, $this->retentionSeconds($product)]);
    }

    public function execute(array $request, int $timeoutMs): DiscoveryResult
    {
        $domain = RegistroBrDomain::normalize($request['identity']['website'] ?? null);
        if ($domain === null || ($request['product'] ?? null) !== 'cnpj' || ($request['kind'] ?? null) !== 'page' ||
            ($request['input']['cursor'] ?? null) !== null) return new DiscoveryResult('failed');
        $response = $this->transport->send('registrobr', 'GET', '/domain/' . rawurlencode($domain), timeoutMs: $timeoutMs);
        if ($response->status === 404) return new DiscoveryResult('no_match');
        if (in_array($response->status, [400, 401, 403, 422, 429], true)) return new DiscoveryResult('failed');
        $data = $response->data;
        if ($response->status !== 200 || ($data['objectClassName'] ?? null) !== 'domain' ||
            strtolower($data['ldhName'] ?? '') !== $domain || !is_array($data['entities'] ?? null)) return new DiscoveryResult('unknown');
        $ids = [];
        // Only direct registrants. Never mine technical/admin contacts, nested entities or free text.
        foreach ($data['entities'] as $entity) {
            if (!is_array($entity) || !is_array($entity['roles'] ?? null) || !in_array('registrant', $entity['roles'], true)) continue;
            if (!is_array($entity['publicIds'] ?? [])) return new DiscoveryResult('unknown');
            foreach ($entity['publicIds'] ?? [] as $id) {
                if (!is_array($id) || ($id['type'] ?? null) !== 'cnpj') continue;
                $cnpj = BrazilianIdentifier::cnpj($id['identifier'] ?? null);
                if ($cnpj !== null) $ids[$cnpj] = true;
            }
        }
        if (count($ids) > 1) return new DiscoveryResult('unknown');
        if (!$ids) return new DiscoveryResult('no_match');
        $cnpj = (string) array_key_first($ids);
        return new DiscoveryResult('succeeded', [['candidateKey' => Quotes::hash(['registrobr', $domain, $cnpj]),
            'values' => ['cnpj' => $cnpj], 'naturalPerson' => false]]);
    }
}
