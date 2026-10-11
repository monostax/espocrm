<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GrantInputTest extends TestCase
{
    public function testCanonicalReplayHashAndDetachedSnapshot(): void
    {
        $evidence = (object) ['z' => [(object) ['b' => '1.25', 'a' => true]], 'a' => 'payment'];
        $first = $this->input(['sourceSnapshot' => $evidence]);
        $second = $this->input(['credits' => '1.0000', 'sourceSnapshot' =>
            (object) ['a' => 'payment', 'z' => [(object) ['a' => true, 'b' => '1.25']]]]);
        $this->assertSame($first->hash, $second->hash);
        $evidence->a = 'changed';
        $this->assertSame($first->snapshotJson, $second->snapshotJson);
        foreach (['tenantId' => 'other', 'credits' => '2', 'actorId' => 'actor', 'occurredAt' => '2026-10-01 00:00:01'] as $key => $value) {
            $this->assertNotSame($this->input()->hash, $this->input([$key => $value])->hash);
        }
    }

    public static function invalid(): iterable
    {
        yield [['credits' => 1.5]];
        yield [['credits' => '0']];
        yield [['credits' => '-1']];
        yield [['sourceKey' => 'Payment-A']];
        yield [['sourceKey' => 'payment ']];
        yield [['tenantId' => 'tenant OR 1=1']];
        yield [['sourceType' => 'adjustment']];
        yield [['expiresAt' => '2026-11-01 00:00:00']];
        yield [['sourceType' => 'subscription']];
        yield [['sourceType' => 'migration', 'expiresAt' => '2026-09-01 00:00:00']];
        yield [['occurredAt' => '2026-02-30 00:00:00']];
        yield [['occurredAt' => '2026-10-01T00:00:00Z']];
        yield [['sourceSnapshot' => (object) ['price' => 1.5]]];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidCommands(array $overrides): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->input($overrides);
    }

    private function input(array $overrides = []): GrantInput
    {
        return new GrantInput(...array_replace([
            'tenantId' => 'tenant', 'sourceType' => 'purchase', 'sourceKey' => 'payment-1',
            'credits' => '1', 'occurredAt' => '2026-10-01 00:00:00', 'expiresAt' => null,
            'sourceSnapshot' => (object) ['verifiedPayment' => 'payment-1'],
        ], $overrides));
    }
}
