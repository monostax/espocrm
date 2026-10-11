<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Modules\FeatureCredits\Accounting\ReservationInput;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReservationInputTest extends TestCase
{
    public function testMinimumAndCanonicalReplayPreserveOriginalBound(): void
    {
        $make = fn (string $amount) => new ReservationInput('tenant', 'ai', 'operation', 'execution', 'rate', $amount);
        $this->assertSame('0.5000', $make('0.1')->credits);
        $this->assertSame($make('0.1')->hash, $make('0.1000')->hash);
        $this->assertNotSame($make('0.1')->hash, $make('0.2')->hash);
        $this->assertSame('0.0001', (new ReservationInput('tenant', 'apollo', 'op', 'exec', 'rate', '0.0001'))->credits);
    }

    public static function invalidInputs(): iterable
    {
        yield 'float' => ['ai', 'op', 'exec', 0.1];
        yield 'negative' => ['ai', 'op', 'exec', '-1'];
        yield 'zero' => ['ai', 'op', 'exec', '0'];
        yield 'unrounded' => ['ai', 'op', 'exec', '0.00001'];
        yield 'operation type' => ['transcription', 'op', 'exec', '1'];
        yield 'case ambiguity' => ['ai', 'Op', 'exec', '1'];
        yield 'execution ambiguity' => ['ai', 'op', 'Exec', '1'];
        yield 'empty' => ['ai', '', 'exec', '1'];
        yield 'length' => ['ai', str_repeat('a', 129), 'exec', '1'];
    }

    #[DataProvider('invalidInputs')]
    public function testRejectInvalidInput(string $type, string $key, string $execution, mixed $bound): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReservationInput('tenant', $type, $key, $execution, 'rate', $bound);
    }
}
