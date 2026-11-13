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
        // `formula` is optional: absent means the original ai-per-10000-v1 formula.
        if (array_diff(array_keys($entry), [...$fields, 'formula']) || array_diff($fields, array_keys($entry))) {
            throw new InvalidArgumentException('Complete model policy required.');
        }
        $formula = $entry['formula'] ?? AiRate::FORMULA;
        if (!is_string($formula) || !in_array($formula, AiRate::FORMULAS, true)) {
            throw new InvalidArgumentException('Unsupported credit pricing formula.');
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
        $rate = new AiRate('validation', $entry['provider'], $entry['model'], $entry['multiplier'], $formula);
        $this->inputTokenBound = $entry['inputTokenBound'];
        $this->outputTokenLimit = $entry['outputTokenLimit'];
        $this->boundProfile = $entry['boundProfile'];
        $multiplier = (string) $rate->multiplier->strippedOfTrailingZeros();
        // The v1 content address is frozen: published v1 policy ids must never change.
        $content = $formula === AiRate::FORMULA
            ? ['ai-model-policy-v1', $rate->provider, $rate->model,
                AiRate::FORMULA, AiRate::TOKENS_PER_UNIT, AiRate::UNCACHED_INPUT, AiRate::CACHED_INPUT, AiRate::OUTPUT,
                $multiplier, $this->inputTokenBound, $this->outputTokenLimit, $this->boundProfile]
            : ['ai-model-policy-v2', $rate->provider, $rate->model,
                $formula, AiRate::TOKENS_PER_UNIT, AiRate::UNCACHED_INPUT, AiRate::CACHED_INPUT,
                AiRate::CACHE_WRITE_INPUT, AiRate::OUTPUT,
                $multiplier, $this->inputTokenBound, $this->outputTokenLimit, $this->boundProfile];
        $id = hash('sha256', json_encode($content, JSON_THROW_ON_ERROR));
        $this->rate = new AiRate($id, $rate->provider, $rate->model, $multiplier, $formula);
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
