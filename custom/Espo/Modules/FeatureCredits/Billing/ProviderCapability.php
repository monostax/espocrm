<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Billing;

use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use InvalidArgumentException;

/** Trusted adapter configuration; capabilities are not supplied by the checkout client. */
final readonly class ProviderCapability
{
    public function __construct(
        public string $provider,
        public string $integrationId,
        public bool $salesEnabled,
        public array $currencies,
        public array $operations,
    ) {
        Routing::provider($provider);
        GrantInput::identity($integrationId);
        foreach ([[$currencies, ['BRL', 'USD']], [$operations, ['purchase', 'subscription']]] as [$values, $allowed]) {
            if (!array_is_list($values)) {
                throw new InvalidArgumentException('Invalid billing adapter capabilities.');
            }
            foreach ($values as $value) {
                if (!is_string($value) || !in_array($value, $allowed, true)) {
                    throw new InvalidArgumentException('Invalid billing adapter capabilities.');
                }
            }
        }
    }
}
