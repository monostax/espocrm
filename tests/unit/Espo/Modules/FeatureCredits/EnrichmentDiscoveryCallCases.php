<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\Conflict;
use Espo\Modules\FeatureCredits\Accounting\{Clock,WalletLock};
use Espo\Modules\FeatureCredits\Enrichment\{Catalog,DiscoveryCalls,DiscoveryExecution,DiscoveryResult,DiscoverySupplier,DiscoverySuppliers,Quotes};
use Espo\ORM\EntityManager;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

trait EnrichmentDiscoveryCallCases
{
    #[DataProvider('dialects')]
    public function testDiscoveryContinuationExpiryContractChangesAndCursorCyclesFailClosed(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'30');
            [$calls,$purchases] = $this->discoveryCalls($pdo);
            [$quote] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 1]);
            $this->discoveryRetention = 90*86400+1;
            try { $calls->page('tenant',$quote['id'],'cpfcnpj',1); $this->fail('Unverified retention accepted.'); } catch (Conflict) {}
            $this->assertSame(0,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_discovery_call')->fetchColumn());
            $this->discoveryRetention = 60;
            $first = $calls->page('tenant',$quote['id'],'cpfcnpj',1);
            $this->assertNull($calls->dispatch('tenant',$quote['id'],$first['id'],str_repeat('0',64)));
            $this->discoveryRetention = 30;
            try { $calls->dispatch('tenant',$quote['id'],$first['id'],$first['token']); $this->fail('Changed contract dispatched.'); } catch (Conflict) {}
            $this->discoveryRetention = 60;
            $calls->dispatch('tenant',$quote['id'],$first['id'],$first['token']);
            $calls->record('tenant',$quote['id'],$first['id'],$first['token'],new DiscoveryResult('succeeded',[],'cycle'));
            try { $calls->page('tenant',$quote['id'],'cpfcnpj',2,'invented'); $this->fail('Invented cursor dispatched.'); } catch (Conflict) {}
            $next = $calls->page('tenant',$quote['id'],'cpfcnpj',2,'cycle');
            $calls->dispatch('tenant',$quote['id'],$next['id'],$next['token']);
            $calls->record('tenant',$quote['id'],$next['id'],$next['token'],new DiscoveryResult('succeeded',[],'cycle'));
            $this->assertSame(['state' => 'stopped','reason' => 'unsafe_paid_repeat'],$calls->page('tenant',$quote['id'],'cpfcnpj',3,'cycle'));
            $purchases->close('tenant',$quote['id']);
            $this->now = '2026-10-13 00:01:00';
            [$later] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 1,'requestKey' => 'expired']);
            $this->assertSame(['state' => 'stopped','reason' => 'unsafe_paid_repeat'],$calls->page('tenant',$later['id'],'cpfcnpj',1));
            $this->executor($pdo)->expireResults();
            $this->assertSame(0,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_discovery_call WHERE result_ciphertext IS NOT NULL OR request_ciphertext IS NOT NULL')->fetchColumn());
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testIndependentDiscoveryClaimsDispatchAndOutcomeReplayHaveSingleOwnership(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog) use ($dialect): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'30');
            [$calls] = $this->discoveryCalls($pdo); [$quote] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 1]);
            $payload = ['tenantId' => 'tenant','quoteId' => $quote['id'],'provider' => 'cpfcnpj','page' => 1];
            $claims = $this->race($pdo,$dialect,'discovery-page',[$payload,$payload]);
            $this->assertArrayHasKey('result',$claims[0]); $this->assertArrayHasKey('result',$claims[1]);
            $this->assertSame($claims[0]['result']['id'],$claims[1]['result']['id']);
            $owners = array_values(array_filter($claims,static fn ($r) => isset($r['result']['token']))); $this->assertCount(1,$owners);
            $claim = $owners[0]['result'];
            $dispatch = ['tenantId' => 'tenant','quoteId' => $quote['id'],'id' => $claim['id'],'token' => $claim['token']];
            $requests = $this->race($pdo,$dialect,'discovery-dispatch',[$dispatch,$dispatch]);
            $this->assertCount(1,array_filter($requests,static fn ($r) => isset($r['result'])));
            $this->assertCount(1,array_filter($requests,static fn ($r) => array_key_exists('result',$r) && $r['result'] === null));
            $outcome = [...$dispatch,'result' => ['state' => 'succeeded','items' => [$this->discoveryPerson('one')]]];
            $recorded = $this->race($pdo,$dialect,'discovery-record',[$outcome,$outcome]);
            $this->assertArrayHasKey('result',$recorded[0]); $this->assertSame($recorded[0],$recorded[1]);
            $this->assertSame(1,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_candidate_cache')->fetchColumn());
            $this->assertSame(1,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_discovery_call')->fetchColumn());
            $conflict = $this->race($pdo,$dialect,'discovery-record',[$outcome,[...$dispatch,'result' => ['state' => 'no_match']]]);
            $this->assertSame($recorded[0],$conflict[0]); $this->assertSame(['error' => Conflict::class],$conflict[1]);
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testDiscoveryWorkerSigkillBeforeAndAfterOutcomePersistenceNeverRepeatsSupplierRequest(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog) use ($dialect): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'60');
            [$calls,$purchases,,,$execution] = $this->discoveryCalls($pdo);
            foreach (['discovery-hang','discovery-record-hang'] as $operation) {
                [$quote] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 2,'refresh' => true,'requestKey' => $operation]);
                $first = $calls->page('tenant',$quote['id'],'cpfcnpj',1);
                $calls->dispatch('tenant',$quote['id'],$first['id'],$first['token']);
                $calls->record('tenant',$quote['id'],$first['id'],$first['token'],new DiscoveryResult('succeeded',[$this->discoveryPerson('first')],'next'));
                $cacheId = $calls->result('tenant',$quote['id'],$first['id'])['items'][0]['cacheId'];
                $purchases->purchaseCached('tenant',$quote['id'],$cacheId);
                $payload = ['tenantId' => 'tenant','quoteId' => $quote['id'],'provider' => 'cpfcnpj','page' => 2,'cursor' => 'next',
                    'result' => ['state' => 'succeeded','items' => [$this->discoveryPerson('second')]]];
                $process = proc_open([PHP_BINARY,__DIR__ . '/fixtures/enrichment-quote-worker.php',$dialect,$operation,
                    base64_encode(json_encode($payload,JSON_THROW_ON_ERROR)),$this->now],
                    [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']],$pipes);
                $this->assertIsResource($process);
                try {
                    stream_set_timeout($pipes[1],15); $this->assertSame("ready\n",fgets($pipes[1]));
                    $boundary = json_decode(fgets($pipes[1]),true,32,JSON_THROW_ON_ERROR);
                    $this->assertSame($operation,$boundary['boundary']); $this->assertTrue(proc_terminate($process,9));
                    stream_get_contents($pipes[1]); $this->assertSame('',stream_get_contents($pipes[2]));
                } finally {
                    if (proc_get_status($process)['running']) proc_terminate($process,9);
                    foreach ($pipes as $pipe) fclose($pipe);
                    proc_close($process);
                }
                $this->now = (new \DateTimeImmutable($this->now))->modify('+30 seconds')->format('Y-m-d H:i:s');
                $result = $execution->run('tenant',$quote['id']);
                $this->assertSame($operation === 'discovery-hang' ? '6.0000' : '12.0000',$result['settledCredits']);
                $this->assertSame($operation === 'discovery-hang' ? 'ambiguous' : 'complete',$result['stopReason']);
                $this->assertSame($result,$execution->run('tenant',$quote['id']));
                $q = $pdo->prepare('SELECT COUNT(*) FROM enrichment_discovery_call WHERE quote_id=? AND dispatched_at IS NOT NULL');
                $q->execute([$quote['id']]); $this->assertSame(2,(int) $q->fetchColumn());
                $this->assertSame([],$this->discoveryRequests);
                $this->assertAdmissionAudit($pdo);
            }
            $this->assertSame('0.0000',$pdo->query('SELECT reserved_credits FROM tenant_credit_balance')->fetchColumn());
        });
    }

    private array $discoveryRequests = [];
    private array $discoveryReplies = [];
    private int $discoveryRetention = 86400;

    private function discoveryCalls(PDO $pdo): array
    {
        [$purchases,,,$crypt,$cache] = $this->purchaseServices($pdo);
        $manager = $this->createStub(EntityManager::class); $manager->method('getPDO')->willReturn($pdo);
        $clock = $this->createStub(Clock::class); $clock->method('now')->willReturnCallback(fn () => $this->now);
        $supplier = $this->createStub(DiscoverySupplier::class);
        $supplier->method('revision')->willReturnCallback(fn () => $this->available ? $this->revision : null);
        $supplier->method('retentionSeconds')->willReturnCallback(fn () => $this->discoveryRetention);
        $supplier->method('execute')->willReturnCallback(function ($request,$timeout): DiscoveryResult {
            $this->discoveryRequests[] = [$request,$timeout];
            $reply = array_shift($this->discoveryReplies) ?? new DiscoveryResult('no_match');
            if ($reply instanceof \Throwable) throw $reply;
            return $reply instanceof \Closure ? $reply($request) : $reply;
        });
        $suppliers = $this->createStub(DiscoverySuppliers::class); $suppliers->method('get')->willReturn($supplier);
        $calls = new DiscoveryCalls(new WalletLock($manager,$clock),$cache,$suppliers,$crypt);
        return [$calls,$purchases,$cache,$suppliers,new DiscoveryExecution($calls,$suppliers,$purchases)];
    }

    private function discoveryPerson(string $key,array $values = []): array
    {
        return ['candidateKey' => hash('sha256',$key),'values' => ['name' => 'Fixture ' . $key,'role' => 'administrator',...$values],
            'naturalPerson' => true,'incidental' => ['email' => ['hidden@example.test']]];
    }

    #[DataProvider('dialects')]
    public function testDiscoveryPageClaimDispatchOutcomeAndCacheAreDurableAndReusable(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'120');
            [$calls,$purchases] = $this->discoveryCalls($pdo);
            [$quote] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 2]);
            $claim = $calls->page('tenant',$quote['id'],'cpfcnpj',1);
            $this->assertArrayHasKey('token',$claim);
            $this->assertArrayNotHasKey('token',$calls->page('tenant',$quote['id'],'cpfcnpj',1));
            $request = $calls->dispatch('tenant',$quote['id'],$claim['id'],$claim['token']);
            $this->assertSame(30000,$request['timeoutMs']);
            $this->assertNull($calls->dispatch('tenant',$quote['id'],$claim['id'],$claim['token']));
            $result = new DiscoveryResult('succeeded',[$this->discoveryPerson('one'),$this->discoveryPerson('two')],'private-cursor');
            $recorded = $calls->record('tenant',$quote['id'],$claim['id'],$claim['token'],$result);
            $this->assertSame($recorded,$calls->record('tenant',$quote['id'],$claim['id'],$claim['token'],$result));
            $this->assertStringNotContainsString('hidden',json_encode($recorded));
            $payload = $calls->result('tenant',$quote['id'],$claim['id']);
            $this->assertCount(2,$payload['items']); $this->assertSame('private-cursor',$payload['nextCursor']);
            $this->assertStringNotContainsString('hidden',json_encode($payload));
            $this->assertSame(2,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_candidate_cache')->fetchColumn());
            $this->assertSame(0,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_purchase')->fetchColumn());
            $purchases->purchaseCached('tenant',$quote['id'],$payload['items'][0]['cacheId']);
            $this->assertSame('6.0000',$purchases->close('tenant',$quote['id'])['settledCredits']);
            [$next] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 2,'requestKey' => 'next']);
            $reuse = $calls->page('tenant',$next['id'],'cpfcnpj',1);
            $this->assertSame('succeeded',$reuse['state']); $this->assertArrayNotHasKey('token',$reuse);
            $this->assertSame($payload,$calls->result('tenant',$next['id'],$reuse['id']));
            $secondPage = $calls->page('tenant',$next['id'],'cpfcnpj',2,'private-cursor');
            $this->assertArrayHasKey('token',$secondPage);
            $this->assertSame(1,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_discovery_call WHERE dispatched_at IS NOT NULL')->fetchColumn());
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testDiscoveryOutcomeFailureRollsBackAllCacheRowsAndOutcomeThenReplaysOriginalResponse(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog) use ($dialect): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'30');
            [$calls] = $this->discoveryCalls($pdo); [$quote] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 2]);
            $claim = $calls->page('tenant',$quote['id'],'cpfcnpj',1);
            $result = new DiscoveryResult('succeeded',[$this->discoveryPerson('one'),$this->discoveryPerson('two')]);
            try { $calls->record('tenant',$quote['id'],$claim['id'],$claim['token'],$result); $this->fail('Unacknowledged result accepted.'); } catch (Conflict) {}
            $calls->dispatch('tenant',$quote['id'],$claim['id'],$claim['token']);
            $pdo->exec("ALTER TABLE enrichment_discovery_call ADD CONSTRAINT injected_outcome_failure CHECK (state<>'succeeded')");
            try { $calls->record('tenant',$quote['id'],$claim['id'],$claim['token'],$result); $this->fail('Outcome failure ignored.'); } catch (PDOException) {}
            finally { $pdo->exec('ALTER TABLE enrichment_discovery_call DROP ' . self::dropCheckKeyword($pdo) . ' injected_outcome_failure'); }
            $this->assertSame(0,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_candidate_cache')->fetchColumn());
            $this->assertSame('claimed',$pdo->query('SELECT state FROM enrichment_discovery_call')->fetchColumn());
            $calls->record('tenant',$quote['id'],$claim['id'],$claim['token'],$result);
            try { $calls->record('tenant',$quote['id'],$claim['id'],$claim['token'],new DiscoveryResult('no_match')); $this->fail('Different outcome replayed.'); } catch (Conflict) {}
            $this->assertSame(2,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_candidate_cache')->fetchColumn());
            $this->assertSame('30.0000',$pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testDiscoveryExpiredClaimAndClosedOperationCannotRetainLateResultsOrRepeatPaidLookup(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'30');
            [$calls,$purchases] = $this->discoveryCalls($pdo); [$quote] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 1]);
            $claim = $calls->page('tenant',$quote['id'],'cpfcnpj',1);
            $this->now = '2026-10-13 00:00:25';
            $this->assertSame(5000,$calls->dispatch('tenant',$quote['id'],$claim['id'],$claim['token'])['timeoutMs']);
            $this->now = '2026-10-13 00:00:30';
            $result = new DiscoveryResult('succeeded',[$this->discoveryPerson('late')]);
            $this->assertSame('waived',$calls->record('tenant',$quote['id'],$claim['id'],$claim['token'],$result)['state']);
            $this->assertSame(0,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_candidate_cache')->fetchColumn());
            $purchases->close('tenant',$quote['id']);
            [$next] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 1,'requestKey' => 'next']);
            $this->assertSame(['state' => 'stopped','reason' => 'unsafe_paid_repeat'],$calls->page('tenant',$next['id'],'cpfcnpj',1));
            [$fresh] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 1,'requestKey' => 'fresh','refresh' => true]);
            $newClaim = $calls->page('tenant',$fresh['id'],'cpfcnpj',1);
            $calls->dispatch('tenant',$fresh['id'],$newClaim['id'],$newClaim['token']);
            $purchases->close('tenant',$fresh['id']);
            $this->assertSame('waived',$calls->record('tenant',$fresh['id'],$newClaim['id'],$newClaim['token'],$result)['state']);
            $this->assertSame(0,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_candidate_cache')->fetchColumn());
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testDiscoveryCompletionFailuresConsumeAttemptLimitAndNeverAcceptDifferentPerson(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'30');
            [$calls] = $this->discoveryCalls($pdo);
            [$quote] = $this->fundedDiscovery($pdo,$quotes,$reservations,['products' => ['employees'],'count' => 1]);
            $claim = $calls->page('tenant',$quote['id'],'apollo',1); $calls->dispatch('tenant',$quote['id'],$claim['id'],$claim['token']);
            $calls->record('tenant',$quote['id'],$claim['id'],$claim['token'],new DiscoveryResult('succeeded',[
                ['candidateKey' => hash('sha256','one'),'completionToken' => 'private-person-one'],
                ['candidateKey' => hash('sha256','two'),'completionToken' => 'private-person-two'],
            ]));
            $completion = $calls->completion('tenant',$quote['id'],$claim['id'],0);
            $this->assertArrayHasKey('token',$completion);
            $request = $calls->dispatch('tenant',$quote['id'],$completion['id'],$completion['token']);
            $this->assertSame('private-person-one',$request['input']['completionToken']);
            try {
                $calls->record('tenant',$quote['id'],$completion['id'],$completion['token'],new DiscoveryResult('succeeded',[
                    ['candidateKey' => hash('sha256','two'),'values' => ['name' => 'Other','currentEmployment' => true],'naturalPerson' => true],
                ])); $this->fail('Completion switched people.');
            } catch (Conflict) {}
            $calls->record('tenant',$quote['id'],$completion['id'],$completion['token'],new DiscoveryResult('failed'));
            $this->assertSame(['state' => 'stopped','reason' => 'completion_limit'],$calls->completion('tenant',$quote['id'],$claim['id'],1));
            $this->assertSame(0,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_purchase')->fetchColumn());
        });
    }
}
