<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Crypt;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureAutomation\Services\AutomationEventDispatcher;
use Espo\Modules\FeatureCredits\Enrichment\FoundResults;
use PHPUnit\Framework\TestCase;

class EnrichmentFoundResultsTest extends TestCase
{
    private Crypt $crypt;
    /** @var list<array> */
    private array $signals = [];

    protected function setUp(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('get')->willReturn('enrichment-test-encryption-key');
        $this->crypt = new Crypt($config);
        $this->signals = [];
    }

    public function testEachFoundValueOfAContactIsOneSignalWithoutTheValue(): void
    {
        $found = $this->found(listening: true);
        $found->collect($this->terms('Contact'), $this->attempt('att1', 'phone', ['+5511999990000', '', '+5511888880000']));
        $found->announce();

        $this->assertSame([
            ['enrichment.found', 'Contact', 'contact1', ['enrichment' => ['attemptId' => 'att1', 'product' => 'phone', 'provider' => 'apollo', 'index' => 0]]],
            ['enrichment.found', 'Contact', 'contact1', ['enrichment' => ['attemptId' => 'att1', 'product' => 'phone', 'provider' => 'apollo', 'index' => 2]]],
        ], $this->signals);
        $this->assertStringNotContainsString('99999', json_encode($this->signals));
    }

    public function testCompaniesAndOtherProductsAreNotAnnounced(): void
    {
        $found = $this->found(listening: true);
        $found->collect($this->terms('Account'), $this->attempt('att1', 'phone', ['+5511999990000']));
        $found->collect($this->terms('Contact'), $this->attempt('att2', 'socios', ['x']));
        $found->announce();

        $this->assertSame([], $this->signals);
    }

    public function testNothingIsDecryptedOrSentWhenNoAutomationListens(): void
    {
        $found = $this->found(listening: false);
        $found->collect($this->terms('Contact'), $this->attempt('att1', 'email', ['ana@example.com']));
        $found->announce();

        $this->assertSame([], $this->signals);
    }

    public function testRolledBackWorkIsForgotten(): void
    {
        $found = $this->found(listening: true);
        $found->collect($this->terms('Contact'), $this->attempt('att1', 'email', ['ana@example.com']));
        $found->reset();
        $found->announce();

        $this->assertSame([], $this->signals);
    }

    public function testAnnouncingTwiceSendsOnce(): void
    {
        $found = $this->found(listening: true);
        $found->collect($this->terms('Contact'), $this->attempt('att1', 'email', ['ana@example.com']));
        $found->announce();
        $found->announce();

        $this->assertCount(1, $this->signals);
    }

    public function testBrokenReceiptDoesNotStopTheOthers(): void
    {
        $found = $this->found(listening: true);
        $found->collect($this->terms('Contact'), ['id' => 'bad', 'product' => 'email', 'provider' => 'x', 'result_ciphertext' => 'not-encrypted']);
        $found->collect($this->terms('Contact'), $this->attempt('att2', 'email', ['ana@example.com']));
        $found->announce();

        $this->assertCount(1, $this->signals);
        $this->assertSame('att2', $this->signals[0][3]['enrichment']['attemptId']);
    }

    private function found(bool $listening): FoundResults
    {
        $dispatcher = $this->createMock(AutomationEventDispatcher::class);
        $dispatcher->method('hasSignalListeners')->with('enrichment.found')->willReturn($listening);
        $dispatcher->method('dispatchSignal')->willReturnCallback(function (...$args) {
            $this->signals[] = $args;
        });

        $factory = $this->createMock(InjectableFactory::class);
        $factory->method('create')->with(AutomationEventDispatcher::class)->willReturn($dispatcher);

        return new FoundResults($this->crypt, $factory, $this->createMock(Log::class));
    }

    private function terms(string $targetType): array
    {
        return ['request' => ['targetType' => $targetType, 'targetId' => 'contact1']];
    }

    private function attempt(string $id, string $product, array $values): array
    {
        return [
            'id' => $id,
            'product' => $product,
            'provider' => 'apollo',
            'result_ciphertext' => $this->crypt->encrypt(json_encode($values)),
        ];
    }
}
