<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Modules\FeatureCredits\Accounting\OutcomeInput;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OutcomeInputTest extends TestCase
{
    public function testCanonicalDetachedEvidenceAndUnknownVersusZero(): void
    {
        $evidence = (object) ['z' => 'receipt', 'a' => (object) ['verified' => true]];
        $first = new OutcomeInput('tenant', 'usage', 'execution', 'request', 'cancelled', null, 0, null, $evidence);
        $second = new OutcomeInput('tenant', 'usage', 'execution', 'request', 'cancelled', null, 0, null,
            (object) ['a' => (object) ['verified' => true], 'z' => 'receipt']);
        $this->assertSame($first->hash, $second->hash);
        $evidence->a->verified = false;
        $this->assertSame($first->json, $second->json);
        $this->assertFalse($first->measured());
        $zero = new OutcomeInput('tenant', 'usage', 'execution', 'request', 'success', 0, 0, 0, (object) ['source' => 'provider']);
        $this->assertTrue($zero->measured());
        $this->assertNotSame($first->hash, $zero->hash);
    }

    public static function invalid(): iterable
    {
        yield ['outcome', 'inFlight'];
        yield ['outcome', 'noMatch'];
        yield ['outcome', 'unrecoverable'];
        yield ['inputTokens', -1];
        yield ['cachedInputTokens', 11];
        yield ['outputTokens', -1];
        yield ['executionId', 'UPPER'];
        yield ['tenantId', '../tenant'];
        yield ['providerRequestId', ''];
        yield ['providerRequestId', str_repeat('a', 129)];
        yield ['evidence', (object) []];
        yield ['evidence', (object) ['amount' => 0.1]];
    }

    #[DataProvider('invalid')]
    public function testInvalidCommands(string $key, mixed $value): void
    {
        $args = ['tenantId' => 'tenant', 'usageId' => 'usage', 'executionId' => 'execution', 'requestId' => 'request',
            'outcome' => 'success', 'inputTokens' => 10, 'cachedInputTokens' => 0, 'outputTokens' => 10,
            'evidence' => (object) ['receipt' => 'provider']];
        $args[$key] = $value;
        $this->expectException(InvalidArgumentException::class);
        new OutcomeInput(...$args);
    }
}
