<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAiUsage\Services;

/** Internal telemetry only. Never used to determine customer charges. */
final class Analytics
{
    public const FIELDS = [
        'inputTokens', 'outputTokens', 'cachedInputTokens', 'reasoningTokens', 'stepCount',
        'mainRequestCount', 'searchRequestCount', 'mainUsageRequestCount', 'searchUsageRequestCount',
        'mainCacheHitRequestCount', 'searchCacheHitRequestCount',
        'mainInputTokens', 'mainCachedInputTokens', 'mainOutputTokens', 'mainMaxInputTokens',
        'searchInputTokens', 'searchCachedInputTokens', 'searchOutputTokens', 'searchMaxInputTokens',
    ];

    public static function summarize(array $runs): array
    {
        $out = ['runs' => count($runs), 'meteredRuns' => 0, 'tokenRuns' => 0, 'cacheRuns' => 0];
        $fields = ['inputTokens', 'outputTokens', 'cachedInputTokens', 'reasoningTokens', 'stepCount'];
        $known = array_fill_keys($fields, 0);
        foreach ($fields as $field) $out[$field] = 0;
        $cacheInput = $cacheTokens = $total = $duration = 0;
        $durations = [];
        $sources = [];
        foreach (['main', 'search'] as $source) {
            $sources[$source] = ['requests' => 0, 'measuredRequests' => 0, 'cacheHitRequests' => 0,
                'inputTokens' => 0, 'cachedInputTokens' => 0, 'outputTokens' => 0, 'maxInputTokens' => 0,
                'knownRuns' => 0];
        }
        foreach ($runs as $run) {
            $out['meteredRuns'] += (int) (($run['usageMetricsVersion'] ?? null) === 1);
            foreach ($fields as $field) {
                if (self::known($run[$field] ?? null)) {
                    $out[$field] += $run[$field];
                    $known[$field]++;
                }
            }
            if (self::known($run['inputTokens'] ?? null) && self::known($run['outputTokens'] ?? null)) {
                $out['tokenRuns']++;
                $total += $run['inputTokens'] + $run['outputTokens'];
            }
            if (self::known($run['inputTokens'] ?? null) && self::known($run['cachedInputTokens'] ?? null)
                && $run['cachedInputTokens'] <= $run['inputTokens']) {
                $out['cacheRuns']++;
                $cacheInput += $run['inputTokens'];
                $cacheTokens += $run['cachedInputTokens'];
            }
            if (self::known($run['durationMs'] ?? null)) {
                $duration += $run['durationMs'];
                $durations[] = $run['durationMs'];
            }
            if (($run['usageMetricsVersion'] ?? null) !== 1) continue;
            foreach ($sources as $source => &$metrics) {
                $map = ['requests' => 'RequestCount', 'measuredRequests' => 'UsageRequestCount',
                    'cacheHitRequests' => 'CacheHitRequestCount', 'inputTokens' => 'InputTokens',
                    'cachedInputTokens' => 'CachedInputTokens', 'outputTokens' => 'OutputTokens',
                    'maxInputTokens' => 'MaxInputTokens'];
                foreach ($map as $suffix) {
                    if (!self::known($run[$source . $suffix] ?? null)) continue 2;
                }
                if ($run[$source . 'UsageRequestCount'] > $run[$source . 'RequestCount']
                    || $run[$source . 'CacheHitRequestCount'] > $run[$source . 'UsageRequestCount']
                    || $run[$source . 'CachedInputTokens'] > $run[$source . 'InputTokens']) continue;
                $metrics['knownRuns']++;
                foreach ($map as $key => $suffix) {
                    $metrics[$key] = $key === 'maxInputTokens'
                        ? max($metrics[$key], $run[$source . $suffix]) : $metrics[$key] + $run[$source . $suffix];
                }
            }
            unset($metrics);
        }
        foreach ($fields as $field) {
            if (!$known[$field]) $out[$field] = null;
        }
        $out['knownRuns'] = $known;
        $out['totalTokens'] = $known['inputTokens'] || $known['outputTokens']
            ? ($out['inputTokens'] ?? 0) + ($out['outputTokens'] ?? 0) : null;
        $out['uncachedInputTokens'] = $out['cacheRuns'] ? $cacheInput - $cacheTokens : null;
        $out['tokenCacheHitPct'] = self::ratio($cacheTokens, $cacheInput, 100);
        $out['avgTokensPerRun'] = self::ratio($total, $out['tokenRuns']);
        $out['telemetryCoveragePct'] = self::ratio($out['meteredRuns'], $out['runs'], 100);
        $out['tokenCoveragePct'] = self::ratio($out['tokenRuns'], $out['runs'], 100);
        $out['durationRuns'] = count($durations);
        $out['avgDurationMs'] = self::ratio($duration, count($durations));
        sort($durations, SORT_NUMERIC);
        $out['p50DurationMs'] = $durations ? $durations[(int) ceil(count($durations) * 0.5) - 1] : null;
        $out['p95DurationMs'] = $durations ? $durations[(int) ceil(count($durations) * 0.95) - 1] : null;
        $out['maxDurationMs'] = $durations ? $durations[count($durations) - 1] : null;
        $requests = $measured = $hits = $requestInput = $requestOutput = 0;
        $requestKnown = false;
        foreach ($sources as &$metrics) {
            $requests += $metrics['requests'];
            $measured += $metrics['measuredRequests'];
            $hits += $metrics['cacheHitRequests'];
            $requestInput += $metrics['inputTokens'];
            $requestOutput += $metrics['outputTokens'];
            $requestKnown = $requestKnown || $metrics['knownRuns'] > 0;
            $metrics['tokenCacheHitPct'] = self::ratio($metrics['cachedInputTokens'], $metrics['inputTokens'], 100);
            $metrics['requestCacheHitPct'] = self::ratio($metrics['cacheHitRequests'], $metrics['measuredRequests'], 100);
            $metrics['avgInputTokens'] = self::ratio($metrics['inputTokens'], $metrics['measuredRequests']);
            $metrics['requestCoveragePct'] = self::ratio($metrics['measuredRequests'], $metrics['requests'], 100);
            if (!$metrics['measuredRequests']) $metrics['maxInputTokens'] = null;
            if (!$metrics['knownRuns']) {
                foreach ($metrics as $key => $_) {
                    if ($key !== 'knownRuns') $metrics[$key] = null;
                }
            }
        }
        unset($metrics);
        $out['sources'] = $sources;
        $out['requests'] = $requestKnown ? $requests : null;
        $out['measuredRequests'] = $requestKnown ? $measured : null;
        $out['requestsWithoutUsage'] = $requestKnown ? $requests - $measured : null;
        $out['requestCacheHitPct'] = self::ratio($hits, $measured, 100);
        $out['requestCoveragePct'] = self::ratio($measured, $requests, 100);
        $out['avgTokensPerRequest'] = self::ratio($requestInput + $requestOutput, $measured);
        return $out;
    }

    /** Group all matching runs before pagination; tool groups intentionally overlap. */
    public static function grouped(array $runs, string $dimension, ?array $keysToInclude = null): array
    {
        $field = match ($dimension) {
            'agent' => 'agentId', 'account' => 'chatwootAccountId', 'conversation' => 'conversationId',
            'opportunity' => 'opportunityId', 'outcome' => 'runOutcome', default => $dimension,
        };
        $buckets = [];
        foreach ($runs as $run) {
            $keys = $dimension === 'action' ? (Dataset::actions($run) ?: ['']) : [(string) ($run[$field] ?? '')];
            foreach ($keys as $key) {
                if ($keysToInclude === null || isset($keysToInclude[$key])) $buckets[$key][] = $run;
            }
        }
        $out = [];
        foreach ($buckets as $key => $rows) $out[] = ['key' => (string) $key] + self::summarize($rows);
        usort($out, static fn ($a, $b) => ($b['totalTokens'] ?? -1) <=> ($a['totalTokens'] ?? -1) ?: strcmp($a['key'], $b['key']));
        return $out;
    }

    private static function known(mixed $value): bool
    {
        return is_int($value) && $value >= 0;
    }

    private static function ratio(int $a, int $b, int $scale = 1): ?float
    {
        return $b > 0 ? round($scale * $a / $b, 2) : null;
    }
}
