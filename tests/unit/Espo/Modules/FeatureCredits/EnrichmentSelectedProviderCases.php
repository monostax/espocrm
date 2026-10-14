<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\Conflict;
use Espo\Modules\FeatureCredits\Enrichment\{Catalog,CustomerProjection,QuoteInput,Quotes};
use Espo\Modules\FeatureCredits\Enrichment\Providers\Result;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

trait EnrichmentSelectedProviderCases
{
    private function selectedInput(array $changes = []): QuoteInput
    {
        return $this->input(['waterfall' => false, 'budget' => null, 'sources' => [2, 3], ...$changes]);
    }

    public function testSelectedSourcesRejectEmptyUnknownDuplicateAndMixedPricingModes(): void
    {
        foreach ([[], [4], ['2'], [2, 2], ['key' => 2], null] as $sources) {
            try { $this->selectedInput(['sources' => $sources]); $this->fail('Invalid selected sources accepted.'); }
            catch (InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        foreach ([['waterfall' => true], ['budget' => '1'], ['targetType' => 'Account', 'products' => ['socios']]] as $input) {
            try { $this->selectedInput($input); $this->fail('Mixed pricing/execution modes accepted.'); }
            catch (InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $this->assertSame([1, 2, 3], $this->selectedInput(['sources' => [3, 1, 2]])->data['sources']);
        $this->assertArrayNotHasKey('sources', $this->input()->data, 'Legacy accepted request hashes must stay unchanged.');
    }

    #[DataProvider('dialects')]
    public function testSelectedPreviewExposesEligiblePricesAndSelectionIsBoundToQuote(string $dialect): void
    {
        $this->providerAvailability = $this->previewSuppliers();
        $this->database($dialect, function (PDO $pdo, Quotes $quotes, Catalog $catalog): void {
            $catalog->publish((object) $this->integerCatalog(), 'admin');
            $this->now = '2026-10-15 00:00:00';
            $params = ['tenantId' => 'tenant', 'targetType' => 'Contact', 'targetId' => 'contact'];
            $preview = $quotes->preview($params)['actions'][2];
            $this->assertSame([
                ['source' => 2, 'prices' => ['email' => '4.0000', 'phone' => '4.0000'], 'selected' => true],
                ['source' => 3, 'prices' => ['email' => '10.0000', 'phone' => '10.0000'], 'selected' => false],
            ], $preview['providers']);
            try { $quotes->quote($this->selectedInput(['sources' => [1]])); $this->fail('Ineligible Apollo selected.'); }
            catch (Conflict) {}
            $input = $this->selectedInput();
            $quote = $quotes->quote($input);
            $this->assertSame('28.0000', $quote['maximum']);
            $this->assertSame($quote['maximum'], $quote['budget']);
            $public = CustomerProjection::quote($quote);
            $this->assertSame([2, 3], $public['sources']);
            $this->assertSame([2, 3], array_column($public['routes']['email'], 'source'));
            foreach (['cpfcnpj', 'hub', 'apollo', '52998224725', 'policyId'] as $private) {
                $this->assertStringNotContainsString($private, json_encode($public));
            }
            $this->assertSame($quote, $quotes->quote($this->selectedInput(['sources' => [3, 2]])));
            try { $quotes->quote($this->selectedInput(['sources' => [3]])); $this->fail('Retry changed selection.'); }
            catch (Conflict) {}
            $single = CustomerProjection::quote($quotes->quote($this->selectedInput(['sources' => [3], 'requestKey' => 'single'])));
            $this->assertSame([3], array_column($single['routes']['phone'], 'source'), 'Source IDs must not change when others are unchecked.');
            $pdo->exec("INSERT INTO contact_channel_identity (id, contact_id, tenant_id, channel_type, source_id)
                VALUES ('linkedin', 'contact', 'tenant', 'linkedin', 'https://linkedin.com/in/person')");
            $actions = $quotes->preview($params)['actions'];
            foreach ([['email', ['email'], '5.0000'], ['phone', ['phone'], '12.0000'], ['combined', ['email', 'phone'], '17.0000']] as [$action, $products, $maximum]) {
                $options = array_values(array_filter($actions, static fn ($row) => $row['action'] === $action))[0]['providers'];
                foreach ($options as $option) $this->assertSame($products, array_keys($option['prices']));
                $scoped = $quotes->quote($this->selectedInput(['sources' => [1, 2], 'products' => $products, 'requestKey' => $action]));
                $this->assertSame($maximum, $scoped['maximum']);
                $this->assertSame($products, array_keys($scoped['routes']));
            }
            $this->hiddenIdentityFields = ['Contact.cpf'];
            try { $quotes->confirm('tenant', $quote['id']); $this->fail('Unavailable selected sources confirmed.'); }
            catch (Conflict) {}
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM credit_usage')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testSelectedProvidersAllRunAfterMatchesAndChargeOnlySuccessfulProductsOnce(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Quotes $quotes, Catalog $catalog): void {
            $catalog->publish((object) $this->integerCatalog(), 'admin');
            $this->now = '2026-10-15 00:00:00';
            $this->fundAdmission($pdo, '100');
            $quote = $quotes->quote($this->selectedInput(['sources' => [1, 2, 3], 'refresh' => true]));
            $this->assertSame('37.0000', $quote['maximum']);
            $quotes->confirm('tenant', $quote['id']);
            $this->assertSame('37.0000', $quotes->admit('tenant', $quote['id'])['reservedCredits']);
            $execution = $this->executor($pdo);
            $this->supplierResults = [new Result('match', ['same@example.test']),
                new Result('match', ['same@example.test', 'second@example.test']), new Result('match', ['third@example.test']),
                new Result('match', ['+5511999998888']), new Result('no_match'), new Result('ambiguous')];
            $execution->run('tenant', $quote['id']);
            $this->assertSame([['apollo','email','lookup'], ['cpfcnpj','email','lookup'], ['hub','email','lookup'],
                ['apollo','phone','lookup'], ['cpfcnpj','phone','lookup'], ['hub','phone','lookup']], $this->supplierCalls);
            $this->assertExecuted($pdo, '23.0000');
            $this->assertSame(4, (int) $pdo->query('SELECT COUNT(*) FROM enrichment_attempt WHERE purchased_at IS NOT NULL')->fetchColumn());
            [,,,,,,$history,$review] = $this->purchaseServices($pdo);
            $list = $history->read(['tenantId' => 'tenant', 'targetType' => 'Contact', 'targetId' => 'contact', 'product' => 'email'])['list'];
            $this->assertCount(3, $list);
            $sources = array_column(array_column($list, 'receipt'), 'source'); sort($sources);
            $this->assertSame([1, 2, 3], $sources);
            foreach ($list as $item) $this->assertNotEmpty((array) $review->read('tenant', $item['receipt']['id'])['values']);
            $execution->run('tenant', $quote['id']);
            $this->assertCount(6, $this->supplierCalls);
            $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type='debit'")->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testSelectedProvidersContinueThroughSiblingCacheAndFreeReuse(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Quotes $quotes, Catalog $catalog): void {
            $catalog->publish((object) $this->integerCatalog(), 'admin');
            $this->now = '2026-10-15 00:00:00';
            $this->fundAdmission($pdo, '100');
            $quote = $quotes->quote($this->selectedInput());
            $quotes->confirm('tenant', $quote['id']); $quotes->admit('tenant', $quote['id']);
            $execution = $this->executor($pdo);
            $this->supplierResults = [new Result('match', ['one@example.test'], hidden: ['phone' => ['+5511999998888']]),
                new Result('match', ['two@example.test'], hidden: ['phone' => ['+5511999997777']])];
            $execution->run('tenant', $quote['id']);
            $this->assertExecuted($pdo, '28.0000');
            $this->assertSame([['cpfcnpj','email','lookup'], ['hub','email','lookup']], $this->supplierCalls);
            $this->assertSame(4, (int) $pdo->query('SELECT COUNT(*) FROM enrichment_attempt WHERE purchased_at IS NOT NULL')->fetchColumn());
            $repeat = $quotes->quote($this->selectedInput(['products' => ['phone'], 'requestKey' => 'reuse']));
            $quotes->confirm('tenant', $repeat['id']); $quotes->admit('tenant', $repeat['id']);
            $execution->run('tenant', $repeat['id']);
            $this->assertCount(2, $this->supplierCalls);
            $this->assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM enrichment_attempt WHERE state='reused'")->fetchColumn());
            $this->assertSame('72.0000', $pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame('0.0000', $pdo->query('SELECT reserved_credits FROM tenant_credit_balance')->fetchColumn());
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testSelectedProviderTimeoutDoesNotCancelOtherSelectedProviderOrPermitLateCharge(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Quotes $quotes, Catalog $catalog): void {
            $catalog->publish((object) $this->integerCatalog(), 'admin');
            $this->now = '2026-10-15 00:00:00';
            $this->fundAdmission($pdo, '100');
            $quote = $quotes->quote($this->selectedInput(['products' => ['email']]));
            $quotes->confirm('tenant', $quote['id']); $quotes->admit('tenant', $quote['id']);
            $execution = $this->executor($pdo);
            $claim = $execution->claim('tenant', $quote['id']);
            $this->assertSame('cpfcnpj', $claim['provider']);
            $this->assertNull($execution->claim('tenant', $quote['id']));
            $this->now = '2026-10-15 00:00:31';
            $this->supplierResults = [new Result('match', ['hub@example.test'])];
            $execution->run('tenant', $quote['id']);
            $this->assertSame([['hub','email','lookup']], $this->supplierCalls);
            $execution->record('tenant', $quote['id'], $claim['id'], $claim['token'], new Result('match', ['late@example.test']));
            $this->assertExecuted($pdo, '10.0000');
        });
    }
}
