<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Brick\Math\BigDecimal;
use DomainException;
use Espo\Modules\FeatureCredits\Pricing\AiPricing;
use Espo\Modules\FeatureCredits\Pricing\AiRate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AiPricingTest extends TestCase
{
    private function rate(string $multiplier = '1'): AiRate
    {
        return new AiRate('test-rate-v1', 'test-provider', 'test-model', $multiplier);
    }

    #[DataProvider('tokenPrices')]
    public function testExactPerTenThousandPricing(int $input, int $cached, int $output, string $expected): void
    {
        $actual = (new AiPricing())->request($this->rate(), $input, $cached, $output);
        $this->assertTrue($actual->isEqualTo($expected), (string) $actual);
    }

    public static function tokenPrices(): iterable
    {
        yield [10000, 0, 0, '0.375'];
        yield [10000, 10000, 0, '0.0375'];
        yield [0, 0, 10000, '1.875'];
        yield [1, 0, 0, '0.0000375'];
        yield [10001, 0, 0, '0.3750375'];
        yield [10000, 4000, 2000, '0.615'];
        yield [0, 0, 0, '0'];
        // Large counters must not overflow integer multiplication or use floats.
        yield [PHP_INT_MAX, 0, 0, '345876451382054.0927625'];
    }

    public function testRoundOnceAcrossMixedModelRequests(): void
    {
        $pricing = new AiPricing();
        $lite = $pricing->request($this->rate('0.2'), 1, 0, 0);
        $frontier = $pricing->request($this->rate('3.5'), 1, 0, 0);
        $this->assertSame('0.0001', (string) $pricing->settle([$lite, $frontier]));
        $oneToken = $pricing->request($this->rate(), 1, 0, 0);
        $this->assertSame('0.0000', (string) $pricing->settle([$oneToken]));
        $this->assertSame('0.0001', (string) $pricing->settle([$oneToken, $oneToken]));
        $this->assertSame('0.0000', (string) $pricing->settle([]));
    }

    public function testInitialMinimumIsNotAddedAndAccruedChargesStayHeld(): void
    {
        $pricing = new AiPricing();
        $this->assertSame('0.5000', (string) $pricing->requiredHold([BigDecimal::of('0.2')], true));
        $this->assertSame('1.0001', (string) $pricing->requiredHold([BigDecimal::of('1.00001')], true));
        $this->assertSame('0.6001', (string) $pricing->requiredHold([
            BigDecimal::of('0.30001'), BigDecimal::of('0.2'), BigDecimal::of('0.1'),
        ], false));
    }

    #[DataProvider('unknownUsage')]
    public function testUnknownUsageCannotBecomeZero(?int $input, ?int $cached, ?int $output): void
    {
        $this->expectException(DomainException::class);
        (new AiPricing())->request($this->rate(), $input, $cached, $output);
    }

    public static function unknownUsage(): iterable
    {
        yield [null, 0, 0];
        yield [0, null, 0];
        yield [0, 0, null];
    }

    #[DataProvider('invalidUsage')]
    public function testInvalidNormalizedUsageIsRejected(int $input, int $cached, int $output): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AiPricing())->request($this->rate(), $input, $cached, $output);
    }

    public static function invalidUsage(): iterable
    {
        yield [-1, 0, 0];
        yield [0, -1, 0];
        yield [0, 0, -1];
        yield [10, 11, 0];
    }

    #[DataProvider('invalidMultipliers')]
    public function testMultiplierRequiresPositiveDecimalString(mixed $multiplier): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AiRate('v1', 'provider', 'model', $multiplier);
    }

    public static function invalidMultipliers(): iterable
    {
        foreach ([0.2, 1, '0', '-1', '1e2', '', '1.00000000001'] as $value) yield [$value];
    }

    public function testAppliedRateSnapshotContainsReproducibleFormulaAndIdentity(): void
    {
        $snapshot = json_decode(json_encode($this->rate('3.5'), JSON_THROW_ON_ERROR), true);
        $this->assertSame('ai-per-10000-v1', $snapshot['formula']);
        $this->assertSame('test-rate-v1', $snapshot['id']);
        $this->assertSame('test-provider', $snapshot['provider']);
        $this->assertSame('test-model', $snapshot['model']);
        $this->assertSame('3.5', $snapshot['multiplier']);
        $this->assertSame('0.0375', $snapshot['cachedInputCredits']);
        $this->assertSame(10000, $snapshot['tokensPerUnit']);
    }

    #[DataProvider('invalidCharges')]
    public function testCannotHideInvalidChargesInOperationTotals(mixed $charge): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AiPricing())->settle([$charge]);
    }

    public static function invalidCharges(): iterable
    {
        yield [BigDecimal::of('-0.1')];
        yield ['0.1'];
        yield [0.1];
        yield [null];
    }

    #[DataProvider('missingIdentity')]
    public function testAppliedRateRequiresAllIdentities(string $id, string $provider, string $model): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AiRate($id, $provider, $model, '1');
    }

    public static function missingIdentity(): iterable
    {
        yield ['', 'provider', 'model'];
        yield ['v1', ' ', 'model'];
        yield ['v1', 'provider', ''];
    }
}
