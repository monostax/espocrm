<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Modules\FeatureCredits\Accounting\Amount;
use Espo\Modules\Global\Tools\BrazilianIdentifier;
use Espo\ORM\Entity;
use InvalidArgumentException;

/** Pure planning, not authorization. Supply only server-verified available rate snapshots. */
final class RoutePlanner
{
    /**
     * The target is the actual CRM record; related records are never consulted.
     * Rates must already be scoped to this entity type and supplier lookup product.
     * Missing rates exclude unavailable/unsupported providers, including Apollo.
     *
     * @param array<string, array<string, array{version: string, credits: string}>> $rates
     * @param list<string> $products
     * @return array{routes: array<string, list<array{provider: string, version: string, credits: string}>>, maximum: string}
     */
    public function plan(Entity $target, array $products, bool $waterfall, Amount $budget, array $rates, string $routingPolicy = RoutingPolicy::ID): array
    {
        $routing = RoutingPolicy::definition($routingPolicy);
        $type = $target->getEntityType();
        if (!in_array($type, ['Contact', 'Account'], true)) {
            throw new InvalidArgumentException('Unsupported enrichment target.');
        }
        if (!in_array($products, [['email'], ['phone'], ['email', 'phone'], ['company']], true)
            || ($products === ['company'] && $type !== 'Account')
        ) {
            throw new InvalidArgumentException('Unsupported enrichment products or order.');
        }
        $zero = Amount::fromString('0');
        if ($budget->compareTo($zero) < 0) throw new InvalidArgumentException('Negative enrichment budget.');
        $providers = $routing['waterfallOff'];
        if ($waterfall && $products !== ['company']) {
            if ($type === 'Contact' && BrazilianIdentifier::cpf($target->get('cpf')) !== null) {
                $providers = $routing['Contact'];
            }
            if ($type === 'Account' && BrazilianIdentifier::cnpj($target->get('cnpj')) !== null) {
                $providers = $routing['Account'];
            }
        }
        $routes = [];
        $maximum = $zero;
        foreach ($products as $product) {
            $routes[$product] = [];
            $childMaximum = $zero;
            foreach ($providers as $provider) {
                $rate = $rates[$provider][$product] ?? null;
                if ($rate === null) continue;
                if (!is_string($rate['version'] ?? null) || $rate['version'] === '') {
                    throw new InvalidArgumentException('Missing enrichment rate version.');
                }
                $price = Amount::fromString($rate['credits'] ?? null);
                if ($price->compareTo($zero) <= 0) throw new InvalidArgumentException('Invalid enrichment price.');
                if ($price->compareTo($budget) > 0) continue;
                $routes[$product][] = ['provider' => $provider, 'version' => $rate['version'], 'credits' => (string) $price];
                if ($price->compareTo($childMaximum) > 0) $childMaximum = $price;
            }
            // Saturate at the shared cap without overflowing Amount's supported range.
            $room = $budget->minus($maximum);
            $maximum = $childMaximum->compareTo($room) > 0 ? $budget : $maximum->plus($childMaximum);
        }
        return ['routes' => $routes, 'maximum' => (string) $maximum];
    }

    /** Refilter a confirmed child route against the operation's remaining shared budget. */
    public function affordable(array $route, Amount $remaining): array
    {
        if ($remaining->compareTo(Amount::fromString('0')) < 0) {
            throw new InvalidArgumentException('Negative remaining enrichment budget.');
        }
        return array_values(array_filter($route, static fn (array $step): bool =>
            Amount::fromString($step['credits'])->compareTo($remaining) <= 0));
    }
}
