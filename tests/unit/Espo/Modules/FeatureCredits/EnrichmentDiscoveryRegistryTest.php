<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\ServiceUnavailable;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Metadata;
use Espo\Modules\FeatureCredits\Enrichment\{DiscoverySupplier,DiscoverySuppliers,QuoteProvider};
use PHPUnit\Framework\TestCase;

class EnrichmentDiscoveryRegistryTest extends TestCase
{
    public function testAbsentRegistryAndQuoteOnlyAdaptersCannotExecuteDiscovery(): void
    {
        $quoteOnly = $this->createStub(QuoteProvider::class);
        foreach ([null,get_class($quoteOnly),'invalid-class'] as $entry) {
            $metadata = $this->createStub(Metadata::class); $metadata->method('get')->willReturn($entry);
            $factory = $this->createMock(InjectableFactory::class); $factory->expects($this->never())->method('create');
            try { (new DiscoverySuppliers($metadata,$factory))->get('apollo'); $this->fail('Unregistered discovery executor activated.'); }
            catch (ServiceUnavailable) { $this->addToAssertionCount(1); }
        }
    }

    public function testOnlySeparateRegisteredDiscoveryContractResolves(): void
    {
        $supplier = $this->createStub(DiscoverySupplier::class);
        $metadata = $this->createMock(Metadata::class);
        $metadata->expects($this->once())->method('get')->with(['app','enrichment','discoveryQuoteProviderClassNameMap','cpfcnpj'])->willReturn(get_class($supplier));
        $factory = $this->createStub(InjectableFactory::class); $factory->method('create')->willReturn($supplier);
        $registry = new DiscoverySuppliers($metadata,$factory);
        $this->assertSame($supplier,$registry->get('cpfcnpj'));
        $this->expectException(ServiceUnavailable::class); $registry->get('unapproved');
    }
}
