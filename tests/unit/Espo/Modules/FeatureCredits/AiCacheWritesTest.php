<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

require_once __DIR__ . '/ExecutionFixtures.php';

use DomainException;
use Espo\Modules\FeatureCredits\Accounting\OutcomeInput;
use Espo\Modules\FeatureCredits\Accounting\TerminalInput;
use Espo\Modules\FeatureCredits\Pricing\AiModelPolicy;
use Espo\Modules\FeatureCredits\Pricing\AiPricing;
use Espo\Modules\FeatureCredits\Pricing\AiRate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Formula ai-per-10000-v2 (cache writes) and the frozen v1 contract. */
class AiCacheWritesTest extends TestCase
{
    /** Live production Hydrogen policy (published 2026-11-13), computed before v2 existed. */
    private const HYDROGEN = ['provider' => 'google', 'model' => 'gemini-4-hydrogen', 'multiplier' => '2',
        'inputTokenBound' => 262144, 'outputTokenLimit' => 32768, 'boundProfile' => 'gemini4-hydrogen-signature-bytes-v1'];
    private const HYDROGEN_ID = '86ce13980ba554206000b0d6db5389a9d580615059a20097251bc542bc3e132c';
    private const HYDROGEN_SNAPSHOT = '{"id":"86ce13980ba554206000b0d6db5389a9d580615059a20097251bc542bc3e132c","provider":"google",'
        . '"model":"gemini-4-hydrogen","formula":"ai-per-10000-v1","multiplier":"2","tokensPerUnit":10000,'
        . '"uncachedInputCredits":"0.3750","cachedInputCredits":"0.0375","outputCredits":"1.8750","inputTokenBound":262144,'
        . '"outputTokenLimit":32768,"boundProfile":"gemini4-hydrogen-signature-bytes-v1"}';
    private const HYDROGEN_REQUEST_HASH = 'b546a6d998c0b4d0e6a164c22916ff2b47c0cb4535588069fc90590c2298eab0';
    /** OutcomeInput canonical hash of a fixed report, computed on the pre-v2 code. */
    private const V1_OUTCOME_HASH = '8cac989795f630d8f240a6d95ef8cfa0f44aa4f42c04ff4fef0cc59fd7779fcb';
    private const V1_TERMINAL_HASH = 'b335a4c721108adaec5714aa62bf3632ee7533b2f9140f9821680d10f7c47380';

    public function testPublishedV1PolicyIdHoldAndSnapshotAreUnchanged(): void
    {
        $policy = new AiModelPolicy(self::HYDROGEN);
        $this->assertSame(self::HYDROGEN_ID, $policy->rate->id);
        $this->assertSame(AiRate::FORMULA, $policy->rate->formula);
        $request = $policy->request('tenant', 'usage', 'exec-1', 'conversation.main.0');
        $this->assertSame('31.9488', $request->credits);
        $this->assertSame(self::HYDROGEN_SNAPSHOT, $request->snapshotJson);
        $this->assertSame(self::HYDROGEN_REQUEST_HASH, $request->hash);
        // An explicit v1 formula is the same content address.
        $this->assertSame(self::HYDROGEN_ID, (new AiModelPolicy([...self::HYDROGEN, 'formula' => AiRate::FORMULA]))->rate->id);
        $this->assertSame(['modelRateId', 'provider', 'model', 'inputTokenBound', 'outputTokenLimit', 'boundProfile'],
            array_keys($policy->receipt()), 'The worker receipt contract must not change.');
    }

    public function testV1OutcomeAndTerminalCanonicalFormsAreUnchanged(): void
    {
        $outcome = new OutcomeInput('tenant', 'usage', 'execution', 'request', 'success', 100000, 60000, 2000,
            (object) ['source' => 'provider'], 'gen-1');
        $this->assertSame(self::V1_OUTCOME_HASH, $outcome->hash);
        $this->assertFalse($outcome->reportsCacheWrites);
        $this->assertNull($outcome->cacheWriteInputTokens);
        $this->assertTrue($outcome->measured());
        $terminal = new TerminalInput(ExecutionFixtures::outcome());
        $wire = ExecutionFixtures::outcome();
        $wire->inputTokens = 5; $wire->cachedInputTokens = 1; $wire->outputTokens = 2;
        $this->assertSame(self::V1_TERMINAL_HASH, (new TerminalInput($wire))->outcome->hash);
        $this->assertFalse($terminal->outcome->reportsCacheWrites);
    }

    public function testV2PolicyHasItsOwnAddressSnapshotAndWorstCaseHold(): void
    {
        $policy = new AiModelPolicy([...self::HYDROGEN, 'formula' => AiRate::FORMULA_V2]);
        $this->assertNotSame(self::HYDROGEN_ID, $policy->rate->id);
        $this->assertSame(AiRate::FORMULA_V2, $policy->rate->formula);
        $request = $policy->request('tenant', 'usage', 'exec-1', 'conversation.main.0');
        // (262144 x 0.46875 + 32768 x 1.875) x 2 / 10000: every input token may be a cache write.
        $this->assertSame('36.8640', $request->credits);
        $snapshot = json_decode($request->snapshotJson, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(AiRate::FORMULA_V2, $snapshot['formula']);
        $this->assertSame('0.46875', $snapshot['cacheWriteInputCredits']);
        $this->assertSame(['id', 'provider', 'model', 'formula', 'multiplier', 'tokensPerUnit', 'uncachedInputCredits',
            'cachedInputCredits', 'cacheWriteInputCredits', 'outputCredits', 'inputTokenBound', 'outputTokenLimit', 'boundProfile'],
            array_keys($snapshot));
        $this->assertArrayNotHasKey('formula', $policy->receipt());
        // The worst-case hold covers any split of the bound into uncached, cached and written input.
        $pricing = new AiPricing();
        foreach ([[262144, 0, 0], [262144, 0, 262144], [262144, 131072, 131072], [262144, 262144, 0]] as [$in, $cached, $written]) {
            $this->assertLessThanOrEqual(0, $pricing->request($policy->rate, $in, $cached, 32768, $written)
                ->compareTo($request->credits));
        }
    }

    public static function v2Prices(): iterable
    {
        yield 'write only' => [10000, 0, 0, 10000, '0.46875'];
        yield 'uncached only' => [10000, 0, 0, 0, '0.375'];
        yield 'cached only' => [10000, 10000, 0, 0, '0.0375'];
        yield 'output only' => [0, 0, 10000, 0, '1.875'];
        // 4000 uncached x 0.375 + 4000 cached x 0.0375 + 2000 written x 0.46875 + 1000 output x 1.875
        yield 'mixed' => [10000, 4000, 1000, 2000, '0.44625'];
        yield 'zero' => [0, 0, 0, 0, '0'];
    }

    #[DataProvider('v2Prices')]
    public function testV2ExactPricing(int $input, int $cached, int $output, int $written, string $expected): void
    {
        $rate = new AiRate('rate', 'openrouter', 'anthropic/claude-sonnet-5.5', '1', AiRate::FORMULA_V2);
        $actual = (new AiPricing())->request($rate, $input, $cached, $output, $written);
        $this->assertTrue($actual->isEqualTo($expected), (string) $actual);
        $this->assertTrue((new AiPricing())->request(new AiRate('rate', 'openrouter', 'model', '2.4', AiRate::FORMULA_V2),
            $input, $cached, $output, $written)->isEqualTo($actual->multipliedBy('2.4')));
    }

    public function testV1RateRejectsCacheWritesAndKeepsExactScale(): void
    {
        $v1 = new AiRate('rate', 'google', 'gemini-3.8-flash', '2');
        $this->assertSame((string) (new AiPricing())->request($v1, 100000, 60000, 2000),
            (string) (new AiPricing())->request($v1, 100000, 60000, 2000, 0));
        // Same exact string as the pre-v2 code produced for this report.
        $this->assertSame('4.2', (string) (new AiPricing())->request($v1, 100000, 60000, 2000));
        $this->expectException(InvalidArgumentException::class);
        (new AiPricing())->request($v1, 100, 0, 0, 1);
    }

    public static function invalidV2Usage(): iterable
    {
        yield 'cached plus written exceed input' => [10, 6, 5, InvalidArgumentException::class];
        yield 'negative write' => [10, 0, -1, InvalidArgumentException::class];
        yield 'unknown write' => [10, 0, null, DomainException::class];
    }

    #[DataProvider('invalidV2Usage')]
    public function testInvalidOrUnknownV2Usage(int $input, int $cached, ?int $written, string $exception): void
    {
        $this->expectException($exception);
        (new AiPricing())->request(new AiRate('rate', 'openrouter', 'model', '1', AiRate::FORMULA_V2), $input, $cached, 0, $written);
    }

    public function testUnsupportedFormulaIsRejected(): void
    {
        foreach ([fn () => new AiRate('rate', 'p', 'm', '1', 'ai-per-10000-v3'),
            fn () => new AiModelPolicy([...self::HYDROGEN, 'formula' => 'ai-per-10000-v3']),
            fn () => new AiModelPolicy([...self::HYDROGEN, 'formula' => 2])] as $build) {
            try { $build(); $this->fail('Unsupported formula accepted.'); } catch (InvalidArgumentException) {}
        }
        $this->expectException(InvalidArgumentException::class);
        new AiModelPolicy([...self::HYDROGEN, 'cacheWriteInputCredits' => '0.1']);
    }

    public function testOutcomeCacheWriteCanonicalFormAndUnknownVersusZero(): void
    {
        $args = ['tenant', 'usage', 'execution', 'request', 'success', 100000, 60000, 2000, (object) ['source' => 'provider'], 'gen-1'];
        $zero = new OutcomeInput(...[...$args, 0]);
        $this->assertTrue($zero->reportsCacheWrites);
        $this->assertSame(0, $zero->cacheWriteInputTokens);
        $this->assertTrue($zero->measured());
        $this->assertNotSame(self::V1_OUTCOME_HASH, $zero->hash);
        $json = json_decode($zero->json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('ai-outcome-v2', $json['version']);
        $this->assertSame(0, $json['cacheWriteInputTokens']);
        $unknown = new OutcomeInput(...[...$args, null]);
        $this->assertTrue($unknown->reportsCacheWrites);
        $this->assertFalse($unknown->measured(), 'Unknown cache writes must never read as zero.');
        $this->assertArrayHasKey('cacheWriteInputTokens', json_decode($unknown->json, true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame(40000, (new OutcomeInput(...[...$args, 40000]))->cacheWriteInputTokens);
        foreach ([40001, -1] as $invalid) {
            try { new OutcomeInput(...[...$args, $invalid]); $this->fail('Invalid cache writes accepted.'); }
            catch (InvalidArgumentException) {}
        }
    }

    public function testTerminalWireCarriesOptionalCacheWrites(): void
    {
        $wire = ExecutionFixtures::outcome();
        $wire->inputTokens = 10; $wire->cachedInputTokens = 2; $wire->cacheWriteInputTokens = 8;
        $outcome = (new TerminalInput($wire))->outcome;
        $this->assertTrue($outcome->reportsCacheWrites);
        $this->assertSame(8, $outcome->cacheWriteInputTokens);
        $wire->cacheWriteInputTokens = null;
        $this->assertFalse((new TerminalInput($wire))->outcome->measured());
        foreach (['8', 1.5, -1, true] as $invalid) {
            $wire->cacheWriteInputTokens = $invalid;
            try { new TerminalInput($wire); $this->fail('Invalid wire cache writes accepted.'); }
            catch (InvalidArgumentException) {}
        }
        $wire->cacheWriteInputTokens = 9;
        $this->expectException(InvalidArgumentException::class);
        new TerminalInput($wire);
    }
}
