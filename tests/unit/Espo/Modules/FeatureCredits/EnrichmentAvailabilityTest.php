<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\ServiceUnavailable;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Metadata;
use Espo\Modules\FeatureCredits\Enrichment\ProviderAvailability;
use Espo\Modules\FeatureCredits\Enrichment\QuoteProvider;
use PHPUnit\Framework\TestCase;

class EnrichmentEligibilityFixture implements QuoteProvider
{
    public ?string $value = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    public array $calls = [];

    public function revision(string $tenantId, string $targetType, string $product, array $identity): ?string
    {
        $this->calls[] = [$tenantId, $targetType, $product, $identity];
        return $this->value;
    }
}

class DiscoveryEligibilityFixture extends EnrichmentEligibilityFixture implements \Espo\Modules\FeatureCredits\Enrichment\DiscoverySupplier
{
    public function retentionSeconds(string $product): int { return 86400; }
    public function execute(array $request, int $timeoutMs): \Espo\Modules\FeatureCredits\Enrichment\DiscoveryResult
    {
        throw new \LogicException('Quote inspection must not dispatch.');
    }
}

class EnrichmentAvailabilityTest extends TestCase
{
    private function service(array $classes, EnrichmentEligibilityFixture $adapter): ProviderAvailability
    {
        $metadata = $this->createStub(Metadata::class);
        $metadata->method('get')->with(['app', 'enrichment', 'quoteProviderClassNameMap'])->willReturn($classes);
        $factory = $this->createMock(InjectableFactory::class);
        $factory->method('create')->with(EnrichmentEligibilityFixture::class)->willReturn($adapter);
        return new ProviderAvailability($metadata, $factory);
    }

    public function testUnregisteredProvidersAreUnavailableAndOffModeNeverConsultsBrazilianAdapters(): void
    {
        $adapter = new EnrichmentEligibilityFixture();
        $this->assertSame([], $this->service([], $adapter)->inspect('tenant', 'Contact', ['email'], []));
        $service = $this->service(['apollo' => EnrichmentEligibilityFixture::class, 'cpfcnpj' => 'invalid-disabled-class'], $adapter);
        $result = $service->inspect('tenant', 'Contact', ['email'], ['cpf' => '52998224725'], false);
        $this->assertSame(['apollo' => ['email' => $adapter->value]], $result);
        $this->assertCount(1, $adapter->calls);
    }

    public function testOwnIdentifierAndCompanyRestrictionsAreEnforcedBeforeAdapterConsultation(): void
    {
        $adapter = new EnrichmentEligibilityFixture();
        $service = $this->service(['cpfcnpj' => EnrichmentEligibilityFixture::class, 'hub' => EnrichmentEligibilityFixture::class], $adapter);
        $this->assertSame([], $service->inspect('tenant', 'Contact', ['email'], ['cnpj' => '04252011000110']));
        $this->assertSame([], $service->inspect('tenant', 'Account', ['phone'], ['cpf' => '52998224725']));
        $this->assertSame([], $service->inspect('tenant', 'Account', ['company'], ['cnpj' => '04252011000110']));
        $this->assertSame([], $adapter->calls);
        $this->assertCount(2, $service->inspect('tenant', 'Contact', ['email', 'phone'], ['cpf' => '52998224725']));
        $this->assertCount(4, $adapter->calls);
    }

    public function testUnavailableAndInvalidCapabilityRevisionsFailClosed(): void
    {
        $adapter = new EnrichmentEligibilityFixture();
        $service = $this->service(['apollo' => EnrichmentEligibilityFixture::class], $adapter);
        $adapter->value = null;
        $this->assertSame([], $service->inspect('tenant', 'Contact', ['email'], []));
        $adapter->value = 'secret-or-mutable-alias';
        $this->expectException(ServiceUnavailable::class);
        $service->inspect('tenant', 'Contact', ['email'], []);
    }

    public function testDiscoveryNeedsSeparateRegistrationAndOwnInputWithoutOffModeFallback(): void
    {
        $adapter = new DiscoveryEligibilityFixture();
        $classes = [];
        $metadata = $this->createStub(Metadata::class);
        $metadata->method('get')->willReturnCallback(static fn ($path) => $path[2] === 'discoveryQuoteProviderClassNameMap' ?
            $classes : ['cpfcnpj' => EnrichmentEligibilityFixture::class]);
        $factory = $this->createMock(InjectableFactory::class); $factory->expects($this->never())->method('create');
        $service = new ProviderAvailability($metadata, $factory);
        $this->assertSame([], $service->inspect('tenant', 'Account', ['socios'], ['cnpj' => '04252011000110'], false));

        $metadata = $this->createStub(Metadata::class);
        $metadata->method('get')->with(['app', 'enrichment', 'discoveryQuoteProviderClassNameMap'])->willReturn(
            array_fill_keys(['apollo', 'cpfcnpj', 'hub', 'registrobr'], DiscoveryEligibilityFixture::class));
        $factory = $this->createStub(InjectableFactory::class); $factory->method('create')->willReturn($adapter);
        $service = new ProviderAvailability($metadata, $factory);
        foreach ([['Contact', ['socios'], ['cnpj' => '04252011000110']], ['Account', ['socios'], ['cpf' => '52998224725']],
            ['Account', ['employees'], ['linkedinUrl' => 'https://linkedin.com/in/person']], ['Account', ['cnpj'], []]] as [$type, $products, $identity]) {
            $this->assertSame([], $service->inspect('tenant', $type, $products, $identity, false));
        }
        $this->assertSame([], $adapter->calls);
        $this->assertSame(['cpfcnpj' => ['socios' => $adapter->value]], $service->inspect('tenant', 'Account', ['socios'], ['cnpj' => '04252011000110'], false));
        $this->assertSame(['cpfcnpj', 'hub'], array_keys($service->inspect('tenant', 'Account', ['socios'], ['cnpj' => '04252011000110'], true)));
        $this->assertSame(['apollo'], array_keys($service->inspect('tenant', 'Account', ['employees'], ['linkedinUrl' => 'https://linkedin.com/company/example'], false)));
        $this->assertSame(['registrobr'], array_keys($service->inspect('tenant', 'Account', ['cnpj'], ['website' => 'https://example.com.br'], false)));
        $adapter->value = null;
        $this->assertSame([], $service->inspect('tenant', 'Account', ['cnpj'], ['website' => 'https://example.com.br'], false));
        $adapter->value = 'not-a-contract-revision';
        $this->expectException(ServiceUnavailable::class);
        $service->inspect('tenant', 'Account', ['cnpj'], ['website' => 'https://example.com.br'], false);
    }
}
