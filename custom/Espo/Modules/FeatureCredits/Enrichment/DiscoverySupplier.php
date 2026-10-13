<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

/** Verified discovery adapters only; field credentials are not discovery activation. */
interface DiscoverySupplier extends QuoteProvider
{
    /** Verified retention/paid-reuse contract, included in the capability revision. */
    public function retentionSeconds(string $product): int;

    /** One claimed HTTP request, without native waterfall, retries or implicit pagination/completion. */
    public function execute(array $request,int $timeoutMs): DiscoveryResult;
}
