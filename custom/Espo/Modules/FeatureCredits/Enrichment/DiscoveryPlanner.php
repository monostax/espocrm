<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Exceptions\Conflict;
use Espo\Modules\FeatureCredits\Accounting\Amount;

/** Quote bounds only. Does not implement candidate traversal, authorize a hold or dispatch. */
final class DiscoveryPlanner
{
    public function plan(QuoteInput $input, RateCard $card, array $availability): array
    {
        $product = $input->data['products'][0];
        $routing = $card->receipt()['routing'];
        $definition = $routing['recordDiscovery'][$product] ?? null;
        if (!$input->isDiscovery() || $definition === null) {
            throw new Conflict('The effective catalog does not support this discovery product.');
        }
        $providers = $definition[$input->data['waterfall'] ? 'waterfallOn' : 'waterfallOff'];
        $rates = $card->ratesFor('Account');
        $count = $input->data['count'] ?? 1;
        $prices = array_map(static fn (string $provider): Amount => Amount::fromString($rates[$provider][$product]['credits']), $providers);
        $budget = $input->data['budget'] !== null ? Amount::fromString($input->data['budget']) :
            self::bound($prices, $count, Amount::fromString('9999999999.9999'));
        $route = []; $unavailable = []; $selected = [];
        foreach ($providers as $provider) {
            if (!isset($availability[$provider][$product])) {
                $unavailable[$provider] = 'capability_unavailable';
                continue;
            }
            $rate = $rates[$provider][$product];
            $price = Amount::fromString($rate['credits']);
            if ($price->compareTo($budget) > 0) {
                $unavailable[$provider] = 'over_budget';
                continue;
            }
            $route[] = ['provider' => $provider, ...$rate];
            $selected[$provider][$product] = $availability[$provider][$product];
        }
        ksort($selected);
        $maximum = self::bound(array_map(static fn (array $step): Amount => Amount::fromString($step['credits']), $route), $count, $budget);
        return ['routes' => [$product => $route], 'budget' => (string) $budget, 'maximum' => (string) $maximum,
            'availability' => $selected, 'discovery' => [
                'unit' => $definition['unit'], 'strategy' => $definition['strategy'],
                'plannedProviders' => $providers, 'unavailableProviders' => $unavailable,
                'limits' => $product === 'cnpj' ? null : $routing['finderLimits'],
                'label' => $product === 'cnpj' ? 'Domain registrant CNPJ' : null,
                'explicitApplicationRequired' => true, 'personalContactDataIncluded' => false,
                'admissionSupported' => true,
            ]];
    }

    /** Saturate before addition so count × contribution prices never overflows posted precision. */
    private static function bound(array $prices, int $count, Amount $cap): Amount
    {
        $total = Amount::fromString('0');
        for ($person = 0; $person < $count; $person++) {
            foreach ($prices as $price) {
                if ($price->compareTo($cap->minus($total)) >= 0) return $cap;
                $total = $total->plus($price);
            }
        }
        return $total;
    }
}
