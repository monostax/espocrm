<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Exceptions\Conflict;
use Espo\Modules\FeatureCredits\Accounting\Amount;
use InvalidArgumentException;

/** Stable public selectors for explicit all-selected field lookups. Never renumber these IDs. */
final class SelectedProviders
{
    public const SOURCES = [1 => 'apollo', 2 => 'cpfcnpj', 3 => 'hub'];

    public static function normalize(mixed $sources): array
    {
        if (!is_array($sources) || !array_is_list($sources) || !$sources || count($sources) > count(self::SOURCES)) {
            throw new InvalidArgumentException('Select at least one available enrichment source.');
        }
        foreach ($sources as $source) {
            if (!is_int($source) || !isset(self::SOURCES[$source])) throw new InvalidArgumentException('Unknown enrichment source.');
        }
        if (count(array_unique($sources)) !== count($sources)) throw new InvalidArgumentException('Duplicate enrichment source.');
        sort($sources, SORT_NUMERIC);
        return $sources;
    }

    public static function availability(array $sources, array $available): array
    {
        $selected = [];
        foreach ($sources as $source) {
            $provider = self::SOURCES[$source];
            if (empty($available[$provider])) throw new Conflict('Selected enrichment source is no longer available; request a new quote.');
            $selected[$provider] = $available[$provider];
        }
        return $selected;
    }

    public static function plan(array $products, array $available, array $rates): array
    {
        $routes = [];
        $maximum = Amount::fromString('0');
        foreach ($products as $product) {
            $routes[$product] = [];
            foreach ($available as $provider => $capabilities) {
                if (!isset($capabilities[$product])) continue;
                $rate = $rates[$provider][$product];
                $routes[$product][] = ['provider' => $provider, 'version' => $rate['version'], 'credits' => $rate['credits']];
                $maximum = $maximum->plus(Amount::fromString($rate['credits']));
            }
        }
        return ['routes' => $routes, 'maximum' => (string) $maximum, 'budget' => (string) $maximum];
    }

    public static function options(array $products, array $available, array $rates, array $routes): array
    {
        $defaults = array_filter(array_map(static fn ($steps) => $steps[0]['provider'] ?? null, $routes));
        $options = [];
        foreach (self::SOURCES as $source => $provider) {
            $prices = [];
            foreach ($products as $product) {
                if (isset($available[$provider][$product])) $prices[$product] = $rates[$provider][$product]['credits'];
            }
            if ($prices) $options[] = ['source' => $source, 'prices' => $prices, 'selected' => in_array($provider, $defaults, true)];
        }
        return $options;
    }
}
