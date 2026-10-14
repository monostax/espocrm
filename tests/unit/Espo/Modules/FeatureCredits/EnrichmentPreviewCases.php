<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Metadata;
use Espo\Modules\FeatureCredits\Enrichment\Catalog;
use Espo\Modules\FeatureCredits\Enrichment\ProviderAvailability;
use Espo\Modules\FeatureCredits\Enrichment\Providers\Apollo;
use Espo\Modules\FeatureCredits\Enrichment\Providers\CpfCnpj;
use Espo\Modules\FeatureCredits\Enrichment\Providers\Credentials;
use Espo\Modules\FeatureCredits\Enrichment\Providers\Hub;
use Espo\Modules\FeatureCredits\Enrichment\Providers\Transport;
use Espo\Modules\FeatureCredits\Enrichment\QuoteInput;
use Espo\Modules\FeatureCredits\Enrichment\Quotes;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

trait EnrichmentPreviewCases
{
    private function previewSuppliers(bool $configured = true): ProviderAvailability
    {
        $metadata = $this->createStub(Metadata::class);
        $metadata->method('get')->willReturn(['apollo' => Apollo::class, 'cpfcnpj' => CpfCnpj::class, 'hub' => Hub::class]);
        $credentials = $this->createStub(Credentials::class);
        $credentials->method('get')->willReturn($configured ? ['key' => 'test', 'token' => 'test', 'contract' => 'test'] : []);
        $transport = $this->createMock(Transport::class);
        $transport->expects($this->never())->method('send');
        $factory = $this->createStub(InjectableFactory::class);
        $factory->method('create')->willReturnCallback(static fn ($class) => new $class($credentials, $transport));
        return new ProviderAvailability($metadata, $factory);
    }

    private function integerCatalog(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/../../../../../custom/Espo/Modules/FeatureCredits/Resources/enrichment/integer-2026-10-14.json'), true, 32, JSON_THROW_ON_ERROR);
    }

    #[DataProvider('dialects')]
    public function testCpfPreviewQuotesWholeCreditsWithoutPurchasingAndPreservesAcceptedPrices(string $dialect): void
    {
        $this->providerAvailability = $this->previewSuppliers();
        $this->database($dialect, function (PDO $pdo, Quotes $quotes, Catalog $catalog): void {
            $old = $quotes->quote($this->input(['products' => ['email'], 'budget' => '3']));
            $old = $quotes->confirm('tenant', $old['id']);
            $this->assertSame('2.7000', $old['maximum']);
            $catalog->publish((object) $this->integerCatalog(), 'admin');
            $this->now = '2026-10-15 00:00:00';
            $params = ['tenantId' => 'tenant', 'targetType' => 'Contact', 'targetId' => 'contact'];
            $preview = $quotes->preview($params);
            $this->assertCount(3, $preview['actions']);
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM enrichment_quote')->fetchColumn());
            foreach ($preview['actions'] as $row) {
                $price = $row['action'] === 'combined' ? '6.0000' : '3.0000';
                $this->assertTrue($row['available']);
                $this->assertNull($row['reason']);
                $this->assertSame($price, $row['startingCredits']);
                $this->assertSame(['waterfall' => true, 'budget' => $price], $row['settings']);
                $quote = $quotes->quote(new QuoteInput((object) [...$params, ...$row['settings'],
                    'products' => $row['action'] === 'combined' ? ['email', 'phone'] : [$row['action']],
                    'requestKey' => $row['action']]));
                $this->assertSame($price, $quote['maximum']);
                foreach ($quote['routes'] as $route) {
                    $this->assertSame(['cpfcnpj'], array_column($route, 'provider'));
                    $this->assertSame(['3.0000'], array_column($route, 'credits'));
                }
                $this->assertTrue($quotes->confirm('tenant', $quote['id'])['confirmable']);
            }
            $this->assertSame($old, $quotes->confirm('tenant', $old['id']));
            foreach (['52998224725', 'cpfcnpj', 'hub', 'apollo', 'policyId'] as $private) {
                $this->assertStringNotContainsString($private, json_encode($preview));
            }
            foreach (['credit_usage', 'credit_reservation', 'credit_transaction'] as $table) {
                $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn());
            }
        });
    }

    #[DataProvider('dialects')]
    public function testPreviewRespectsReadableOwnIdentityCredentialsAndExistingLinkedInDefaults(string $dialect): void
    {
        $this->providerAvailability = $this->previewSuppliers();
        $this->database($dialect, function (PDO $pdo, Quotes $quotes, Catalog $catalog): void {
            $catalog->publish((object) $this->integerCatalog(), 'admin');
            $this->now = '2026-10-15 00:00:00';
            $params = ['tenantId' => 'tenant', 'targetType' => 'Contact', 'targetId' => 'contact'];
            $this->hiddenIdentityFields = ['Contact.cpf'];
            foreach ($quotes->preview($params)['actions'] as $row) {
                $this->assertFalse($row['available']);
                $this->assertSame('noRoute', $row['reason']);
                $this->assertNull($row['settings']);
            }
            $this->hiddenIdentityFields = [];
            $pdo->exec("UPDATE contact SET cpf='11111111111'");
            $this->assertSame([false, false, false], array_column($quotes->preview($params)['actions'], 'available'));
            $pdo->exec("UPDATE contact SET cpf='52998224725'");
            $this->providerAvailability = $this->previewSuppliers(false);
            [$unconfigured] = $this->services($pdo);
            $this->assertSame([false, false, false], array_column($unconfigured->preview($params)['actions'], 'available'));

            $pdo->exec("INSERT INTO contact_channel_identity (id, contact_id, tenant_id, channel_type, source_id)
                VALUES ('linkedin', 'contact', 'tenant', 'linkedin', 'https://linkedin.com/in/person')");
            $preview = $quotes->preview($params)['actions'];
            $this->assertSame(['1.0000', '8.0000', '9.0000'], array_column($preview, 'startingCredits'));
            foreach ($preview as $row) $this->assertSame(['waterfall' => false], $row['settings']);

            $account = $quotes->preview([...$params, 'targetType' => 'Account'])['actions'];
            $this->assertSame(['1.0000', '1.0000', '2.0000'], array_column(array_slice($account, 0, 3), 'startingCredits'));
            $this->assertSame('noRoute', $account[3]['reason']); // CNPJ cannot enable company/Apollo.
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM enrichment_quote')->fetchColumn());
        });
    }
}
