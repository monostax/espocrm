<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use InvalidArgumentException;

/** Versioned approved platform routes. Supplier-managed Apollo waterfall is never enabled. */
final class RoutingPolicy
{
    public const ID = 'br-identifiers-v1';
    public const DISCOVERY_ID = 'br-discovery-v2';

    public static function definition(string $id): array
    {
        if ($id === self::DISCOVERY_ID) {
            return [...self::definition(self::ID), 'id' => self::DISCOVERY_ID,
                'recordDiscovery' => [
                    'employees' => [
                        'target' => 'Account', 'identity' => 'companyLinkedIn',
                        'unit' => 'candidate', 'waterfallDefault' => false,
                        'waterfallOff' => ['apollo'], 'waterfallOn' => ['apollo'],
                        'strategy' => 'distinct-person-first',
                    ],
                    'socios' => [
                        'target' => 'Account', 'identity' => 'cnpj',
                        'unit' => 'useful-provider-contribution', 'waterfallDefault' => false,
                        'waterfallOff' => ['cpfcnpj'], 'waterfallOn' => ['cpfcnpj', 'hub'],
                        'strategy' => 'distinct-person-first-then-supplement',
                    ],
                    'cnpj' => [
                        'target' => 'Account', 'identity' => 'websiteDomain',
                        'unit' => 'registrant-cnpj-reveal', 'waterfallDefault' => false,
                        'waterfallOff' => ['registrobr'], 'waterfallOn' => ['registrobr'],
                        'strategy' => 'qualifying-registrant',
                    ],
                ],
                'finderLimits' => ['defaultCount' => 10, 'maximumCount' => 50,
                    'pagesPerProvider' => 5, 'completionAttempts' => 'requested-count',
                    'operationDeadlineSeconds' => 300],
                'hiddenRetentionMaximumDays' => 90,
            ];
        }
        if ($id !== self::ID) throw new InvalidArgumentException('Unsupported enrichment routing policy.');
        return [
            'id' => self::ID,
            'waterfallOff' => ['apollo'],
            'Contact' => ['cpfcnpj', 'hub', 'apollo'],
            'Account' => ['hub', 'cpfcnpj', 'apollo'],
            'company' => ['apollo'],
            'combinedOrder' => ['email', 'phone'],
            'apolloNativeWaterfall' => false,
        ];
    }

    /** Exact catalog tuples; product families are not interchangeable supplier capabilities. */
    public static function products(string $id): array
    {
        self::definition($id);
        $products = [];
        foreach (['Contact', 'Account'] as $target) {
            foreach (['cpfcnpj', 'hub', 'apollo'] as $provider) {
                foreach (['email', 'phone'] as $product) {
                    $products["$target:$provider:$product"] = self::lookup($provider, $target);
                }
            }
        }
        $products['Account:apollo:company'] = 'organization';
        if ($id === self::DISCOVERY_ID) {
            $products += [
                'Account:apollo:employees' => 'people',
                'Account:cpfcnpj:socios' => '6',
                'Account:hub:socios' => 'cnpj',
                'Account:registrobr:cnpj' => 'domain',
            ];
        }
        return $products;
    }

    public static function lookup(string $provider, string $target): string
    {
        return match ([$provider, $target]) {
            ['cpfcnpj', 'Contact'] => '21',
            ['cpfcnpj', 'Account'] => '6',
            ['hub', 'Contact'] => 'cadastropf',
            ['hub', 'Account'] => 'cnpj',
            ['apollo', 'Contact'] => 'person',
            ['apollo', 'Account'] => 'organization',
            default => throw new InvalidArgumentException('Unsupported enrichment provider/target.'),
        };
    }
}
