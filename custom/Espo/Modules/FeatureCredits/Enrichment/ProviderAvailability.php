<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Exceptions\ServiceUnavailable;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Metadata;
use Espo\Modules\Global\Tools\BrazilianIdentifier;
use Espo\Modules\Global\Tools\LinkedInProfile;

class ProviderAvailability
{
    public function __construct(private Metadata $metadata, private InjectableFactory $factory) {}

    /** No registered/verified adapter means no executable provider; never infer from price rows. */
    public function inspect(string $tenantId, string $type, array $products, array $identity, bool $waterfall = true): array
    {
        if (in_array($products, [['employees'], ['socios'], ['cnpj']], true)) {
            return $this->discovery($tenantId, $type, $products[0], $identity, $waterfall);
        }
        $classes = $this->metadata->get(['app', 'enrichment', 'quoteProviderClassNameMap']) ?? [];
        if (!is_array($classes) || array_diff(array_keys($classes), ['apollo', 'cpfcnpj', 'hub'])) {
            throw new ServiceUnavailable('Invalid enrichment provider registry.');
        }
        $result = [];
        foreach ($classes as $provider => $class) {
            if ($provider !== 'apollo' && (!$waterfall || $products === ['company'])) continue;
            if ($provider !== 'apollo' && ($type === 'Contact' ?
                BrazilianIdentifier::cpf($identity['cpf'] ?? null) : BrazilianIdentifier::cnpj($identity['cnpj'] ?? null)) === null) continue;
            if (!is_string($class) || !is_a($class, QuoteProvider::class, true)) {
                throw new ServiceUnavailable('Invalid enrichment quote provider.');
            }
            $adapter = $this->factory->create($class);
            foreach ($products as $product) {
                if ($product === 'company' && ($type !== 'Account' || $provider !== 'apollo')) continue;
                $revision = $adapter->revision($tenantId, $type, $product, $identity);
                if ($revision === null) continue;
                if (!preg_match('/\A[a-f0-9]{64}\z/', $revision)) throw new ServiceUnavailable('Invalid enrichment capability revision.');
                $result[$provider][$product] = $revision;
            }
        }
        ksort($result);
        foreach ($result as &$entries) ksort($entries);
        unset($entries);
        return $result;
    }

    /** Separate registration is mandatory: a field adapter/credential does not establish Finder rights. */
    private function discovery(string $tenant, string $type, string $product, array $identity, bool $waterfall): array
    {
        if ($type !== 'Account') return [];
        $eligible = match ($product) {
            'employees' => LinkedInProfile::profile('Account', $identity['linkedinUrl'] ?? null) !== null,
            'socios' => BrazilianIdentifier::cnpj($identity['cnpj'] ?? null) !== null,
            // Domain normalization/coverage is the registered RDAP adapter's responsibility;
            // a website alone is never enough to declare a supplier capability.
            'cnpj' => is_string($identity['website'] ?? null) && trim($identity['website']) !== '',
        };
        if (!$eligible) return [];
        $classes = $this->metadata->get(['app', 'enrichment', 'discoveryQuoteProviderClassNameMap']) ?? [];
        if (!is_array($classes) || array_diff(array_keys($classes), ['apollo', 'cpfcnpj', 'hub', 'registrobr'])) {
            throw new ServiceUnavailable('Invalid discovery provider registry.');
        }
        $definition = RoutingPolicy::definition(RoutingPolicy::DISCOVERY_ID)['recordDiscovery'][$product];
        $result = [];
        foreach ($definition[$waterfall ? 'waterfallOn' : 'waterfallOff'] as $provider) {
            $class = $classes[$provider] ?? null;
            if ($class === null) continue;
            if (!is_string($class) || !is_a($class, DiscoverySupplier::class, true)) {
                throw new ServiceUnavailable('Invalid discovery execution provider.');
            }
            $supplier = $this->factory->create($class);
            $retention = $supplier->retentionSeconds($product);
            if ($retention < 1 || $retention > 90 * 86400) throw new ServiceUnavailable('Invalid discovery retention contract.');
            $revision = $supplier->revision($tenant, $type, $product, $identity);
            if ($revision === null) continue;
            if (!preg_match('/\A[a-f0-9]{64}\z/', $revision)) throw new ServiceUnavailable('Invalid discovery capability revision.');
            $result[$provider][$product] = $revision;
        }
        ksort($result);
        return $result;
    }
}
