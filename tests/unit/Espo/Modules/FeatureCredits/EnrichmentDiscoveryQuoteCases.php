<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\FeatureCredits\Controllers\CreditEnrichmentQuotes;
use Espo\Modules\FeatureCredits\Enrichment\Catalog;
use Espo\Modules\FeatureCredits\Enrichment\QuoteInput;
use Espo\Modules\FeatureCredits\Enrichment\Quotes;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

trait EnrichmentDiscoveryQuoteCases
{
    private function discoveryCatalog(PDO $pdo, Catalog $catalog): void
    {
        $catalog->publish((object) EnrichmentFixtures::discoveryDefinition(), 'admin');
        $this->now = '2026-10-13 00:00:00';
        $pdo->exec("UPDATE account SET linkedin_url='https://linkedin.com/company/example', website='https://example.com.br'");
    }

    private function discoveryInput(array $changes = []): QuoteInput
    {
        return new QuoteInput((object) [...['tenantId' => 'tenant', 'targetType' => 'Account', 'targetId' => 'contact',
            'products' => ['socios'], 'requestKey' => 'finder'], ...$changes]);
    }

    #[DataProvider('dialects')]
    public function testDiscoveryApiFreezesSeparateProductsAndAdmitsWithoutLeakingInputs(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Quotes $quotes, Catalog $catalog): void {
            $this->discoveryCatalog($pdo, $catalog);
            $this->fundAdmission($pdo, '100');
            $controller = new CreditEnrichmentQuotes($quotes);
            foreach (['socios' => '60.0000', 'employees' => '20.0000', 'cnpj' => '2.0000'] as $product => $maximum) {
                $input = $this->discoveryInput(['products' => [$product], 'requestKey' => $product]);
                $request = $this->createStub(Request::class);
                $request->method('getBodyContents')->willReturn(json_encode($input->data, JSON_THROW_ON_ERROR));
                $receipt = (array) $controller->postActionQuote($request, $this->createStub(Response::class));
                $this->assertSame($maximum, $receipt['maximum']);
                $this->assertSame($receipt, $quotes->quote($input));
                $this->assertSame([$product], array_keys($receipt['routes']));
                $this->assertTrue($receipt['confirmable']);
                $this->assertTrue($receipt['discovery']['admissionSupported']);
                $this->assertTrue($receipt['discovery']['explicitApplicationRequired']);
                $public = json_encode($receipt, JSON_THROW_ON_ERROR);
                foreach (['11222333000181', 'linkedin.com', 'example.com.br', 'person@example.test', 'snapshot', '"identity"', $this->revision] as $hidden) {
                    $this->assertStringNotContainsString($hidden, $public);
                }
                $q = $pdo->prepare('SELECT snapshot FROM enrichment_quote WHERE id=?'); $q->execute([$receipt['id']]);
                $snapshot = json_decode($q->fetchColumn(), true, 32, JSON_THROW_ON_ERROR);
                $source = match ($product) { 'socios' => 'cnpj', 'employees' => 'linkedinUrl', 'cnpj' => 'website' };
                $this->assertSame([$source], array_keys($snapshot['identity']));
                $confirmed = $quotes->confirm('tenant', $receipt['id']);
                $this->assertSame($confirmed, $quotes->confirm('tenant', $receipt['id']));
                $this->assertSame('not_admitted', $confirmed['admission']);
                if ($product === 'cnpj') {
                    $this->assertSame('Domain registrant CNPJ', $confirmed['discovery']['label']);
                    $this->assertArrayNotHasKey('count', $confirmed);
                } else {
                    $this->assertSame(10, $confirmed['count']);
                    $this->assertSame([], $confirmed['filters']);
                    $this->assertSame($product === 'socios', $confirmed['permissionsAtQuote']['candidateRead']['cpf']);
                }
                $admitted = $quotes->admit('tenant', $receipt['id']);
                $this->assertSame('admitted', $admitted['admission']);
                $this->assertSame($maximum, $admitted['reservedCredits']);
                $this->assertSame($admitted, $quotes->admit('tenant', $receipt['id']));
                try { $this->executor($pdo)->claim('tenant', $receipt['id']); $this->fail('Discovery used field-only dispatch.'); }
                catch (Conflict $e) { $this->assertSame('Discovery requires its own incremental executor.', $e->getMessage()); }
            }
            $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM credit_usage')->fetchColumn());
            $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM credit_reservation')->fetchColumn());
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM enrichment_discovery_call')->fetchColumn());
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM enrichment_attempt')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testDiscoveryConfirmationFencesIdentityCapabilitiesPermissionsAndPrices(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Quotes $quotes, Catalog $catalog): void {
            $this->discoveryCatalog($pdo, $catalog);
            $quote = $quotes->quote($this->discoveryInput());
            $pdo->exec("UPDATE account SET cnpj='04252011000110'");
            try { $quotes->confirm('tenant', $quote['id']); $this->fail('Changed company identity confirmed.'); } catch (Conflict) {}
            $pdo->exec("UPDATE account SET cnpj='11222333000181'");
            $this->revision = str_repeat('b', 64);
            try { $quotes->confirm('tenant', $quote['id']); $this->fail('Changed supplier terms confirmed.'); } catch (Conflict) {}
            $this->revision = str_repeat('a', 64);
            $this->hiddenIdentityFields = ['Contact.cpf'];
            try { $quotes->confirm('tenant', $quote['id']); $this->fail('Changed CPF disclosure confirmed.'); } catch (Conflict) {}
            $this->hiddenIdentityFields = [];
            $this->deniedWrites = ['Contact.accounts'];
            try { $quotes->confirm('tenant', $quote['id']); $this->fail('Changed application disclosure confirmed.'); } catch (Conflict) {}
            $this->deniedWrites = [];
            $confirmed = $quotes->confirm('tenant', $quote['id']);
            $unconfirmed = $quotes->quote($this->discoveryInput(['requestKey' => 'stale-price']));
            $next = EnrichmentFixtures::discoveryDefinition('discovery-v3', '2026-10-14 00:00:00');
            $next['rates'][14]['credits'] = '7';
            $catalog->publish((object) $next, 'admin'); $this->now = '2026-10-14 00:00:00';
            $this->assertSame($confirmed, $quotes->confirm('tenant', $quote['id']));
            try { $quotes->confirm('tenant', $unconfirmed['id']); $this->fail('Old price confirmed after cutover.'); } catch (Conflict) {}
            $new = $quotes->quote($this->discoveryInput(['requestKey' => 'current-price']));
            $this->assertSame('70.0000', $new['maximum']);
            $this->assertNotSame($new['policyId'], $confirmed['policyId']);
        });
    }

    #[DataProvider('dialects')]
    public function testDiscoveryReadOnlyReviewAndHumanApplicationRestrictionsAreDisclosed(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Quotes $quotes, Catalog $catalog): void {
            $this->discoveryCatalog($pdo, $catalog);
            $this->apiActor = true;
            $this->hiddenIdentityFields = ['Contact.cpf', 'ContactChannelIdentity.sourceId'];
            $this->deniedScopes = ['Contact.create', 'Contact.edit'];
            $this->deniedWrites = ['Contact.cpf', 'Contact.accounts', 'Contact.firstName'];
            $quote = $quotes->quote($this->discoveryInput());
            $this->assertTrue($quote['confirmable']);
            $permissions = $quote['permissionsAtQuote'];
            $this->assertSame(['cpf' => false, 'linkedinUrl' => false], $permissions['candidateRead']);
            foreach (['actorIsHuman', 'contactCreateScopeAllowed', 'contactEditScopeAllowed', 'contactNameWriteAllowed', 'accountLinkWriteAllowed', 'cpfWriteAllowed'] as $permission) {
                $this->assertFalse($permissions['application'][$permission]);
            }
            $this->assertTrue($permissions['application']['humanRequired']);
            $this->assertSame('terms_confirmed', $quotes->confirm('tenant', $quote['id'])['state']);
            $this->hiddenIdentityFields = ['Contact.name'];
            try { $quotes->quote($this->discoveryInput(['requestKey' => 'denied-name'])); $this->fail('Hidden candidate name allowed.'); } catch (Forbidden) {}
            $this->hiddenIdentityFields = ['Account.cnpj'];
            try { $quotes->quote($this->discoveryInput(['requestKey' => 'denied-company'])); $this->fail('Unreadable company input allowed.'); } catch (Forbidden) {}
            $this->hiddenIdentityFields = [];
            $this->deniedScopes = ['Contact.read'];
            try { $quotes->quote($this->discoveryInput(['requestKey' => 'denied-contact'])); $this->fail('Contact read scope bypassed.'); } catch (Forbidden) {}
            $this->deniedScopes = [];
            $this->actor = 'another-user';
            try { $quotes->confirm('tenant', $quote['id']); $this->fail('Actor quote isolation bypassed.'); } catch (NotFound) {}
            $this->actor = 'user';
            try { $quotes->confirm('other', $quote['id']); $this->fail('Tenant quote isolation bypassed.'); } catch (NotFound) {}
        });
    }

    #[DataProvider('dialects')]
    public function testFinderCountFiltersRefreshAndRouteAreBoundToTheRequest(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Quotes $quotes, Catalog $catalog): void {
            $this->discoveryCatalog($pdo, $catalog);
            $input = ['products' => ['employees'], 'count' => 3, 'filters' => (object) ['titles' => ['CEO', 'CTO']]];
            $quote = $quotes->quote($this->discoveryInput($input));
            $this->assertSame('6.0000', $quote['maximum']);
            $this->assertSame(3, $quote['count']);
            $this->assertSame(['titles' => ['CEO', 'CTO']], $quote['filters']);
            foreach (['count' => 4, 'filters' => ['titles' => ['CFO']], 'refresh' => true, 'budget' => '5'] as $key => $value) {
                try { $quotes->quote($this->discoveryInput([...$input, $key => $value])); $this->fail('Finder request mutation accepted.'); } catch (Conflict) {}
            }
            $this->assertSame($quote, $quotes->quote($this->discoveryInput([...$input, 'filters' => ['titles' => [' CTO ', 'CEO', 'CTO']]])));
            $this->unavailableDiscoveryProviders = ['cpfcnpj'];
            $off = $quotes->quote($this->discoveryInput(['requestKey' => 'off']));
            $this->assertFalse($off['confirmable']);
            $this->assertSame(['cpfcnpj' => 'capability_unavailable'], $off['discovery']['unavailableProviders']);
            try { $quotes->confirm('tenant', $off['id']); $this->fail('Unavailable default silently replaced.'); } catch (Conflict) {}
            $on = $quotes->quote($this->discoveryInput(['requestKey' => 'on', 'waterfall' => true, 'budget' => '120']));
            $this->assertSame(['hub'], array_column($on['routes']['socios'], 'provider'));
            $this->assertSame('60.0000', $on['maximum']);
            $this->unavailableDiscoveryProviders = [];
            try { $quotes->confirm('tenant', $on['id']); $this->fail('Changed eligible route silently confirmed.'); } catch (Conflict) {}
        });
    }

    #[DataProvider('dialects')]
    public function testDiscoveryIndependentWorkersReplayAndFenceOwnCompanyInputChanges(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Quotes $quotes, Catalog $catalog) use ($dialect): void {
            $this->discoveryCatalog($pdo, $catalog);
            foreach (['socios' => "cnpj='04252011000110'", 'employees' => "linkedin_url='https://linkedin.com/company/changed'",
                'cnpj' => "website='https://changed.com.br'"] as $product => $mutation) {
                $input = $this->discoveryInput(['products' => [$product], 'requestKey' => $product]);
                $results = $this->race($pdo, $dialect, 'quote', [$input->data, $input->data]);
                $this->assertArrayHasKey('result', $results[0]);
                $this->assertSame($results[0], $results[1]);
                $this->assertSame($results[0]['result'], $quotes->quote($input));
                $confirm = ['tenantId' => 'tenant', 'quoteId' => $results[0]['result']['id']];
                $confirmed = $this->race($pdo, $dialect, 'confirm', [$confirm, $confirm]);
                $this->assertSame('terms_confirmed', $confirmed[0]['result']['state']);
                $this->assertSame($confirmed[0], $confirmed[1]);
                $stale = $quotes->quote($this->discoveryInput(['products' => [$product], 'requestKey' => $product . '-stale']));
                $confirm['quoteId'] = $stale['id'];
                $results = $this->race($pdo, $dialect, 'confirm', [$confirm, $confirm], 'UPDATE account SET ' . $mutation);
                $this->assertSame([['error' => Conflict::class], ['error' => Conflict::class]], $results);
            }
            $this->assertSame(6, (int) $pdo->query('SELECT COUNT(*) FROM enrichment_quote')->fetchColumn());
            $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM enrichment_quote WHERE confirmed_at IS NOT NULL')->fetchColumn());
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM credit_reservation')->fetchColumn());
        });
    }
}
