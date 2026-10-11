<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Pricing;

use Espo\Modules\FeatureCredits\Accounting\RequestInput;
use InvalidArgumentException;

/** Explicit approved provider ceilings. No token estimation or market pricing defaults. */
final readonly class AiModelPolicy
{
    public AiRate $rate;
    public int $inputTokenBound;
    public int $outputTokenLimit;
    public string $boundProfile;

    public function __construct(array $entry)
    {
        $fields = ['provider', 'model', 'multiplier', 'inputTokenBound', 'outputTokenLimit', 'boundProfile'];
        if (array_diff(array_keys($entry), $fields) || array_diff($fields, array_keys($entry))) {
            throw new InvalidArgumentException('Complete model policy required.');
        }
        foreach (['provider' => 64, 'model' => 128, 'boundProfile' => 64] as $field => $maximum) {
            if (!is_string($entry[$field]) || strlen($entry[$field]) > $maximum ||
                !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:\/-]*$/D', $entry[$field])) {
                throw new InvalidArgumentException('Invalid model policy identity.');
            }
        }
        foreach (['inputTokenBound', 'outputTokenLimit'] as $field) {
            if (!is_int($entry[$field]) || $entry[$field] <= 0 || $entry[$field] > 9007199254740991) {
                throw new InvalidArgumentException('Approved positive safe integer token ceilings required.');
            }
        }
        $rate = new AiRate('validation', $entry['provider'], $entry['model'], $entry['multiplier']);
        $this->inputTokenBound = $entry['inputTokenBound'];
        $this->outputTokenLimit = $entry['outputTokenLimit'];
        $this->boundProfile = $entry['boundProfile'];
        $id = hash('sha256', json_encode(['ai-model-policy-v1', $rate->provider, $rate->model,
            AiRate::FORMULA, AiRate::TOKENS_PER_UNIT, AiRate::UNCACHED_INPUT, AiRate::CACHED_INPUT, AiRate::OUTPUT,
            (string) $rate->multiplier->strippedOfTrailingZeros(), $this->inputTokenBound, $this->outputTokenLimit,
            $this->boundProfile], JSON_THROW_ON_ERROR));
        $this->rate = new AiRate($id, $rate->provider, $rate->model, (string) $rate->multiplier->strippedOfTrailingZeros());
        // Reject policies whose conservative hold cannot fit the accounting amount range.
        $this->request('validation', 'validation', 'validation', 'validation');
    }

    public function request(string $tenantId, string $usageId, string $executionId, string $requestKey): RequestInput
    {
        return new RequestInput($tenantId, $usageId, $executionId, $requestKey, $this->rate,
            $this->inputTokenBound, $this->outputTokenLimit, $this->boundProfile);
    }

    public function receipt(): array
    {
        return ['modelRateId' => $this->rate->id, 'provider' => $this->rate->provider, 'model' => $this->rate->model,
            'inputTokenBound' => $this->inputTokenBound, 'outputTokenLimit' => $this->outputTokenLimit, 'boundProfile' => $this->boundProfile];
    }
}
