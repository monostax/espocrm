<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureAiUsage;

use Espo\Modules\FeatureAiUsage\Services\Analytics;
use PHPUnit\Framework\TestCase;

class AnalyticsTest extends TestCase
{
    private function usageRow(int $input, int $cached, int $output, int $requests, int $measured, int $hits): array
    {
        return [
            'usageMetricsVersion' => 1, 'inputTokens' => $input, 'cachedInputTokens' => $cached, 'outputTokens' => $output,
            'mainRequestCount' => $requests, 'mainUsageRequestCount' => $measured, 'mainCacheHitRequestCount' => $hits,
            'mainInputTokens' => $input, 'mainCachedInputTokens' => $cached, 'mainOutputTokens' => $output,
            'mainMaxInputTokens' => $input,
            'searchRequestCount' => 0, 'searchUsageRequestCount' => 0, 'searchCacheHitRequestCount' => 0,
            'searchInputTokens' => 0, 'searchCachedInputTokens' => 0, 'searchOutputTokens' => 0, 'searchMaxInputTokens' => 0,
        ];
    }

    public function testWeightedCacheRatesKnownUsageAndNonbillableConsumption(): void
    {
        $a = $this->usageRow(100, 100, 20, 2, 1, 1) + ['runOutcome' => 'failed', 'reasoningTokens' => 10, 'durationMs' => 1000];
        $b = $this->usageRow(900, 0, 80, 4, 3, 0) + ['billingWaived' => true, 'durationMs' => 9000];
        $out = Analytics::summarize([$a, $b, ['inputTokens' => 50, 'outputTokens' => 5], []]);
        $this->assertSame(1155, $out['totalTokens']);
        $this->assertSame(10, $out['reasoningTokens']);
        $this->assertSame(900, $out['uncachedInputTokens']);
        $this->assertSame(10.0, $out['tokenCacheHitPct']); // Not (100% + 0%) / 2.
        $this->assertSame(25.0, $out['requestCacheHitPct']);
        $this->assertSame(6, $out['requests']);
        $this->assertSame(2, $out['requestsWithoutUsage']);
        $this->assertSame(66.67, $out['requestCoveragePct']);
        $this->assertSame(75.0, $out['tokenCoveragePct']);
        $this->assertSame(50.0, $out['telemetryCoveragePct']);
        $this->assertSame(385.0, $out['avgTokensPerRun']);
        $this->assertSame(275.0, $out['avgTokensPerRequest']);
        $this->assertSame(5000.0, $out['avgDurationMs']);
        $this->assertSame(1000, $out['p50DurationMs']);
        $this->assertSame(9000, $out['p95DurationMs']);
        $this->assertSame(900, $out['sources']['main']['maxInputTokens']);
        $this->assertNull($out['sources']['search']['requestCacheHitPct']);
        $this->assertSame(0, $out['sources']['search']['requests']);
    }

    public function testUnknownZeroAndInvalidCacheAreNotConflated(): void
    {
        foreach ([[], [[]]] as $runs) {
            $out = Analytics::summarize($runs);
            foreach (['inputTokens', 'outputTokens', 'totalTokens', 'uncachedInputTokens', 'tokenCacheHitPct', 'requests', 'requestCacheHitPct', 'avgDurationMs'] as $field) {
                $this->assertNull($out[$field], $field);
            }
        }
        $zero = Analytics::summarize([$this->usageRow(0, 0, 0, 0, 0, 0)]);
        $this->assertSame(0, $zero['totalTokens']);
        $this->assertSame(0, $zero['uncachedInputTokens']);
        $this->assertNull($zero['tokenCacheHitPct']);
        $invalid = Analytics::summarize([$this->usageRow(100, 101, 0, 1, 1, 1)]);
        $this->assertNull($invalid['tokenCacheHitPct']);
        $this->assertNull($invalid['uncachedInputTokens']);
        $this->assertNull($invalid['sources']['main']['requests']);
        $partial = Analytics::summarize([['inputTokens' => 30], ['outputTokens' => 5]]);
        $this->assertSame(35, $partial['totalTokens']);
        $this->assertSame(0, $partial['tokenRuns']);
        $this->assertNull($partial['avgTokensPerRun']);
        $this->assertSame(1, $partial['knownRuns']['inputTokens']);
    }

    public function testSourceCountersNeedV1AndGroupingRetainsUnknownOutcomesAndOverlappingTools(): void
    {
        $a = $this->usageRow(100, 50, 20, 1, 1, 1) + ['model' => 'a', 'runOutcome' => 'failed', 'toolsUsed' => ['search', 'send']];
        $b = $this->usageRow(900, 0, 80, 1, 1, 0) + ['model' => 'b', 'toolsUsed' => ['send']];
        $b['usageMetricsVersion'] = null;
        $out = Analytics::summarize([$a, $b]);
        $this->assertSame(1, $out['requests']);
        $this->assertSame(100, $out['sources']['main']['inputTokens']);
        $this->assertSame(1000, $out['inputTokens']);
        $models = Analytics::grouped([$a, $b], 'model');
        $this->assertSame('b', $models[0]['key']);
        $outcomes = array_column(Analytics::grouped([$a, $b], 'outcome'), null, 'key');
        $this->assertSame(120, $outcomes['failed']['totalTokens']);
        $this->assertSame(980, $outcomes['']['totalTokens']);
        $tools = array_column(Analytics::grouped([$a, $b], 'action'), null, 'key');
        $this->assertSame(1100, $tools['send']['totalTokens']);
        $this->assertSame(120, $tools['search']['totalTokens']);
        $page = Analytics::grouped([$a, $b], 'action', ['send' => true]);
        $this->assertCount(1, $page);
        $this->assertSame(1100, $page[0]['totalTokens']);
    }
}
