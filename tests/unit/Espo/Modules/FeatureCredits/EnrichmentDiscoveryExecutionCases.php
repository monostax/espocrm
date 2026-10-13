<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Job\Job\Data;
use Espo\Modules\FeatureCredits\Enrichment\{Catalog,DiscoveryResult,ExecutionRouter,Quotes};
use Espo\Modules\FeatureCredits\Jobs\RunEnrichment;
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

trait EnrichmentDiscoveryExecutionCases
{
    #[DataProvider('dialects')]
    public function testDifferentEmployeeFiltersDoNotRepurchaseTheSameAccountCandidate(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'30');
            [,,,,$execution] = $this->discoveryCalls($pdo);
            $employee = static fn ($key) => ['candidateKey' => hash('sha256',$key),'naturalPerson' => true,
                'values' => ['name' => 'Person ' . $key,'currentEmployment' => true,'linkedinUrl' => 'https://linkedin.com/in/' . $key]];
            [$first] = $this->fundedDiscovery($pdo,$quotes,$reservations,['products' => ['employees'],'count' => 1,'filters' => ['titles' => ['CEO']]]);
            $this->discoveryReplies = [new DiscoveryResult('succeeded',[$employee('original')])];
            $execution->run('tenant',$first['id']);
            [$second] = $this->fundedDiscovery($pdo,$quotes,$reservations,['products' => ['employees'],'count' => 1,
                'requestKey' => 'other-filter','filters' => ['titles' => ['Founder']]]);
            $this->discoveryReplies = [new DiscoveryResult('succeeded',[$employee('original'),$employee('new')])];
            $result = $execution->run('tenant',$second['id']);
            $this->assertSame('2.0000',$result['settledCredits']); $this->assertCount(2,$this->discoveryRequests);
            $keys = $pdo->query('SELECT c.candidate_key FROM enrichment_purchase p JOIN enrichment_candidate_cache c ON c.id=p.candidate_cache_id')->fetchAll(PDO::FETCH_COLUMN);
            $this->assertEqualsCanonicalizing([hash('sha256','original'),hash('sha256','new')],$keys);
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testDiscoveryJobFortyResultsTenThenTwentyPurchasesUsesOneSupplierRequest(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'1000');
            [$calls,$purchases,,,$execution] = $this->discoveryCalls($pdo);
            $items = []; for ($i = 0; $i < 40; $i++) $items[] = $this->discoveryPerson("person-$i");
            $this->discoveryReplies = [new DiscoveryResult('succeeded',$items)];
            [$first] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 10]);
            $manager = $this->createStub(EntityManager::class); $manager->method('getPDO')->willReturn($pdo);
            $router = new ExecutionRouter($manager,$this->executor($pdo),$execution);
            $job = new RunEnrichment($router);
            $job->run(Data::create(['tenantId' => 'tenant','quoteId' => $first['id']]));
            $this->assertSame('60.0000',$purchases->close('tenant',$first['id'])['settledCredits']);
            $this->assertSame('complete',$purchases->close('tenant',$first['id'])['stopReason']);
            $progress = $quotes->confirm('tenant',$first['id'])['executionProducts']['socios'];
            $this->assertSame('complete',$progress['state']); $this->assertSame(10,$progress['candidateCount']);
            $this->assertSame(10,$progress['purchaseCount']); $this->assertCount(1,$progress['calls']);
            foreach (['person-','hidden@','11222333000181','Ciphertext','cursor','token'] as $private) {
                $this->assertStringNotContainsString($private,json_encode($progress));
            }
            $job->run(Data::create(['tenantId' => 'tenant','quoteId' => $first['id']]));
            $next = EnrichmentFixtures::discoveryDefinition('execution-cache-price','2026-10-13 00:01:00');
            $next['rates'][14]['credits'] = '7'; $catalog->publish((object) $next,'admin');
            $this->now = '2026-10-13 00:01:00';
            [$second] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 20,'requestKey' => 'twenty']);
            $receipt = $execution->run('tenant',$second['id']);
            $this->assertSame('140.0000',$receipt['settledCredits']); $this->assertSame('complete',$receipt['stopReason']);
            $this->assertSame($receipt,$execution->run('tenant',$second['id']));
            $this->assertCount(1,$this->discoveryRequests); $this->assertSame([],$this->supplierCalls);
            $this->assertSame(40,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_candidate_cache')->fetchColumn());
            $this->assertSame(30,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_purchase')->fetchColumn());
            $this->assertSame(1,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_discovery_call WHERE dispatched_at IS NOT NULL')->fetchColumn());
            $this->assertSame(['2026-10-13 00:00:00'],$pdo->query('SELECT DISTINCT acquired_at FROM enrichment_purchase')->fetchAll(PDO::FETCH_COLUMN));
            $this->assertSame('800.0000',$pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame('0.0000',$pdo->query('SELECT reserved_credits FROM tenant_credit_balance')->fetchColumn());
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testDiscoveryTraversalBuysDistinctCandidatesBeforeUsefulWaterfallSupplement(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'30');
            [,,,,$execution] = $this->discoveryCalls($pdo);
            [$quote] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 2,'waterfall' => true,'budget' => '24']);
            $this->discoveryReplies = [
                new DiscoveryResult('succeeded',[$this->discoveryPerson('one',['cpf' => '52998224725'])]),
                new DiscoveryResult('succeeded',[$this->discoveryPerson('one-hub',['cpf' => '52998224725','ownershipPercentage' => '25']),
                    $this->discoveryPerson('two',['cpf' => '11144477735'])]),
            ];
            $receipt = $execution->run('tenant',$quote['id']);
            $this->assertSame('18.0000',$receipt['settledCredits']); $this->assertSame('complete',$receipt['stopReason']);
            $this->assertSame(['cpfcnpj','hub'],array_map(static fn ($r) => $r[0]['provider'],$this->discoveryRequests));
            $this->assertSame(2,(int) $pdo->query("SELECT COUNT(*) FROM enrichment_purchase WHERE purchase_kind='candidate'")->fetchColumn());
            $this->assertSame(1,(int) $pdo->query("SELECT COUNT(*) FROM enrichment_purchase WHERE purchase_kind='supplement'")->fetchColumn());
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testDiscoveryPageBoundAndAmbiguousFailureReleaseRemainderWithoutRefundingPurchases(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'30');
            [,,,,$execution] = $this->discoveryCalls($pdo);
            [$bounded] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 2]);
            for ($i = 1; $i <= 5; $i++) $this->discoveryReplies[] = new DiscoveryResult('succeeded',[],'cursor-' . $i);
            $result = $execution->run('tenant',$bounded['id']);
            $this->assertSame('page_limit',$result['stopReason']); $this->assertSame('0.0000',$result['settledCredits']);
            $this->assertCount(5,$this->discoveryRequests);
            [$partial] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 2,'refresh' => true,'requestKey' => 'partial']);
            $this->discoveryReplies = [new DiscoveryResult('succeeded',[$this->discoveryPerson('kept')],'next'),new \RuntimeException('private supplier failure')];
            $result = $execution->run('tenant',$partial['id']);
            $this->assertSame('ambiguous',$result['stopReason']); $this->assertSame('6.0000',$result['settledCredits']);
            $this->assertSame('24.0000',$pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame('0.0000',$pdo->query('SELECT reserved_credits FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame($result,$execution->run('tenant',$partial['id'])); $this->assertCount(7,$this->discoveryRequests);
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testDiscoveryEmployeeCompletionLimitAndSupplierResultOrder(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'30');
            [,,,,$execution] = $this->discoveryCalls($pdo);
            [$quote] = $this->fundedDiscovery($pdo,$quotes,$reservations,['products' => ['employees'],'count' => 2]);
            $this->discoveryReplies = [new DiscoveryResult('succeeded',[
                ['candidateKey' => hash('sha256','first'),'completionToken' => 'opaque-first'],
                ['candidateKey' => hash('sha256','failed'),'completionToken' => 'opaque-failed'],
                ['candidateKey' => hash('sha256','excess'),'completionToken' => 'opaque-excess'],
            ]),new DiscoveryResult('succeeded',[
                ['candidateKey' => hash('sha256','first'),'values' => ['name' => 'Person','currentEmployment' => true],'naturalPerson' => true],
            ]),new DiscoveryResult('failed')];
            $result = $execution->run('tenant',$quote['id']);
            $this->assertSame('completion_limit',$result['stopReason']); $this->assertSame('2.0000',$result['settledCredits']);
            $this->assertCount(3,$this->discoveryRequests);
            $this->assertSame('opaque-first',$this->discoveryRequests[1][0]['input']['completionToken']);
            $this->assertSame('opaque-failed',$this->discoveryRequests[2][0]['input']['completionToken']);
            $this->assertSame('0.0000',$pdo->query('SELECT reserved_credits FROM tenant_credit_balance')->fetchColumn());
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testDiscoveryRestartAndScheduledRecoveryFenceLostDispatchAndRevokedActors(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'30');
            [$calls,,,,$execution] = $this->discoveryCalls($pdo);
            [$quote] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 1]);
            $claim = $calls->page('tenant',$quote['id'],'cpfcnpj',1);
            $calls->dispatch('tenant',$quote['id'],$claim['id'],$claim['token']);
            $this->assertSame(['quoteId' => $quote['id'],'state' => 'pending'],$execution->run('tenant',$quote['id']));
            $this->now = '2026-10-13 00:00:30';
            $result = $execution->run('tenant',$quote['id']);
            $this->assertSame('ambiguous',$result['stopReason']); $this->assertSame('0.0000',$result['settledCredits']);
            $this->assertSame([],$this->discoveryRequests);
            [$late] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 1,'requestKey' => 'late','refresh' => true]);
            $this->now = '2026-10-13 00:05:30'; $execution->recover('tenant',$late['id']);
            $this->assertSame('deadline',$execution->run('tenant',$late['id'])['stopReason']);
            [$revoked] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 1,'requestKey' => 'revoked','refresh' => true]);
            $this->allowed = false; $execution->recover('tenant',$revoked['id']);
            $this->assertSame('authorization_changed',$execution->run('tenant',$revoked['id'])['stopReason']);
            $this->assertSame('0.0000',$pdo->query('SELECT reserved_credits FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame([],$this->discoveryRequests);
            $this->assertAdmissionAudit($pdo);
        });
    }
}
