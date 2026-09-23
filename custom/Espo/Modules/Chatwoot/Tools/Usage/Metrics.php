<?php

namespace Espo\Modules\Chatwoot\Tools\Usage;

/** Ratios of aggregate counters, never averages/sums of per-run percentages. */
final class Metrics
{
    public const SUM_FIELDS = [
        'inputTokens', 'cachedInputTokens', 'modelRequestCount',
        'mainRequestCount', 'searchRequestCount',
        'mainUsageRequestCount', 'searchUsageRequestCount',
        'mainCacheHitRequestCount', 'searchCacheHitRequestCount',
        'mainInputTokens', 'mainCachedInputTokens', 'mainOutputTokens',
        'searchInputTokens', 'searchCachedInputTokens', 'searchOutputTokens',
    ];

    /** @return array<string, int|float|null> */
    public static function fromAggregates(array $row): array
    {
        $n = static fn (string $key): int => (int) ($row[$key] ?? 0);
        $ratio = static fn (int $a, int $b, int $scale = 1): ?float =>
            $b > 0 ? round($scale * $a / $b, 2) : null;
        $covered = $n('meteredRuns');
        $out = [
            'runs' => $n('runs'),
            'meteredRuns' => $covered,
            'telemetryCoveragePct' => $ratio($covered, $n('runs'), 100),
            // Historical token-level ratio is still available without request telemetry.
            'inputTokens' => $n('inputTokens'),
            'cachedInputTokens' => $n('cachedInputTokens'),
            'tokenCacheHitPct' => $ratio($n('cachedInputTokens'), $n('inputTokens'), 100),
        ];
        foreach (array_diff(self::SUM_FIELDS, ['inputTokens', 'cachedInputTokens']) as $field) {
            $out[$field] = $covered ? $n($field) : null;
        }
        $measured = $n('mainUsageRequestCount') + $n('searchUsageRequestCount');
        $out['requestsWithoutUsage'] = $covered ? $n('modelRequestCount') - $measured : null;
        $out['requestCacheHitPct'] = $ratio(
            $n('mainCacheHitRequestCount') + $n('searchCacheHitRequestCount'), $measured, 100
        );
        foreach (['main', 'search'] as $source) {
            $out[$source . 'TokenCacheHitPct'] = $ratio($n($source . 'CachedInputTokens'), $n($source . 'InputTokens'), 100);
            $out[$source . 'RequestCacheHitPct'] = $ratio($n($source . 'CacheHitRequestCount'), $n($source . 'UsageRequestCount'), 100);
            $out[$source . 'AvgInputTokens'] = $ratio($n($source . 'InputTokens'), $n($source . 'UsageRequestCount'));
            $out[$source . 'MaxInputTokens'] = $covered && $n($source . 'UsageRequestCount') ? $n($source . 'MaxInputTokens') : null;
        }
        return $out;
    }
}
