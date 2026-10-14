<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Exceptions\Conflict;

/** Output only. Call after validating original snapshots; never persist this projection. */
final class CustomerProjection
{
    private static function only(array $value, array $keys): array
    {
        return array_intersect_key($value, array_flip($keys));
    }

    /** Number sources by first appearance in the original quote, never by a global alias. */
    public static function source(array $terms, string $provider): int
    {
        $sources = [];
        foreach ($terms['routes'] as $steps) {
            foreach ($steps as $step) {
                if (!in_array($step['provider'], $sources, true)) $sources[] = $step['provider'];
            }
        }
        $index = array_search($provider, $sources, true);
        if ($index === false) throw new Conflict('Enrichment source is unavailable.');
        return $index + 1;
    }

    public static function purchase(array $receipt, array $terms): array
    {
        return self::only($receipt, ['id', 'quoteId', 'usageId', 'product', 'credits', 'acquiredAt', 'purchasedAt']) +
            ['source' => self::source($terms, $receipt['provider'])];
    }

    public static function quote(array $receipt): array
    {
        $result = self::only($receipt, ['id', 'tenantId', 'targetType', 'targetId', 'products', 'waterfall',
            'refresh', 'budget', 'maximum', 'createdAt', 'confirmedAt', 'state', 'confirmable', 'admission',
            'dispatchAllowed', 'usageId', 'reservationId', 'reservedCredits', 'admittedAt', 'count']);
        $result['routes'] = [];
        foreach ($receipt['routes'] as $product => $steps) {
            $result['routes'][$product] = array_map(static fn (array $step): array => [
                'source' => self::source($receipt, $step['provider']), 'credits' => $step['credits'],
            ], $steps);
        }
        if (isset($receipt['executionProducts'])) {
            $result['executionProducts'] = [];
            foreach ($receipt['executionProducts'] as $product => $progress) {
                $public = self::only($progress, ['state', 'candidateCount', 'purchaseCount']);
                foreach (['attempts', 'calls'] as $kind) {
                    if (!isset($progress[$kind])) continue;
                    $public[$kind] = array_map(static fn (array $item): array =>
                        self::only($item, ['state', 'delivery', 'purchaseId', 'kind', 'dispatched', 'reused']) +
                        ['source' => self::source($receipt, $item['provider'])], $progress[$kind]);
                }
                $result['executionProducts'][$product] = $public;
            }
        }
        if (isset($receipt['discovery'])) {
            $result['discovery'] = self::only($receipt['discovery'], [
                'explicitApplicationRequired', 'personalContactDataIncluded', 'admissionSupported',
            ]);
        }
        return $result;
    }
}
