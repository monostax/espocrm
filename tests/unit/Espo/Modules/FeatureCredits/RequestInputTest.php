<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Modules\FeatureCredits\Accounting\RequestInput;
use Espo\Modules\FeatureCredits\Pricing\AiRate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RequestInputTest extends TestCase
{
    public function testConservativeBoundAndCanonicalSnapshot(): void
    {
        $input = new RequestInput('tenant', 'usage', 'execution', 'request', new AiRate('v1', 'provider', 'model', '1.00'), 1, 1);
        $this->assertSame('0.0003', $input->credits);
        $this->assertSame($input->hash, (new RequestInput('tenant', 'usage', 'execution', 'request',
            new AiRate('v1', 'provider', 'model', '1'), 1, 1))->hash);
        $snapshot = json_decode($input->snapshotJson, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('ai-per-10000-v1', $snapshot['formula']);
        $this->assertSame(1, $snapshot['outputTokenLimit']);
        $this->assertSame('0.3750', $snapshot['uncachedInputCredits']);
    }

    public static function invalid(): iterable
    {
        yield ['Request', 0, 1, 'provider', 'model'];
        yield ['request', -1, 1, 'provider', 'model'];
        yield ['request', 0, 0, 'provider', 'model'];
        yield ['request', 0, -1, 'provider', 'model'];
        yield ['request', 0, 1, str_repeat('p', 65), 'model'];
        yield ['request', 0, 1, 'provider', str_repeat('m', 129)];
    }

    #[DataProvider('invalid')]
    public function testInvalidBoundsAndIdentities(string $key, int $input, int $output, string $provider, string $model): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RequestInput('tenant', 'usage', 'execution', $key, new AiRate('v1', $provider, $model, '1'), $input, $output);
    }
}
