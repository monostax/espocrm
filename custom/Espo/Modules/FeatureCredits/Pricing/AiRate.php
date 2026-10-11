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
    public const TOKENS_PER_UNIT = 10000;
    public const UNCACHED_INPUT = '0.3750';
    public const CACHED_INPUT = '0.0375';
    public const OUTPUT = '1.8750';

    public BigDecimal $multiplier;

    public function __construct(
        public string $id,
        public string $provider,
        public string $model,
        mixed $multiplier,
    ) {
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

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'model' => $this->model,
            'formula' => self::FORMULA,
            'multiplier' => (string) $this->multiplier,
            'tokensPerUnit' => self::TOKENS_PER_UNIT,
            'uncachedInputCredits' => self::UNCACHED_INPUT,
            'cachedInputCredits' => self::CACHED_INPUT,
            'outputCredits' => self::OUTPUT,
        ];
    }
}
