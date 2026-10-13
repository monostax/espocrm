<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Modules\FeatureCredits\Billing\{ProviderCapability, Providers};

/** Explicit synthetic capability for storage tests, independent of runtime activation. */
final class BillingTestProviders extends Providers
{
    public bool $enabled = true;
    public string $integration = 'test-asaas';

    public function __construct() {}

    public function capability(?string $provider): ?ProviderCapability
    {
        return $provider === 'asaas' ? new ProviderCapability('asaas', $this->integration, $this->enabled, ['BRL'], ['purchase', 'subscription']) : null;
    }
}
