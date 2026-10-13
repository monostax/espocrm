<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\Conflict;
use Espo\Modules\FeatureCredits\Enrichment\{Catalog, Quotes};
use Espo\Modules\FeatureCredits\Enrichment\Providers\{RegistroBrDiscovery, Response, Transport};
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

trait EnrichmentDiscoveryAdmissionCases
{
    #[DataProvider('dialects')]
    public function testPublicDiscoveryAdmissionRevalidatesSelectedTermsAndReplaysClosedOperation(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Quotes $quotes, Catalog $catalog): void {
            $this->discoveryCatalog($pdo, $catalog);
            $reservations = $this->fundAdmission($pdo, '20');
            $quote = $quotes->quote($this->discoveryInput(['count' => 1]));
            try { $quotes->admit('tenant', $quote['id']); $this->fail('Unconfirmed discovery held funds.'); } catch (Conflict) {}
            $quotes->confirm('tenant', $quote['id']);
            $pdo->exec("UPDATE account SET cnpj='04252011000110'");
            try { $quotes->admit('tenant', $quote['id']); $this->fail('Changed company identity admitted.'); } catch (Conflict) {}
            $pdo->exec("UPDATE account SET cnpj='11222333000181'");
            $this->available = false;
            try { $quotes->admit('tenant', $quote['id']); $this->fail('Unavailable supplier admitted.'); } catch (Conflict) {}
            $this->available = true; $this->deniedWrites = ['Contact.cpf'];
            try { $quotes->admit('tenant', $quote['id']); $this->fail('Changed permissions admitted.'); } catch (Conflict) {}
            $this->deniedWrites = [];
            $receipt = $quotes->admit('tenant', $quote['id']);
            $this->assertSame('6.0000', $receipt['reservedCredits']);
            $reservations->release('tenant', $receipt['usageId'], 'enrichment:' . $quote['id']);
            $this->available = false;
            $this->assertSame('released', $quotes->admit('tenant', $quote['id'])['admission']);
            $this->assertSame('0.0000', $pdo->query('SELECT reserved_credits FROM tenant_credit_balance')->fetchColumn());
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testPublicDiscoveryAdmissionRacesUseSingleHoldAndSharedAiFunds(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Quotes $quotes, Catalog $catalog) use ($dialect): void {
            $this->discoveryCatalog($pdo, $catalog); $reservations = $this->fundAdmission($pdo, '10');
            $quote = $quotes->quote($this->discoveryInput(['count' => 1])); $quotes->confirm('tenant', $quote['id']);
            $payload = ['tenantId' => 'tenant', 'quoteId' => $quote['id']];
            $results = $this->race($pdo, $dialect, 'admit', [$payload, $payload]);
            $this->assertArrayHasKey('result', $results[0]); $this->assertSame($results[0], $results[1]);
            $reservations->release('tenant', $results[0]['result']['usageId'], 'enrichment:' . $quote['id']);
            $next = $quotes->quote($this->discoveryInput(['count' => 1, 'requestKey' => 'competing'])); $quotes->confirm('tenant', $next['id']);
            $results = $this->race($pdo, $dialect, 'admit', [['tenantId' => 'tenant', 'quoteId' => $next['id']], ['testOperation' => 'ai']]);
            $this->assertCount(1, array_column($results, 'result'));
            $this->assertSame([Conflict::class], array_column($results, 'error'));
            $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM credit_usage')->fetchColumn());
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testPublicRdapAdmissionAdapterPurchaseAndReplayOrUnqualifiedZeroCharge(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Quotes $quotes, Catalog $catalog): void {
            $this->discoveryCatalog($pdo, $catalog); $this->fundAdmission($pdo, '10');
            [$calls, , , , $execution] = $this->discoveryCalls($pdo);
            $transport = $this->createMock(Transport::class);
            $transport->expects($this->exactly(2))->method('send')->with('registrobr', 'GET', '/domain/example.com.br', [], [], 30000)->willReturnOnConsecutiveCalls(
                new Response(200, ['objectClassName' => 'domain', 'ldhName' => 'example.com.br', 'entities' => [
                    ['roles' => ['registrant'], 'publicIds' => [['type' => 'cnpj', 'identifier' => '05506560000136']]]]]),
                new Response(200, ['objectClassName' => 'domain', 'ldhName' => 'example.com.br', 'entities' => []]));
            $adapter = new RegistroBrDiscovery($transport);
            foreach ([false, true] as $refresh) {
                $quote = $quotes->quote($this->discoveryInput(['products' => ['cnpj'], 'requestKey' => $refresh ? 'fresh' : 'initial', 'refresh' => $refresh]));
                $quotes->confirm('tenant', $quote['id']); $quotes->admit('tenant', $quote['id']);
                $this->discoveryReplies[] = fn ($request) => $adapter->execute($request, $request['timeoutMs']);
                $receipt = $execution->run('tenant', $quote['id']);
                $this->assertSame($refresh ? '0.0000' : '2.0000', $receipt['settledCredits']);
                $this->assertSame($receipt, $execution->run('tenant', $quote['id']));
                $this->assertSame('settled', $quotes->admit('tenant', $quote['id'])['admission']);
            }
            $this->assertSame('8.0000', $pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame('0.0000', $pdo->query('SELECT reserved_credits FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM enrichment_purchase')->fetchColumn());
            $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM enrichment_discovery_call')->fetchColumn());
            $this->assertAdmissionAudit($pdo);
        });
    }
}
