<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Pricing;

use Brick\Math\BigDecimal;
use InvalidArgumentException;
use JsonSerializable;

/** Immutable applied-rate value; persistence/activation belongs to the accounting service. */
final readonly class AiRate implements JsonSerializable
{
    public const FORMULA = 'ai-per-10000-v1';
    /** v1 plus cache-write input, priced at 1.25x uncached input (Anthropic, OpenAI GPT-5.6+). */
    public const FORMULA_V2 = 'ai-per-10000-v2';
    public const FORMULAS = [self::FORMULA, self::FORMULA_V2];
    public const TOKENS_PER_UNIT = 10000;
    public const UNCACHED_INPUT = '0.3750';
    public const CACHED_INPUT = '0.0375';
    public const CACHE_WRITE_INPUT = '0.46875';
    public const OUTPUT = '1.8750';

    public BigDecimal $multiplier;

    public function __construct(
        public string $id,
        public string $provider,
        public string $model,
        mixed $multiplier,
        public string $formula = self::FORMULA,
    ) {
        if (!in_array($formula, self::FORMULAS, true)) {
            throw new InvalidArgumentException('Unsupported credit pricing formula.');
        }
        foreach ([$id, $provider, $model] as $identity) {
            if (trim($identity) === '' || strlen($identity) > 255) {
                throw new InvalidArgumentException('Applied rate, provider, and model identities are required.');
            }
        }
        if (!is_string($multiplier) ||
            !preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,10})?$/D', $multiplier)) {
            throw new InvalidArgumentException('Model multiplier must be a positive decimal string.');
        }
        $this->multiplier = BigDecimal::of($multiplier);
        if (!$this->multiplier->isPositive()) {
            throw new InvalidArgumentException('Model multiplier must be positive.');
        }
    }

    public function pricesCacheWrites(): bool
    {
        return $this->formula === self::FORMULA_V2;
    }

    /** v1 output is byte-identical to the original snapshot; v2 adds only the write rate. */
    public function jsonSerialize(): array
    {
        $snapshot = [
            'id' => $this->id,
            'provider' => $this->provider,
            'model' => $this->model,
            'formula' => $this->formula,
            'multiplier' => (string) $this->multiplier,
            'tokensPerUnit' => self::TOKENS_PER_UNIT,
            'uncachedInputCredits' => self::UNCACHED_INPUT,
            'cachedInputCredits' => self::CACHED_INPUT,
        ];
        if ($this->pricesCacheWrites()) {
            $snapshot['cacheWriteInputCredits'] = self::CACHE_WRITE_INPUT;
        }
        $snapshot['outputCredits'] = self::OUTPUT;
        return $snapshot;
    }
}
