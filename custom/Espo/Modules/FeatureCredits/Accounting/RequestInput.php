<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Espo\Modules\FeatureCredits\Pricing\AiPricing;
use Espo\Modules\FeatureCredits\Pricing\AiRate;
use InvalidArgumentException;

/** Trusted adapter supplies resolved pricing and reservation limits. Ordinary
 * profiles enforce conservative caps; the explicit native-search profile permits
 * measured overruns only when selected by this tenant's original agreement.
 *
 * A measured request (the adapter proved its input size and fixed its output
 * cap before dispatch) holds those, plus headroom on the input, never more than
 * the approved ceilings. Without a measurement it holds the ceilings. */
final readonly class RequestInput
{
    /** Headroom over a measured input: a provider may bill a little more than its count. */
    public const HOLD_INPUT_HEADROOM_PERCENT = 20;
    public const HOLD_INPUT_HEADROOM_MIN = 2048;

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
        public ?int $measuredInputTokens = null,
        public ?int $measuredOutputTokens = null,
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
        if (($measuredInputTokens === null) !== ($measuredOutputTokens === null) ||
            $measuredInputTokens !== null && ($measuredInputTokens <= 0 || $measuredInputTokens > $inputTokenBound ||
            $measuredOutputTokens <= 0 || $measuredOutputTokens > $outputTokenLimit)) {
            throw new InvalidArgumentException('A measured hold must fit the approved token bounds.');
        }
        $holdInput = $measuredInputTokens === null ? $inputTokenBound : min($inputTokenBound, $measuredInputTokens +
            max(self::HOLD_INPUT_HEADROOM_MIN, intdiv($measuredInputTokens * self::HOLD_INPUT_HEADROOM_PERCENT + 99, 100)));
        $holdOutput = $measuredOutputTokens ?? $outputTokenLimit;
        // Assume no cache discount. Output includes all billable reasoning tokens.
        // v2: every input token may be a (dearer) cache write, so hold the bound at the write rate.
        $this->credits = (string) Amount::reservation((new AiPricing())->request($rate, $holdInput, 0, $holdOutput,
            $rate->pricesCacheWrites() ? $holdInput : 0));
        $snapshot = $rate->jsonSerialize();
        $snapshot['multiplier'] = (string) $rate->multiplier->strippedOfTrailingZeros();
        $snapshot['inputTokenBound'] = $inputTokenBound;
        $snapshot['outputTokenLimit'] = $outputTokenLimit;
        if ($measuredInputTokens !== null) {
            $snapshot['holdInputTokens'] = $holdInput;
            $snapshot['holdOutputTokens'] = $holdOutput;
        }
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
