<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Modules\FeatureCredits\Accounting\DispatchInput;
use Espo\Modules\FeatureCredits\Pricing\AiModelPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** A measured request holds what its adapter proved, plus input headroom, never more than the ceilings. */
final class MeasuredHoldTest extends TestCase
{
    private function policy(array $changes = []): AiModelPolicy
    {
        return new AiModelPolicy([...['provider' => 'google', 'model' => 'gemini-3.8-flash', 'multiplier' => '1',
            'inputTokenBound' => 262144, 'outputTokenLimit' => 16384, 'boundProfile' => 'gemini38-flash-medium-signature-bytes-v1'], ...$changes]);
    }

    private function hold(array $measured, ?AiModelPolicy $policy = null): string
    {
        return ($policy ?? $this->policy())->request('tenant', 'usage', 'execution', 'key', $measured)->credits;
    }

    public function testCeilingsWithoutAMeasurement(): void
    {
        $this->assertSame('12.9024', $this->policy()->request('tenant', 'usage', 'execution', 'key')->credits);
    }

    public function testHeadroomIsTwentyPercentOrAtLeast2048Tokens(): void
    {
        // 24,000 + 4,800 input; 16,384 output.
        $this->assertSame('4.1520', $this->hold(['inputTokens' => 24000, 'outputTokens' => 16384]));
        // 1,000 + 2,048 input (the minimum); a smaller output cap holds less.
        $this->assertSame('0.1893', $this->hold(['inputTokens' => 1000, 'outputTokens' => 400]));
    }

    public function testHeadroomNeverPassesTheApprovedInputBound(): void
    {
        $this->assertSame($this->policy()->request('tenant', 'usage', 'execution', 'key')->credits,
            $this->hold(['inputTokens' => 262144, 'outputTokens' => 16384]));
        $this->assertSame($this->hold(['inputTokens' => 262144, 'outputTokens' => 16384]),
            $this->hold(['inputTokens' => 250000, 'outputTokens' => 16384]));
    }

    public function testCacheWritePricedPoliciesHoldTheMeasuredInputAtTheWriteRate(): void
    {
        $policy = $this->policy(['provider' => 'openrouter', 'model' => 'anthropic/claude-haiku-5.5', 'multiplier' => '0.16',
            'formula' => 'ai-per-10000-v2', 'outputTokenLimit' => 32768, 'boundProfile' => 'openrouter-incremental-bound-v1']);
        $measured = $this->hold(['inputTokens' => 24000, 'outputTokens' => 32768], $policy);
        $this->assertLessThan((float) $policy->request('tenant', 'usage', 'execution', 'key')->credits, (float) $measured);
        $this->assertGreaterThan(0, (float) $measured);
    }

    public function testTheMeasurementIsPartOfTheRequestIdentity(): void
    {
        $policy = $this->policy();
        $a = $policy->request('tenant', 'usage', 'execution', 'key', ['inputTokens' => 24000, 'outputTokens' => 16384]);
        $b = $policy->request('tenant', 'usage', 'execution', 'key', ['inputTokens' => 24001, 'outputTokens' => 16384]);
        $this->assertNotSame($a->hash, $b->hash);
        $this->assertSame($a->hash, $policy->request('tenant', 'usage', 'execution', 'key', ['inputTokens' => 24000, 'outputTokens' => 16384])->hash);
    }

    /** @return iterable<string, array{array{inputTokens: int, outputTokens: int}}> */
    public static function invalid(): iterable
    {
        yield 'no input' => [['inputTokens' => 0, 'outputTokens' => 1]];
        yield 'no output' => [['inputTokens' => 1, 'outputTokens' => 0]];
        yield 'input above the bound' => [['inputTokens' => 262145, 'outputTokens' => 1]];
        yield 'output above the limit' => [['inputTokens' => 1, 'outputTokens' => 16385]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testAMeasurementOutsideThePolicyIsRefused(array $measured): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->hold($measured);
    }

    public function testIntentHoldIsOptionalStrictAndBounded(): void
    {
        $intent = fn (mixed $hold = null) => (object) [
            'scope' => (object) ['billingRegime' => 'unified-prepaid-v1', 'tenantId' => 'tenant', 'usageId' => 'usage',
                'runId' => str_repeat('a', 17), 'workflowRunId' => 'workflow', 'executionId' => '00000000-0000-4000-8000-000000000000'],
            'requestKey' => 'key', 'requestHash' => str_repeat('a', 64), 'kind' => 'generate',
            'policy' => (object) $this->policy()->receipt(), ...($hold === null ? [] : ['hold' => $hold])];
        DispatchInput::intent($intent());
        DispatchInput::intent($intent((object) ['inputTokens' => 24000, 'outputTokens' => 16384]));
        foreach ([(object) ['inputTokens' => 24000], (object) ['inputTokens' => 1, 'outputTokens' => 1, 'extra' => 1],
            (object) ['inputTokens' => '1', 'outputTokens' => 1], (object) ['inputTokens' => 262145, 'outputTokens' => 1], []] as $bad) {
            try {
                DispatchInput::intent($intent($bad));
                $this->fail('Invalid hold accepted: ' . json_encode($bad));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
