<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Modules\FeatureCredits\Accounting\ReconciliationInput;
use Espo\Modules\FeatureCredits\Accounting\ReconciliationPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReconciliationInputTest extends TestCase
{
    public function testCanonicalDetachedEvidence(): void
    {
        $policy = new ReconciliationPolicy('policy-v1', 'operator', 120, 60, 2);
        $evidence = (object) ['reference' => 'lookup-1', 'reason' => 'not retained', 'source' => 'provider'];
        $input = new ReconciliationInput('tenant', 'usage', 'execution', 'request', 'attempt', 'operator',
            '2026-10-10 12:00:00', $policy, $evidence);
        $evidence->reason = 'changed';
        $this->assertSame('not retained', json_decode($input->json)->evidence->reason);
        $same = new ReconciliationInput('tenant', 'usage', 'execution', 'request', 'attempt', 'operator',
            '2026-10-10 12:00:00', $policy, (object) ['source' => 'provider', 'reason' => 'not retained', 'reference' => 'lookup-1']);
        $this->assertSame($input->hash, $same->hash);
    }

    public static function invalidPolicies(): iterable
    {
        yield ['Policy', 'operator', 120, 60, 2];
        yield ['policy', '', 120, 60, 2];
        yield ['policy', 'operator', 0, 60, 2];
        yield ['policy', 'operator', 120, 0, 2];
        yield ['policy', 'operator', 120, 60, 1];
        yield ['policy', 'operator', 120, 60, 4];
        yield ['policy', 'operator', 31536001, 60, 2];
        yield ['policy', 'operator', 120, PHP_INT_MAX, 2];
    }

    #[DataProvider('invalidPolicies')]
    public function testInvalidPolicy(string $version, string $operator, int $deadline, int $retry, int $attempts): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReconciliationPolicy($version, $operator, $deadline, $retry, $attempts);
    }

    public static function invalidInputs(): iterable
    {
        yield ['operator', '2026-02-30 12:00:00', (object) ['source' => 'p', 'reference' => 'r', 'reason' => 'unknown']];
        yield ['takeover', '2026-10-10 12:00:00', (object) ['source' => 'p', 'reference' => 'r', 'reason' => 'unknown']];
        yield ['operator', '2026-10-10 12:00:00', (object) []];
        yield ['operator', '2026-10-10 12:00:00', (object) ['source' => 'p', 'reference' => ' ', 'reason' => 'unknown']];
        yield ['operator', '2026-10-10 12:00:00', (object) ['source' => 'p', 'reference' => 'r', 'reason' => 'unknown', 'float' => 1.5]];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidInput(string $operator, string $time, \stdClass $evidence): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReconciliationInput('tenant', 'usage', 'execution', 'request', 'attempt', $operator, $time,
            new ReconciliationPolicy('policy', 'operator', 120, 60, 2), $evidence);
    }
}
