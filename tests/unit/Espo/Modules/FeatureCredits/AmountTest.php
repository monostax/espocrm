<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Brick\Math\BigDecimal;
use Espo\Modules\FeatureCredits\Accounting\Amount;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AmountTest extends TestCase
{
    public function testExactArithmeticAndStringSerialization(): void
    {
        $amount = Amount::fromString('0.1')->plus(Amount::fromString('0.2'));
        $this->assertSame('0.3000', (string) $amount);
        $this->assertSame('"0.3000"', json_encode($amount));
        $this->assertSame('-0.2000', (string) $amount->minus(Amount::fromString('0.5')));
        $this->assertSame('0.0000', (string) Amount::fromString('-0.0000'));
        $this->assertSame(0, $amount->compareTo(Amount::fromString('0.3000')));
        $this->assertSame('9999999999.9999', (string) Amount::fromString('9999999999.9999'));
        $this->assertSame('-9999999999.9999', (string) Amount::fromString('-9999999999.9999'));
    }

    #[DataProvider('invalidAmounts')]
    public function testRejectsNonDecimalOrLossyInput(mixed $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        Amount::fromString($input);
    }

    public static function invalidAmounts(): iterable
    {
        foreach ([0.1, 1, null, true, [], '', '1e2', ' 1', '1\n', '+1', '01', '.1', '1.',
            '1,00', '0.00001', '10000000000', '-10000000000', 'NaN', 'INF'] as $input) {
            yield [$input];
        }
    }

    #[DataProvider('overflowOperations')]
    public function testRejectsOverflow(string $operation): void
    {
        $this->expectException(OverflowException::class);
        match ($operation) {
            'add' => Amount::fromString('9999999999.9999')->plus(Amount::fromString('0.0001')),
            'subtract' => Amount::fromString('-9999999999.9999')->minus(Amount::fromString('0.0001')),
            'settle' => Amount::settlement(BigDecimal::of('9999999999.99995')),
            'reserve' => Amount::reservation(BigDecimal::of('9999999999.99991')),
        };
    }

    public static function overflowOperations(): iterable
    {
        foreach (['add', 'subtract', 'settle', 'reserve'] as $operation) yield [$operation];
    }

    public function testRoundingBoundaries(): void
    {
        $this->assertSame('0.0000', (string) Amount::settlement(BigDecimal::of('0.000049999')));
        $this->assertSame('0.0001', (string) Amount::settlement(BigDecimal::of('0.00005')));
        $this->assertSame('0.0001', (string) Amount::reservation(BigDecimal::of('0.000000001')));
        $this->assertSame('0.5000', (string) Amount::reservation(BigDecimal::of('0.5')));
    }

    #[DataProvider('negativeQuantities')]
    public function testRejectsNegativeUsageAndBounds(string $method): void
    {
        $this->expectException(InvalidArgumentException::class);
        Amount::$method(BigDecimal::of('-0.00001'));
    }

    public static function negativeQuantities(): iterable
    {
        yield ['settlement'];
        yield ['reservation'];
    }
}
