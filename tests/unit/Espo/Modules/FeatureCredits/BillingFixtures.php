<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use stdClass;

final class BillingFixtures
{
    /** Synthetic test economics, never production defaults. */
    public static function definition(string $version = 'v1', string $start = '2026-10-13 00:00:00'): stdClass
    {
        return (object) [
            'version' => $version, 'effectiveFrom' => $start,
            'products' => [
                (object) ['productId' => 'monthly', 'productVersion' => 'v1', 'name' => 'Monthly test plan', 'operation' => 'subscription', 'credits' => '100'],
                (object) ['productId' => 'pack', 'productVersion' => 'v1', 'name' => 'Test credit pack', 'operation' => 'purchase', 'credits' => '50'],
            ],
            'offers' => [
                (object) ['offerId' => 'monthly-br', 'version' => 'v1', 'productId' => 'monthly', 'productVersion' => 'v1',
                    'countries' => ['BR'], 'currency' => 'BRL', 'price' => '50', 'interval' => 'month'],
                (object) ['offerId' => 'monthly-us', 'version' => 'v1', 'productId' => 'monthly', 'productVersion' => 'v1',
                    'countries' => ['US'], 'currency' => 'USD', 'price' => '10', 'interval' => 'month'],
                (object) ['offerId' => 'pack-br', 'version' => 'v1', 'productId' => 'pack', 'productVersion' => 'v1',
                    'countries' => ['BR'], 'currency' => 'BRL', 'price' => '25', 'interval' => null],
            ],
        ];
    }
}
