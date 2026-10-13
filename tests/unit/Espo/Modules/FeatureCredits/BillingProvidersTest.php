<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Utils\Config;
use Espo\Modules\FeatureCredits\Billing\{Providers, Routing, CatalogDefinition};
use Espo\Modules\FeatureCredits\Billing\Asaas\{Connection, Connections};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/BillingFixtures.php';

final class BillingProvidersTest extends TestCase
{
    public static function selection(): array
    {
        return ['integrationId' => 'test-asaas', 'environment' => 'sandbox', 'salesEnabled' => true, 'operations' => ['subscription']];
    }

    public static function invalidSelections(): iterable
    {
        foreach ([null, false, 'enabled', [], (object) self::selection()] as $index => $value) yield "shape $index" => [$value];
        foreach (array_keys(self::selection()) as $key) {
            $entry = self::selection(); unset($entry[$key]); yield "missing $key" => [$entry];
        }
        foreach ([false, 'true', 1, null] as $index => $value) yield "sales $index" => [array_replace(self::selection(), ['salesEnabled' => $value])];
        foreach (['', 'unsafe/id', 1, []] as $index => $value) yield "identity $index" => [array_replace(self::selection(), ['integrationId' => $value])];
        foreach (['live', null, [], true] as $index => $value) yield "environment $index" => [array_replace(self::selection(), ['environment' => $value])];
        foreach ([[], 'subscription', ['refund'], ['subscription', 'subscription'], [true], [['subscription']], ['op' => 'subscription']] as $index => $value) {
            yield "operations $index" => [array_replace(self::selection(), ['operations' => $value])];
        }
        yield 'client currencies cannot broaden adapter' => [self::selection() + ['currencies' => ['USD']]];
        yield 'unknown option' => [self::selection() + ['apiKey' => 'not-a-config-option']];
    }

    #[DataProvider('invalidSelections')]
    public function testInvalidOrDisabledSelectionNeverLoadsCredentials(mixed $selection): void
    {
        $config = $this->createStub(Config::class);
        $config->method('get')->with('featureCreditsBillingProviders')->willReturn(['asaas' => $selection]);
        $connections = $this->createMock(Connections::class); $connections->expects($this->never())->method('get');
        $this->assertNull((new Providers($config, $connections))->capability('asaas'));
    }

    public function testDeferredOrMissingRoutesNeverFallBackToAsaas(): void
    {
        $config = $this->createMock(Config::class); $config->expects($this->never())->method('get');
        $connections = $this->createMock(Connections::class); $connections->expects($this->never())->method('get');
        $providers = new Providers($config, $connections);
        foreach ([null, 'stripe', 'unknown', 'ASAAS'] as $provider) $this->assertNull($providers->capability($provider));
    }

    public function testRuntimeConnectionAndSelectionMustBothBeReadyAndEnvironmentExact(): void
    {
        $old = getenv('CREDIT_BILLING_PROVIDER_TEST_KEY');
        try {
            $selection = self::selection();
            $entry = ['environment' => 'sandbox', 'apiKeyEnv' => 'CREDIT_BILLING_PROVIDER_TEST_KEY', 'creationEnabled' => true];
            $config = $this->createStub(Config::class);
            $config->method('get')->willReturnCallback(static function ($key) use (&$selection, &$entry) {
                return match ($key) {
                    'featureCreditsBillingProviders' => ['asaas' => $selection],
                    'featureCreditsAsaasConnections' => ['test-asaas' => $entry],
                    default => null,
                };
            });
            $providers = new Providers($config, new Connections($config));
            putenv('CREDIT_BILLING_PROVIDER_TEST_KEY');
            $this->assertNull($providers->capability('asaas'));
            putenv('CREDIT_BILLING_PROVIDER_TEST_KEY=fixture-only-key');
            $capability = $providers->capability('asaas');
            $this->assertNotNull($capability);
            $this->assertSame('test-asaas', $capability->integrationId);
            $this->assertSame(['BRL'], $capability->currencies);
            $this->assertSame(['subscription'], $capability->operations);
            $catalog = new CatalogDefinition(BillingFixtures::definition());
            $this->assertTrue(Routing::checkout($catalog->offers['monthly-br'], 'BR', null, null, false, $capability)['eligible']);
            $this->assertSame('billing_operation_unsupported', Routing::checkout($catalog->offers['pack-br'], 'BR', null, null, false, $capability)['blockedReason']);
            $entry['creationEnabled'] = false;
            $this->assertNull($providers->capability('asaas'));
            $entry['creationEnabled'] = true; $entry['environment'] = 'production';
            $this->assertNull($providers->capability('asaas'));
            $selection['environment'] = 'production';
            $this->assertNotNull($providers->capability('asaas'));
            $selection['salesEnabled'] = false;
            $this->assertNull($providers->capability('asaas'));
            $selection['salesEnabled'] = true; $selection['integrationId'] = 'missing';
            $this->assertNull($providers->capability('asaas'));
        } finally {
            putenv($old === false ? 'CREDIT_BILLING_PROVIDER_TEST_KEY' : 'CREDIT_BILLING_PROVIDER_TEST_KEY=' . $old);
        }
    }

    public function testConnectionCannotSilentlySubstituteAnotherIntegration(): void
    {
        $config = $this->createStub(Config::class); $config->method('get')->willReturn(['asaas' => self::selection()]);
        $connections = $this->createStub(Connections::class);
        $connections->method('get')->willReturn(new Connection('another', 'sandbox', 'fixture-key', true));
        $this->assertNull((new Providers($config, $connections))->capability('asaas'));
    }
}
