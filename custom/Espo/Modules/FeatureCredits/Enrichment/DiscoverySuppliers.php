<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Exceptions\ServiceUnavailable;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Metadata;

class DiscoverySuppliers
{
    public function __construct(private Metadata $metadata,private InjectableFactory $factory) {}

    public function get(string $provider): DiscoverySupplier
    {
        if (!in_array($provider,['apollo','cpfcnpj','hub','registrobr'],true)) throw new ServiceUnavailable('Unknown discovery supplier.');
        $class = $this->metadata->get(['app','enrichment','discoveryQuoteProviderClassNameMap',$provider]);
        if (!is_string($class) || !is_a($class,DiscoverySupplier::class,true)) {
            throw new ServiceUnavailable('Discovery execution capability is not verified and registered.');
        }
        return $this->factory->create($class);
    }
}
