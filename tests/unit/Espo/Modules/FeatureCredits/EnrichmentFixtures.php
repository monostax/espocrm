<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

/** Approved reference prices, test data only. Never automatically seeded in production. */
final class EnrichmentFixtures
{
    public static function discoveryDefinition(string $version = 'discovery-v2', string $from = '2026-10-13 00:00:00'): array
    {
        $input = self::definition($version, $from);
        $input['routingPolicy'] = 'br-discovery-v2';
        foreach ([['apollo', 'people', 'employees', '2.0000'], ['cpfcnpj', '6', 'socios', '6.0000'],
            ['hub', 'cnpj', 'socios', '6.0000'], ['registrobr', 'domain', 'cnpj', '2.0000']] as [$provider, $lookup, $product, $credits]) {
            $input['rates'][] = ['provider' => $provider, 'target' => 'Account', 'lookup' => $lookup,
                'product' => $product, 'credits' => $credits];
        }
        return $input;
    }

    public static function definition(string $version = 'v1', string $from = '2026-10-12 00:00:00'): array
    {
        $rates = [];
        foreach ([
            ['cpfcnpj', 'Contact', '21', '2.7000', '2.7000'],
            ['hub', 'Contact', 'cadastropf', '7.5000', '7.5000'],
            ['hub', 'Account', 'cnpj', '5.1000', '5.1000'],
            ['cpfcnpj', 'Account', '6', '5.1000', '5.1000'],
            ['apollo', 'Contact', 'person', '1.0000', '8.0000'],
            ['apollo', 'Account', 'organization', '1.0000', '8.0000'],
        ] as [$provider, $target, $lookup, $email, $phone]) {
            foreach (['email' => $email, 'phone' => $phone] as $product => $credits) {
                $rates[] = compact('provider', 'target', 'lookup', 'product', 'credits');
            }
        }
        $rates[] = ['provider' => 'apollo', 'target' => 'Account', 'lookup' => 'organization', 'product' => 'company', 'credits' => '1.0000'];
        return ['version' => $version, 'effectiveFrom' => $from, 'routingPolicy' => 'br-identifiers-v1', 'rates' => $rates];
    }
}
