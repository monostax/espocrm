<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Pricing;

use Brick\Math\BigDecimal;
use DomainException;
use Espo\Modules\FeatureCredits\Accounting\Amount;
use InvalidArgumentException;

/** Pure arithmetic. Callers must resolve request billability and completeness before settlement. */
final class AiPricing
{
    /**
     * Input includes cached and cache-written input; normalized output already
     * includes reasoning. Null is unknown metering, never zero. Cache writes are
     * priced only by the v2 formula; a v1 rate accepts zero writes only.
     * The returned charge is NOT rounded.
     */
    public function request(AiRate $rate, ?int $input, ?int $cachedInput, ?int $output, ?int $cacheWriteInput = 0): BigDecimal
    {
        if ($input === null || $cachedInput === null || $output === null || $cacheWriteInput === null) {
            throw new DomainException('Incomplete usage requires reconciliation.');
        }
        if ($input < 0 || $cachedInput < 0 || $output < 0 || $cacheWriteInput < 0 || $cachedInput > $input ||
            $cacheWriteInput > $input - $cachedInput) {
            throw new InvalidArgumentException('Invalid normalized token counts.');
        }
        if ($cacheWriteInput > 0 && !$rate->pricesCacheWrites()) {
            throw new InvalidArgumentException('Cache-write input is not priced by this rate.');
        }

        $units = BigDecimal::of($input - $cachedInput - $cacheWriteInput)->multipliedBy(AiRate::UNCACHED_INPUT)
            ->plus(BigDecimal::of($cachedInput)->multipliedBy(AiRate::CACHED_INPUT));
        // v1 arithmetic (and so its exact decimal scale) is unchanged.
        if ($rate->pricesCacheWrites()) {
            $units = $units->plus(BigDecimal::of($cacheWriteInput)->multipliedBy(AiRate::CACHE_WRITE_INPUT));
        }

        return $units->plus(BigDecimal::of($output)->multipliedBy(AiRate::OUTPUT))
            ->multipliedBy($rate->multiplier)
            ->dividedByExact(AiRate::TOKENS_PER_UNIT);
    }

    /** @param iterable<BigDecimal> $billableCharges Successful requests survive a failed parent run. */
    public function settle(iterable $billableCharges): Amount
    {
        return Amount::settlement($this->sum($billableCharges));
    }

    /**
     * Total required hold, not an incremental extension. Retain accrued usage
     * plus EVERY authorized in-flight bound. The service computes the delta
     * under its wallet lock and allocates eligible grants before authorizing.
     *
     * @param iterable<BigDecimal> $accruedAndInFlight
     */
    public function requiredHold(iterable $accruedAndInFlight, bool $initial): Amount
    {
        $total = $this->sum($accruedAndInFlight);
        if ($initial && $total->isLessThan('0.5000')) {
            $total = BigDecimal::of('0.5000');
        }

        return Amount::reservation($total);
    }

    /** @param iterable<BigDecimal> $charges */
    private function sum(iterable $charges): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($charges as $charge) {
            if (!$charge instanceof BigDecimal || $charge->isNegative()) {
                throw new InvalidArgumentException('Expected non-negative exact request charges.');
            }
            $total = $total->plus($charge);
        }

        return $total;
    }
}
