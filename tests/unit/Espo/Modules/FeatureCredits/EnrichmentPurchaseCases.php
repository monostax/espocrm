<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\{Conflict,Forbidden};
use Espo\Core\Utils\{Config,Crypt};
use Espo\Modules\FeatureCredits\Accounting\{Clock,FixedSettlement,Funding,GrantInput,ReservationInput,Reservations,WalletLock};
use Espo\Modules\FeatureCredits\Enrichment\{ActorAccess,Catalog,ProviderAvailability,Purchases,Quotes};
use Espo\ORM\EntityManager;
use InvalidArgumentException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

trait EnrichmentPurchaseCases
{
    private function purchaseServices(PDO $pdo): array
    {
        $manager = $this->createStub(EntityManager::class); $manager->method('getPDO')->willReturn($pdo);
        $clock = $this->createStub(Clock::class); $clock->method('now')->willReturnCallback(fn () => $this->now);
        $lock = new WalletLock($manager,$clock); $funding = new Funding($lock);
        $actors = $this->createStub(ActorAccess::class); $actors->method('forActor')->willReturn($this->executionAccess);
        $availability = $this->createStub(ProviderAvailability::class);
        $availability->method('inspect')->willReturnCallback(fn ($tenant,$type,$products) => $this->available ?
            array_fill_keys(['cpfcnpj','hub','apollo','registrobr'],array_fill_keys($products,$this->revision)) : []);
        $config = $this->createStub(Config::class); $config->method('get')->willReturn('enrichment-test-encryption-key');
        $crypt = new Crypt($config);
        $purchases = new Purchases($lock,new FixedSettlement($funding),$actors,$availability,$crypt);
        $readLock = new \Espo\Modules\FeatureCredits\Enrichment\ReviewLock($manager);
        $review = new \Espo\Modules\FeatureCredits\Enrichment\PurchasedReview($readLock,$this->executionAccess,$purchases);
        $fields = new \Espo\Modules\FeatureCredits\Enrichment\FieldReview($readLock,$this->executionAccess,$crypt);
        return [$purchases,new Reservations($lock,$funding),$funding,$crypt,
            new \Espo\Modules\FeatureCredits\Enrichment\CandidateCache($lock,$crypt,$actors,$availability),
            $review,new \Espo\Modules\FeatureCredits\Enrichment\PurchasedHistory($readLock,$this->executionAccess,$review,$fields),$fields,
            new \Espo\Modules\FeatureCredits\Enrichment\PurchasedErasure($manager,$crypt,new FixedSettlement($funding),$clock)];
    }

    /** Test-only funding bridge. Public discovery admission is deliberately still disabled. */
    private function fundedDiscovery(PDO $pdo,Quotes $quotes,Reservations $reservations,array $settings = []): array
    {
        $quote = $quotes->quote($this->discoveryInput($settings)); $quotes->confirm('tenant',$quote['id']);
        $hold = $reservations->reserve(new ReservationInput('tenant','enrichment','enrichment:' . $quote['id'],
            'enrichment:' . $quote['id'],'rate',$quote['maximum']));
        $pdo->prepare('UPDATE enrichment_quote SET usage_id=?,admitted_at=? WHERE id=?')->execute([$hold['usageId'],$this->now,$quote['id']]);
        return [$quote,$hold];
    }

    private function buy(Purchases $purchases,string $quote,string $key = 'first',array $values = ['name' => 'Fixture Person','role' => 'administrator'],string $provider = 'cpfcnpj'): array
    {
        return $purchases->purchase('tenant',$quote,hash('sha256',$key),$provider,$values,'2026-10-12 23:00:00');
    }

    #[DataProvider('dialects')]
    public function testIncrementalPurchaseCommitsChargeAndEncryptedAccessThenClosesWithoutRecharging(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'30');
            [$purchases,,,$crypt] = $this->purchaseServices($pdo);
            [$quote,$hold] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 2,'waterfall' => true,'budget' => '24']);
            $first = $this->buy($purchases,$quote['id']);
            $this->assertSame('6.0000',$first['credits']);
            $this->assertSame('24.0000',$pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame('18.0000',$pdo->query('SELECT reserved_credits FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame('admitted',$pdo->query('SELECT state FROM credit_usage')->fetchColumn());
            $this->assertSame('6.0000',$pdo->query('SELECT settled_credits FROM credit_usage')->fetchColumn());
            $this->assertNull($pdo->query('SELECT settled_at FROM credit_usage')->fetchColumn());
            $cipher = $pdo->query('SELECT values_ciphertext FROM enrichment_purchase')->fetchColumn();
            $this->assertStringNotContainsString('Fixture Person',$cipher);
            $this->assertSame(['name' => 'Fixture Person','role' => 'administrator'],json_decode($crypt->decrypt($cipher),true));
            $this->assertStringNotContainsString('Fixture Person',json_encode($first));
            $this->assertAdmissionAudit($pdo);
            $this->assertSame($first,$this->buy($purchases,$quote['id']));
            try { $this->buy($purchases,$quote['id'],'first',['role' => 'owner']); $this->fail('Replay changed purchased projection.'); } catch (Conflict) {}
            try { $reservations->release('tenant',$hold['usageId'],'enrichment:' . $quote['id']); $this->fail('Pre-request release erased a purchase.'); } catch (Conflict) {}
            $second = $this->buy($purchases,$quote['id'],'supplement',['role' => 'owner'],'hub');
            $this->assertNotSame($first['id'],$second['id']);
            $this->assertSame('18.0000',$pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame('12.0000',$pdo->query('SELECT reserved_credits FROM tenant_credit_balance')->fetchColumn());
            $this->assertAdmissionAudit($pdo);
            $closed = $purchases->close('tenant',$quote['id']);
            $this->assertSame('12.0000',$closed['settledCredits']);
            $this->assertSame($closed,$purchases->close('tenant',$quote['id']));
            $this->available = false; $this->now = '2026-10-14 00:00:00';
            $this->assertSame($first,$this->buy($purchases,$quote['id']));
            try { $this->buy($purchases,$quote['id'],'late'); $this->fail('Closed purchase reopened.'); } catch (Conflict) {}
            $this->assertSame('0.0000',$pdo->query('SELECT reserved_credits FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame(2,(int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type='debit'")->fetchColumn());
            $this->assertSame(2,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_purchase')->fetchColumn());
            $this->assertSame(0,(int) $pdo->query('SELECT COUNT(*) FROM credit_request')->fetchColumn());
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testIncrementalLateClosureExpiresUnusedOriginalGrantAndReleasesOtherOriginalGrant(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'12','2026-10-13 00:00:30');
            [$purchases,,$funding] = $this->purchaseServices($pdo);
            $funding->grant(new GrantInput('tenant','purchase','second','8',$this->now,null,(object) []));
            [$quote] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 3]);
            $this->buy($purchases,$quote['id']); $this->now = '2026-10-13 00:00:40';
            $this->assertSame('6.0000',$purchases->close('tenant',$quote['id'])['settledCredits']);
            $this->assertSame('8.0000',$pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame('0.0000',$pdo->query('SELECT reserved_credits FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame('-6.0000',$pdo->query("SELECT credits FROM credit_transaction WHERE type='expiration'")->fetchColumn());
            $this->assertSame('0.0000',$pdo->query("SELECT remaining_credits FROM credit_grant WHERE source_key='opening'")->fetchColumn());
            $this->assertSame('8.0000',$pdo->query("SELECT remaining_credits FROM credit_grant WHERE source_key='second'")->fetchColumn());
            $this->assertSame(2,(int) $pdo->query('SELECT COUNT(DISTINCT grant_id) FROM credit_allocation')->fetchColumn());
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testOnePurchaseSpansGrantsAndLaterPurchaseCanConsumeAlreadyHeldExpiredFunds(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'4','2026-10-13 00:00:20');
            [$purchases,,$funding] = $this->purchaseServices($pdo);
            $funding->grant(new GrantInput('tenant','subscription','second','10',$this->now,'2026-10-13 00:00:30',(object) []));
            [$quote] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 2]);
            $this->buy($purchases,$quote['id']);
            $this->assertSame(2,(int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type='debit'")->fetchColumn());
            $this->assertSame('6.0000',$pdo->query('SELECT reserved_credits FROM tenant_credit_balance')->fetchColumn());
            $this->now = '2026-10-13 00:00:40';
            $this->buy($purchases,$quote['id'],'second');
            $this->assertSame('0.0000',$pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame('-2.0000',$pdo->query("SELECT credits FROM credit_transaction WHERE type='expiration'")->fetchColumn());
            $this->assertAdmissionAudit($pdo);
            $this->assertSame('12.0000',$purchases->close('tenant',$quote['id'])['settledCredits']);
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testPurchaseFailureRollsBackInsertedAccessAndDebitAndClosureFailureKeepsSuccessfulPurchase(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog) use ($dialect): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'18');
            [$purchases] = $this->purchaseServices($pdo);
            [$quote] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 3]);
            $pdo->exec("ALTER TABLE credit_transaction ADD CONSTRAINT injected_purchase_failure CHECK (type<>'debit')");
            try { $this->buy($purchases,$quote['id']); $this->fail('Debit failure ignored.'); } catch (PDOException) {}
            finally { $pdo->exec('ALTER TABLE credit_transaction DROP ' . self::dropCheckKeyword($pdo) . ' injected_purchase_failure'); }
            $this->assertSame(0,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_purchase')->fetchColumn());
            $this->assertSame('18.0000',$pdo->query('SELECT reserved_credits FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame(1,(int) $pdo->query('SELECT COUNT(*) FROM credit_allocation')->fetchColumn());
            $this->assertAdmissionAudit($pdo);
            $first = $this->buy($purchases,$quote['id']);
            $pdo->exec('ALTER TABLE tenant_credit_balance ADD CONSTRAINT injected_close_failure CHECK (reserved_credits>=6)');
            try { $purchases->close('tenant',$quote['id']); $this->fail('Closure failure ignored.'); } catch (PDOException) {}
            finally { $pdo->exec('ALTER TABLE tenant_credit_balance DROP ' . self::dropCheckKeyword($pdo) . ' injected_close_failure'); }
            $this->assertSame('admitted',$pdo->query('SELECT state FROM credit_usage')->fetchColumn());
            $this->assertSame('12.0000',$pdo->query('SELECT reserved_credits FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame($first,$this->buy($purchases,$quote['id']));
            $this->assertAdmissionAudit($pdo);
            $this->assertSame('6.0000',$purchases->close('tenant',$quote['id'])['settledCredits']);
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testPurchaseRechecksIdentityPermissionsCapabilityProjectionCountAndDeadline(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'30');
            [$purchases] = $this->purchaseServices($pdo);
            [$quote] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 1,'waterfall' => true,'budget' => '12']);
            foreach ([['email' => 'hidden@example.test'],['cpf' => '***.123.***-**'],['linkedinUrl' => 'https://linkedin.com/company/example']] as $bad) {
                try { $this->buy($purchases,$quote['id'],'bad',$bad); $this->fail('Unpurchased or invalid field accepted.'); } catch (InvalidArgumentException) {}
            }
            $pdo->exec("UPDATE account SET cnpj='04252011000110'");
            try { $this->buy($purchases,$quote['id']); $this->fail('Stale identity purchased.'); } catch (Conflict) {}
            $pdo->exec("UPDATE account SET cnpj='11222333000181'");
            $this->hiddenIdentityFields = ['Contact.cpf'];
            try { $this->buy($purchases,$quote['id']); $this->fail('Changed disclosure purchased.'); } catch (Conflict) {}
            $this->hiddenIdentityFields = []; $this->available = false;
            try { $this->buy($purchases,$quote['id']); $this->fail('Unavailable supplier purchased.'); } catch (Conflict) {}
            $this->available = true; $this->allowed = false;
            try { $this->buy($purchases,$quote['id']); $this->fail('Revoked permission purchased.'); } catch (Forbidden) {}
            $this->allowed = true;
            $this->assertSame(0,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_purchase')->fetchColumn());
            $this->buy($purchases,$quote['id']);
            try { $this->buy($purchases,$quote['id'],'excess'); $this->fail('Provider count exceeded.'); } catch (Conflict) {}
            $this->now = '2026-10-13 00:05:00';
            try { $this->buy($purchases,$quote['id'],'late',['role' => 'owner'],'hub'); $this->fail('Late contribution purchased.'); } catch (Conflict) {}
            $this->assertSame('6.0000',$purchases->close('tenant',$quote['id'])['settledCredits']);
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testConcurrentPurchasesReplayOnceAndCannotOverspendOrReopenAfterClosure(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog) use ($dialect): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'18');
            [$purchases] = $this->purchaseServices($pdo);
            [$quote] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 3,'budget' => '12']);
            $payload = ['tenantId' => 'tenant','quoteId' => $quote['id'],'key' => hash('sha256','first'),
                'provider' => 'cpfcnpj','values' => ['name' => 'Fixture Person','role' => 'administrator'],'acquiredAt' => '2026-10-12 23:00:00'];
            $results = $this->race($pdo,$dialect,'purchase',[$payload,$payload]);
            $this->assertArrayHasKey('result',$results[0]); $this->assertSame($results[0],$results[1]);
            $this->assertSame($results[0]['result'],$this->buy($purchases,$quote['id']));
            $this->assertSame(1,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_purchase')->fetchColumn());
            $different = [...$payload,'values' => ['role' => 'owner']];
            $conflict = $this->race($pdo,$dialect,'purchase',[$payload,$different]);
            $this->assertSame($results[0],$conflict[0]); $this->assertSame(['error' => Conflict::class],$conflict[1]);
            $last = [...$payload,'key' => hash('sha256','last')]; $excess = [...$payload,'key' => hash('sha256','excess')];
            $results = $this->race($pdo,$dialect,'purchase',[$last,$excess]);
            $this->assertCount(1,array_filter($results,static fn ($r) => isset($r['result'])));
            $this->assertCount(1,array_filter($results,static fn ($r) => ($r['error'] ?? null) === Conflict::class));
            $this->assertSame('6.0000',$pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame('0.0000',$pdo->query('SELECT reserved_credits FROM tenant_credit_balance')->fetchColumn());
            $this->assertAdmissionAudit($pdo);
            $close = ['tenantId' => 'tenant','quoteId' => $quote['id']];
            $closed = $this->race($pdo,$dialect,'purchase-close',[$close,$close]);
            $this->assertSame($closed[0],$closed[1]); $this->assertSame('12.0000',$closed[0]['result']['settledCredits']);
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testCommittedPurchaseSurvivesWorkerSigkillBeforeAcknowledgement(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog) use ($dialect): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'18');
            [$purchases] = $this->purchaseServices($pdo);
            [$quote] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 3]);
            $payload = ['tenantId' => 'tenant','quoteId' => $quote['id'],'key' => hash('sha256','first'),
                'provider' => 'cpfcnpj','values' => ['name' => 'Fixture Person','role' => 'administrator'],'acquiredAt' => '2026-10-12 23:00:00'];
            $process = proc_open([PHP_BINARY,__DIR__ . '/fixtures/enrichment-quote-worker.php',$dialect,'purchase-hang',
                base64_encode(json_encode($payload,JSON_THROW_ON_ERROR)),$this->now],
                [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']],$pipes);
            $this->assertIsResource($process);
            try {
                stream_set_timeout($pipes[1],15); $this->assertSame("ready\n",fgets($pipes[1]));
                $committed = json_decode(fgets($pipes[1]),true,32,JSON_THROW_ON_ERROR);
                $this->assertArrayHasKey('purchaseCommitted',$committed);
                $this->assertTrue(proc_terminate($process,9));
                stream_get_contents($pipes[1]); $this->assertSame('',stream_get_contents($pipes[2]));
                $this->assertSame($committed['purchaseCommitted'],$this->buy($purchases,$quote['id']));
                $this->assertSame(1,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_purchase')->fetchColumn());
                $this->assertSame(1,(int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type='debit'")->fetchColumn());
                $this->assertSame('6.0000',$purchases->close('tenant',$quote['id'])['settledCredits']);
                $this->assertAdmissionAudit($pdo);
            } finally {
                if (proc_get_status($process)['running']) proc_terminate($process,9);
                foreach ($pipes as $pipe) fclose($pipe);
                proc_close($process);
            }
        });
    }

    #[DataProvider('dialects')]
    public function testConcurrentAiCannotSpendConsumedHoldAndClosePurchaseRaceRemainsAtomic(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog) use ($dialect): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'18');
            [$purchases] = $this->purchaseServices($pdo);
            [$quote] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 2]);
            $payload = ['tenantId' => 'tenant','quoteId' => $quote['id'],'key' => hash('sha256','first'),
                'provider' => 'cpfcnpj','values' => ['role' => 'owner'],'acquiredAt' => '2026-10-12 23:00:00'];
            $results = $this->race($pdo,$dialect,'purchase',[$payload,['testOperation' => 'ai']]);
            $this->assertArrayHasKey('result',$results[0]); $this->assertSame(['error' => Conflict::class],$results[1]);
            $this->assertSame('12.0000',$pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame('6.0000',$pdo->query('SELECT reserved_credits FROM tenant_credit_balance')->fetchColumn());
            $results = $this->race($pdo,$dialect,'purchase',[[...$payload,'key' => hash('sha256','second')],
                ['testOperation' => 'purchase-close','tenantId' => 'tenant','quoteId' => $quote['id']]]);
            $this->assertArrayHasKey('result',$results[1]);
            $count = (int) $pdo->query('SELECT COUNT(*) FROM enrichment_purchase')->fetchColumn();
            $this->assertContains($count,[1,2]);
            $this->assertSame($count === 2 ? '12.0000' : '6.0000',$results[1]['result']['settledCredits']);
            $this->assertSame('0.0000',$pdo->query('SELECT reserved_credits FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame($results[1]['result'],$purchases->close('tenant',$quote['id']));
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testOriginalRateSurvivesCutoverAndCorruptReceiptsCannotJustifyOpenConsumption(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'18');
            [$purchases] = $this->purchaseServices($pdo);
            [$quote] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 3]);
            $next = EnrichmentFixtures::discoveryDefinition('new-purchase-rate','2026-10-13 00:01:00');
            $next['rates'][14]['credits'] = '9'; $catalog->publish((object) $next,'admin');
            $this->now = '2026-10-13 00:01:00';
            $receipt = $this->buy($purchases,$quote['id']);
            $this->assertSame('6.0000',$receipt['credits']); $this->assertSame($quote['policyId'],$receipt['policyId']);
            $manager = $this->createStub(EntityManager::class); $manager->method('getPDO')->willReturn($pdo);
            $clock = $this->createStub(Clock::class); $clock->method('now')->willReturn($this->now);
            $audit = new \Espo\Modules\FeatureCredits\Accounting\ConsistencyAudit($manager,$clock);
            $pdo->exec("UPDATE enrichment_purchase SET credits='0.0001'");
            $this->assertContains('usage.purchases',array_column($audit->inspect('tenant')['findings'],'code'));
            try { $this->buy($purchases,$quote['id']); $this->fail('Corrupt receipt replayed a different price.'); }
            catch (Conflict) { $this->addToAssertionCount(1); }
            try { $purchases->close('tenant',$quote['id']); $this->fail('Corrupt receipt closed.'); } catch (Conflict) {}
            $pdo->exec("UPDATE enrichment_purchase SET credits='6.0000',deleted=TRUE");
            $this->assertContains('purchase.binding',array_column($audit->inspect('tenant')['findings'],'code'));
            $this->assertContains('usage.consumption',array_column($audit->inspect('tenant')['findings'],'code'));
            $pdo->exec('UPDATE enrichment_purchase SET deleted=FALSE');
            $this->assertAdmissionAudit($pdo);
            $this->assertSame('6.0000',$purchases->close('tenant',$quote['id'])['settledCredits']);
        });
    }
}
