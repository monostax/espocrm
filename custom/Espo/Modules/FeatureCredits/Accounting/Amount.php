<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use JsonSerializable;
use OverflowException;
use Stringable;

/** A posted/held NUMERIC(14,4) quantity. Never use this to accumulate unrounded usage. */
final readonly class Amount implements JsonSerializable, Stringable
{
    private function __construct(private BigDecimal $value)
    {
        if ($value->abs()->isGreaterThan('9999999999.9999')) {
            throw new OverflowException('Credit amount exceeds NUMERIC(14,4).');
        }
    }

    /** Reject numeric JSON values even when called by non-strict PHP code. */
    public static function fromString(mixed $value): self
    {
        if (!is_string($value) || !preg_match('/^-?(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,4})?$/D', $value)) {
            throw new InvalidArgumentException('Credits require a decimal string with at most four fractional digits.');
        }

        return new self(BigDecimal::of($value)->toScale(4));
    }

    /** Only call after summing all exact billable request charges for the operation. */
    public static function settlement(BigDecimal $exact): self
    {
        if ($exact->isNegative()) {
            throw new InvalidArgumentException('Usage cannot be negative.');
        }

        return new self($exact->toScale(4, RoundingMode::HALF_UP));
    }

    /** Conservative authorization, independently rounded from final consumption. */
    public static function reservation(BigDecimal $bound): self
    {
        if ($bound->isNegative()) {
            throw new InvalidArgumentException('Reservation bounds cannot be negative.');
        }

        return new self($bound->toScale(4, RoundingMode::CEILING));
    }

    public function plus(self $other): self
    {
        return new self($this->value->plus($other->value));
    }

    public function minus(self $other): self
    {
        return new self($this->value->minus($other->value));
    }

    public function compareTo(self $other): int
    {
        return $this->value->compareTo($other->value);
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }

    public function jsonSerialize(): string
    {
        return (string) $this;
    }
}
