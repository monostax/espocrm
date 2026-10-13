<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Modules\FeatureCredits\Accounting\Amount;
use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use InvalidArgumentException;
use stdClass;

/** Immutable full price/route snapshot; publishing it is not supplier activation. */
final class RateCard
{
    public readonly string $id;
    public readonly string $version;
    public readonly string $effectiveFrom;
    public readonly array $definition;

    public function __construct(array $input)
    {
        self::fields($input, ['version', 'effectiveFrom', 'routingPolicy', 'rates']);
        if (!is_string($input['version']) || !preg_match('/\A[a-z0-9][a-z0-9._:-]{0,63}\z/', $input['version'])) {
            throw new InvalidArgumentException('Invalid enrichment catalog version.');
        }
        if (!is_string($input['effectiveFrom'])) throw new InvalidArgumentException('UTC catalog timestamp required.');
        GrantInput::timestamp($input['effectiveFrom']);
        if (!is_string($input['routingPolicy'])) throw new InvalidArgumentException('Enrichment routing policy required.');
        $routing = RoutingPolicy::definition($input['routingPolicy']);
        $products = RoutingPolicy::products($input['routingPolicy']);
        if (!is_array($input['rates']) || !array_is_list($input['rates']) || count($input['rates']) !== count($products)) {
            throw new InvalidArgumentException('Complete enrichment rate card for the selected routing policy required.');
        }
        $rates = [];
        foreach ($input['rates'] as $entry) {
            if ($entry instanceof stdClass) $entry = get_object_vars($entry);
            if (!is_array($entry)) throw new InvalidArgumentException('Invalid enrichment rate.');
            self::fields($entry, ['provider', 'target', 'lookup', 'product', 'credits']);
            foreach (['provider', 'target', 'lookup', 'product'] as $key) {
                if (!is_string($entry[$key])) throw new InvalidArgumentException('Invalid enrichment rate identity.');
            }
            $key = implode(':', [$entry['target'], $entry['provider'], $entry['product']]);
            if (!isset($products[$key]) || $entry['lookup'] !== $products[$key]) {
                throw new InvalidArgumentException('Unsupported enrichment product or supplier lookup.');
            }
            $credits = Amount::fromString($entry['credits']);
            if ($credits->compareTo(Amount::fromString('0')) <= 0) throw new InvalidArgumentException('Positive enrichment price required.');
            if (isset($rates[$key])) throw new InvalidArgumentException('Duplicate enrichment product rate.');
            $rates[$key] = [
                'provider' => $entry['provider'], 'target' => $entry['target'], 'lookup' => $entry['lookup'],
                'product' => $entry['product'], 'credits' => (string) $credits,
            ];
        }
        // Exact supported tuple count plus uniqueness requires the complete policy catalog.
        ksort($rates, SORT_STRING);
        $this->version = $input['version'];
        $this->effectiveFrom = $input['effectiveFrom'];
        $this->definition = ['version' => $this->version, 'effectiveFrom' => $this->effectiveFrom,
            'routingPolicy' => $routing['id'], 'rates' => array_values($rates)];
        $this->id = hash('sha256', json_encode(['enrichment-rate-card-v1', $this->definition, $routing], JSON_THROW_ON_ERROR));
    }

    /** All prices for a target, not availability, permission or dispatch authority. */
    public function ratesFor(string $target): array
    {
        if (!in_array($target, ['Contact', 'Account'], true)) throw new InvalidArgumentException('Unsupported enrichment target.');
        $rates = [];
        foreach ($this->definition['rates'] as $rate) {
            if ($rate['target'] !== $target) continue;
            $rates[$rate['provider']][$rate['product']] = ['version' => $this->id, 'credits' => $rate['credits']];
        }
        return $rates;
    }

    public function receipt(): array
    {
        return ['policyId' => $this->id, ...$this->definition,
            'routing' => RoutingPolicy::definition($this->definition['routingPolicy'])];
    }

    private static function fields(array $input, array $expected): void
    {
        if (array_diff(array_keys($input), $expected) || array_diff($expected, array_keys($input))) {
            throw new InvalidArgumentException('Complete enrichment definition with no extra fields required.');
        }
    }
}
