<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Espo\Modules\FeatureCredits\Pricing\AiPricing;
use Espo\Modules\FeatureCredits\Pricing\AiRate;
use InvalidArgumentException;

/** Trusted adapter supplies resolved pricing and reservation limits. Ordinary
 * profiles enforce conservative caps; the explicit native-search profile permits
 * measured overruns only when selected by this tenant's original agreement. */
final readonly class RequestInput
{
    public string $credits;
    public string $snapshotJson;
    public string $hash;

    public function __construct(
        public string $tenantId,
        public string $usageId,
        public string $executionId,
        public string $requestKey,
        public AiRate $rate,
        public int $inputTokenBound,
        public int $outputTokenLimit,
        public ?string $boundProfile = null,
    ) {
        GrantInput::identity($tenantId);
        GrantInput::identity($usageId);
        foreach ([$executionId, $requestKey] as $key) {
            if (!preg_match('/^[a-z0-9][a-z0-9._:\/-]{0,127}$/D', $key)) {
                throw new InvalidArgumentException('Canonical request and execution identities are required.');
            }
        }
        if (strlen($rate->provider) > 64 || strlen($rate->model) > 128 ||
            $inputTokenBound < 0 || $outputTokenLimit <= 0) {
            throw new InvalidArgumentException('Valid provider/model and enforced token bounds are required.');
        }
        // Assume no cache discount. Output includes all billable reasoning tokens.
        $this->credits = (string) Amount::reservation((new AiPricing())->request($rate, $inputTokenBound, 0, $outputTokenLimit));
        $snapshot = $rate->jsonSerialize();
        $snapshot['multiplier'] = (string) $rate->multiplier->strippedOfTrailingZeros();
        $snapshot['inputTokenBound'] = $inputTokenBound;
        $snapshot['outputTokenLimit'] = $outputTokenLimit;
        if ($boundProfile !== null) {
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:\/-]{0,63}$/D', $boundProfile)) {
                throw new InvalidArgumentException('Valid provider bound profile required.');
            }
            $snapshot['boundProfile'] = $boundProfile;
        }
        $this->snapshotJson = json_encode($snapshot, JSON_THROW_ON_ERROR);
        $this->hash = hash('sha256', json_encode([
            'ai-request-v1', $tenantId, $usageId, $executionId, $requestKey, $this->snapshotJson, $this->credits,
        ], JSON_THROW_ON_ERROR));
    }
}
