<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\{Conflict,NotFound};
use Espo\Modules\FeatureCredits\Enrichment\{CandidateCache,Catalog,Quotes};
use InvalidArgumentException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

trait EnrichmentCandidateCacheCases
{
    #[DataProvider('dialects')]
    public function testEmptyPurchasedHistoryDoesNotCreateWalletOrFinancialRecords(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo): void {
            $history = $this->purchaseServices($pdo)[6];
            foreach (['socios','email'] as $product) {
                $this->assertSame(['list' => [],'next' => null],$history->read([
                    'tenantId' => 'tenant','targetType' => 'Account','targetId' => 'contact','product' => $product]));
            }
            foreach (['tenant_credit_balance','credit_grant','credit_usage','credit_transaction'] as $table) {
                $this->assertSame(0,(int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn());
            }
        });
    }

    #[DataProvider('dialects')]
    public function testContactErasureKeepsUnrelatedCandidateInSameQuoteAndNeverExposesErasedValues(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'60');
            [$purchases,,,,$cache,$review,,,$erasure] = $this->purchaseServices($pdo);
            [$quote] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 2]);
            $first = $this->cached($cache,$quote['id'],'first',['cpf' => '52998224725']);
            $second = $this->cached($cache,$quote['id'],'second',['cpf' => '11144477735']);
            $one = $purchases->purchaseCached('tenant',$quote['id'],$first['id']);
            $two = $purchases->purchaseCached('tenant',$quote['id'],$second['id']);
            $entity = new \Espo\ORM\BaseEntity('Contact',['attributes' => array_fill_keys(['id','tenantId','cpf'],['type' => 'varchar'])]);
            $entity->set(['id' => 'contact','tenantId' => 'tenant','cpf' => '52998224725']);
            $pdo->beginTransaction(); $erasure->erase($entity); $pdo->commit();
            $this->assertTrue($review->read('tenant',$one['id'])['erased']);
            $other = $review->read('tenant',$two['id']); $this->assertFalse($other['erased']);
            $this->assertSame('11144477735',$other['values']->cpf);
            $this->assertSame(1,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_candidate_cache WHERE payload_ciphertext IS NOT NULL')->fetchColumn());
            $this->assertSame('12.0000',$pdo->query('SELECT settled_credits FROM credit_usage')->fetchColumn());
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testPurchasedErasurePreservesFinancialReceiptsClosesHoldAndRollsBackWithRecordTransaction(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'60');
            [$purchases,,,,$cache,$review,,,$erasure] = $this->purchaseServices($pdo);
            [$quote] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 2]);
            $source = $this->cached($cache,$quote['id'],'first',['cpf' => '52998224725']);
            $receipt = $purchases->purchaseCached('tenant',$quote['id'],$source['id']);
            $this->cached($cache,$quote['id'],'hidden',['cpf' => '11144477735']);
            $entity = new \Espo\ORM\BaseEntity('Account',['attributes' => array_fill_keys(['id','tenantId'],['type' => 'varchar'])]);
            $entity->set(['id' => 'contact','tenantId' => 'tenant']);
            $pdo->beginTransaction(); $erasure->erase($entity); $pdo->rollBack();
            $this->assertFalse($review->read('tenant',$receipt['id'])['erased']);
            $this->assertSame('admitted',$pdo->query('SELECT state FROM credit_usage')->fetchColumn());
            $pdo->beginTransaction(); $erasure->erase($entity); $pdo->commit();
            $read = $review->read('tenant',$receipt['id']);
            $this->assertSame(\Espo\Modules\FeatureCredits\Enrichment\CustomerProjection::purchase($receipt,$quote),$read['receipt']); $this->assertTrue($read['erased']);
            $this->assertSame([],(array) $read['values']); $this->assertSame('data_erased',$read['application']['reason']);
            $this->assertSame(0,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_candidate_cache WHERE payload_ciphertext IS NOT NULL')->fetchColumn());
            $this->assertSame('settled',$pdo->query('SELECT state FROM credit_usage')->fetchColumn());
            $this->assertSame('6.0000',$pdo->query('SELECT settled_credits FROM credit_usage')->fetchColumn());
            $this->assertSame('54.0000',$pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame('0.0000',$pdo->query('SELECT reserved_credits FROM tenant_credit_balance')->fetchColumn());
            $pdo->beginTransaction(); $erasure->erase($entity); $pdo->commit();
            $this->assertEquals($read,$review->read('tenant',$receipt['id'])); $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testPurchasedHistoryPagesReceiptsWithoutHiddenCountsOrValuesAndRechecksAccess(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'60');
            [$purchases,,,,$cache,,$history] = $this->purchaseServices($pdo);
            [$quote] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 3]);
            $ids = [];
            foreach (['one','two','three','hidden'] as $person) {
                $source = $this->cached($cache,$quote['id'],$person,['cpf' => $person === 'one' ? '52998224725' : '11144477735'],person: $person);
                // Separate fixture contributions avoid deduplicating the intentionally repeated CPF.
                if ($person !== 'hidden') $ids[] = $this->buy($purchases,$quote['id'],$person)['id'];
            }
            $purchases->close('tenant',$quote['id']); rsort($ids,SORT_STRING);
            $query = ['tenantId' => 'tenant','targetType' => 'Account','targetId' => 'contact','product' => 'socios','limit' => '2'];
            $first = $history->read($query);
            $this->assertSame(array_slice($ids,0,2),array_column(array_column($first['list'],'receipt'),'id'));
            $this->assertNotNull($first['next']); $this->assertCount(2,$first['list']);
            $second = $history->read($query + $first['next']);
            $this->assertSame([$ids[2]],array_column(array_column($second['list'],'receipt'),'id'));
            $this->assertNull($second['next']);
            $encoded = json_encode($first);
            foreach (['Fixture Person','52998224725','values','ciphertext','total'] as $hidden) $this->assertStringNotContainsString($hidden,$encoded);
            $this->assertSame(['6.0000','6.0000'],array_column(array_column($first['list'],'receipt'),'credits'));
            $this->assertSame([],$history->read(array_replace($query,['product' => 'employees']))['list']);
            foreach ([['limit' => '01'],['limit' => 51],['beforeAt' => $this->now],['unexpected' => true]] as $bad) {
                try { $history->read(array_replace($query,$bad)); $this->fail('Invalid history query accepted.'); } catch (InvalidArgumentException) {}
            }
            $this->allowed = false;
            try { $history->read($query); $this->fail('Revoked actor enumerated history.'); } catch (\Espo\Core\Exceptions\Forbidden) {}
            $this->allowed = true;
            try { $history->read(array_replace($query,['tenantId' => 'other'])); $this->fail('Foreign target enumerated history.'); } catch (\Espo\Core\Exceptions\Forbidden) {}
            $pdo->prepare("UPDATE enrichment_purchase SET credits='1.0000' WHERE id=?")->execute([$ids[2]]);
            try { $history->read($query); $this->fail('Corrupt lookahead accepted.'); } catch (Conflict) {}
        });
    }

    #[DataProvider('dialects')]
    public function testDurableCandidateConflictSurvivesRejectionRestartExpiryAndBlocksNewContributions(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog) use ($dialect): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'60');
            [$purchases,,,,$cache,$review] = $this->purchaseServices($pdo);
            [$quote] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 1,'waterfall' => true,'budget' => '12']);
            $original = $this->cached($cache,$quote['id'],'first',['cpf' => '52998224725']);
            $receipt = $purchases->purchaseCached('tenant',$quote['id'],$original['id']);
            $conflicting = $this->cached($cache,$quote['id'],'conflict',['cpf' => '11144477735'],person: 'first');
            $input = ['tenantId' => 'tenant','quoteId' => $quote['id'],'cacheId' => $conflicting['id']];
            $this->assertSame([['error' => Conflict::class],['error' => Conflict::class]],$this->race($pdo,$dialect,'cache-purchase',[$input,$input]));
            $this->assertSame(2,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_candidate_cache WHERE conflict_at IS NOT NULL')->fetchColumn());
            $blocked = $review->read('tenant',$receipt['id']);
            $this->assertSame('identity_contradiction',$blocked['conflict']['reason']);
            $this->assertSame('identity_conflict',$blocked['application']['reason']);
            $this->assertSame(\Espo\Modules\FeatureCredits\Enrichment\CustomerProjection::purchase($receipt,$quote),$blocked['receipt']);
            $this->assertSame('52998224725',$blocked['values']->cpf);
            $extra = $this->cached($cache,$quote['id'],'extra',['cpf' => '52998224725','ownershipPercentage' => '25'],'hub');
            try { $purchases->purchaseCached('tenant',$quote['id'],$extra['id']); $this->fail('Blocked group bought a supplement.'); } catch (Conflict) {}
            $this->assertSame(3,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_candidate_cache WHERE conflict_at IS NOT NULL')->fetchColumn());
            $this->assertSame($receipt,$purchases->purchaseCached('tenant',$quote['id'],$original['id']));
            $this->assertSame('6.0000',$purchases->close('tenant',$quote['id'])['settledCredits']);
            $this->now = '2026-10-15 00:00:00'; $cache->purge('tenant');
            [,,,,,$restarted] = $this->purchaseServices($pdo);
            $this->assertEquals($blocked,$restarted->read('tenant',$receipt['id']));
            $this->assertSame(1,(int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type='debit'")->fetchColumn());
            $this->assertSame('54.0000',$pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testIdentityBridgeQuarantinesEveryPurchasedGroupWithoutRevealingHiddenCandidate(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'60');
            [$purchases,,,,$cache,$review] = $this->purchaseServices($pdo);
            [$quote] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 2,'waterfall' => true,'budget' => '24']);
            $one = $this->cached($cache,$quote['id'],'one',['cpf' => '52998224725']);
            $two = $this->cached($cache,$quote['id'],'two',['linkedinUrl' => 'https://www.linkedin.com/in/two']);
            $a = $purchases->purchaseCached('tenant',$quote['id'],$one['id']);
            $b = $purchases->purchaseCached('tenant',$quote['id'],$two['id']);
            $bridge = $this->cached($cache,$quote['id'],'bridge',['cpf' => '52998224725','linkedinUrl' => 'https://www.linkedin.com/in/two'],'hub');
            try { $purchases->purchaseCached('tenant',$quote['id'],$bridge['id']); $this->fail('Bridge merged purchased people.'); } catch (Conflict) {}
            foreach ([$a,$b] as $receipt) $this->assertSame('identity_bridge',$review->read('tenant',$receipt['id'])['conflict']['reason']);
            try { $review->read('tenant',$bridge['id']); $this->fail('Hidden acquisition became readable.'); } catch (NotFound) {}
            $this->assertSame('12.0000',$purchases->close('tenant',$quote['id'])['settledCredits']);
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testPurchasedReviewUsesCurrentActorAclRedactsSensitiveFieldsAndRetainsOriginalReceipt(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'30');
            [$purchases,,,,$cache,$review] = $this->purchaseServices($pdo);
            [$quote] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 1]);
            $source = $this->cached($cache,$quote['id'],'first',['cpf' => '52998224725','linkedinUrl' => 'https://www.linkedin.com/in/person']);
            $receipt = $purchases->purchaseCached('tenant',$quote['id'],$source['id']); $purchases->close('tenant',$quote['id']);
            $this->actor = 'another-user'; $this->available = false;
            $read = $review->read('tenant',$receipt['id']);
            $this->assertSame(\Espo\Modules\FeatureCredits\Enrichment\CustomerProjection::purchase($receipt,$quote),$read['receipt']); $this->assertNull($read['conflict']);
            $this->assertStringNotContainsString('hidden',json_encode($read));
            $controller = new \Espo\Modules\FeatureCredits\Controllers\CreditEnrichmentReview($review,$this->purchaseServices($pdo)[7]);
            $request = $this->createStub(\Espo\Core\Api\Request::class);
            $request->method('getQueryParams')->willReturn(['tenantId' => 'tenant','id' => $receipt['id']]);
            $response = $this->createMock(\Espo\Core\Api\Response::class);
            $response->expects($this->once())->method('setHeader')->with('Cache-Control','private, no-store');
            $this->assertEquals((object) $read,$controller->getActionRead($request,$response));
            $this->hiddenIdentityFields = ['Contact.cpf','ContactChannelIdentity.sourceId'];
            $redacted = $review->read('tenant',$receipt['id']);
            $this->assertSame(['cpf','linkedinUrl'],$redacted['redactedFields']);
            $this->assertObjectNotHasProperty('cpf',$redacted['values']); $this->assertObjectNotHasProperty('linkedinUrl',$redacted['values']);
            $pdo->exec("UPDATE account SET cnpj='04252011000110'");
            $this->assertSame('company_identity_changed',$review->read('tenant',$receipt['id'])['application']['reason']);
            $this->allowed = false;
            try { $review->read('tenant',$receipt['id']); $this->fail('Revoked actor read purchased values.'); } catch (\Espo\Core\Exceptions\Forbidden) {}
            $this->allowed = true;
            try { $review->read('other',$receipt['id']); $this->fail('Cross-tenant review.'); } catch (NotFound) {}
            $this->deniedIdentityIds = ['contact'];
            try { $review->read('tenant',$receipt['id']); $this->fail('Denied Account read.'); } catch (\Espo\Core\Exceptions\Forbidden) {}
            $this->deniedIdentityIds = [];
            $this->assertSame(1,(int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type='debit'")->fetchColumn());
            $pdo->exec("UPDATE enrichment_purchase SET credits='5.0000'");
            try { $review->read('tenant',$receipt['id']); $this->fail('Corrupt receipt revealed.'); } catch (Conflict) {}
            $pdo->exec("UPDATE enrichment_purchase SET credits='6.0000'");
            $pdo->exec('UPDATE enrichment_purchase SET deleted=TRUE');
            try { $review->read('tenant',$receipt['id']); $this->fail('Deleted purchase revealed.'); } catch (NotFound) {}
        });
    }

    #[DataProvider('dialects')]
    public function testEmployeeAndRegistrantCacheProductsKeepTheirQualificationAndDisclosureBoundaries(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'30');
            [$purchases,,,,$cache] = $this->purchaseServices($pdo);
            [$employee] = $this->fundedDiscovery($pdo,$quotes,$reservations,['products' => ['employees'],'count' => 1]);
            $storeEmployee = fn (array $values,bool $natural = true) => $cache->store('tenant',$employee['id'],'apollo',
                hash('sha256','employee-result'),hash('sha256','apollo-person'),$this->now,'2026-10-14 00:00:00',$values,[], $natural);
            foreach ([['name' => 'Person'],['name' => 'Person','currentEmployment' => false],
                ['name' => 'Person','currentEmployment' => true,'email' => 'hidden@example.test']] as $bad) {
                try { $storeEmployee($bad); $this->fail('Unqualified Employee cached.'); } catch (InvalidArgumentException) {}
            }
            try { $storeEmployee(['name' => 'Person','currentEmployment' => true],false); $this->fail('Unclassified person cached.'); } catch (InvalidArgumentException) {}
            $saved = $storeEmployee(['name' => 'Person','currentEmployment' => true,'linkedinUrl' => 'https://www.linkedin.com/in/person/']);
            $this->assertSame('2.0000',$purchases->purchaseCached('tenant',$employee['id'],$saved['id'])['credits']);
            $purchases->close('tenant',$employee['id']);
            [$registrant] = $this->fundedDiscovery($pdo,$quotes,$reservations,['products' => ['cnpj'],'requestKey' => 'registrant']);
            $storeRegistrant = fn (array $values,array $incidental = []) => $cache->store('tenant',$registrant['id'],'registrobr',
                hash('sha256','rdap-result'),hash('sha256','registrant'),$this->now,'2026-10-14 00:00:00',$values,$incidental);
            foreach ([['cnpj' => '***'],['cpf' => '52998224725'],[]] as $bad) {
                try { $storeRegistrant($bad); $this->fail('Unqualified registrant cached.'); } catch (InvalidArgumentException) {}
            }
            try { $storeRegistrant(['cnpj' => '11222333000181'],['email' => ['hidden@example.test']]); $this->fail('Company contacts transferred to a person.'); } catch (InvalidArgumentException) {}
            $saved = $storeRegistrant(['cnpj' => '11.222.333/0001-81']);
            $this->assertSame('2.0000',$purchases->purchaseCached('tenant',$registrant['id'],$saved['id'])['credits']);
            $purchases->close('tenant',$registrant['id']);
            $this->assertSame('26.0000',$pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testConcurrentCacheAcquisitionAndCrossQuotePurchaseCommitOnce(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog) use ($dialect): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'60');
            [$purchases] = $this->purchaseServices($pdo);
            [$first] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 2]);
            [$second] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 2,'requestKey' => 'second']);
            $payload = ['tenantId' => 'tenant','quoteId' => $first['id'],'provider' => 'cpfcnpj',
                'key' => hash('sha256','source'),'candidateKey' => hash('sha256','person'),
                'acquiredAt' => $this->now,'expiresAt' => '2026-10-14 00:00:00','values' => ['name' => 'Same Person','role' => 'administrator']];
            $stored = $this->race($pdo,$dialect,'cache-store',[$payload,$payload]);
            $this->assertArrayHasKey('result',$stored[0]); $this->assertSame($stored[0],$stored[1]);
            $this->assertSame(1,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_candidate_cache')->fetchColumn());
            $conflict = $this->race($pdo,$dialect,'cache-store',[$payload,[...$payload,'values' => ['name' => 'Changed','role' => 'owner']]]);
            $this->assertSame($stored[0],$conflict[0]); $this->assertSame(['error' => Conflict::class],$conflict[1]);
            $purchase = ['tenantId' => 'tenant','quoteId' => $first['id'],'cacheId' => $stored[0]['result']['id']];
            $receipts = $this->race($pdo,$dialect,'cache-purchase',[$purchase,[...$purchase,'quoteId' => $second['id']]]);
            $this->assertArrayHasKey('result',$receipts[0]); $this->assertSame($receipts[0],$receipts[1]);
            $this->assertSame(1,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_purchase')->fetchColumn());
            $this->assertSame(1,(int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type='debit'")->fetchColumn());
            $this->assertSame('54.0000',$pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            $purchases->close('tenant',$first['id']); $purchases->close('tenant',$second['id']);
            $this->assertSame('0.0000',$pdo->query('SELECT reserved_credits FROM tenant_credit_balance')->fetchColumn());
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testFreshCachePurchaseRequiresNewAcquisitionAndRetainsOneReceiptPerCandidate(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'60');
            [$purchases,,,,$cache] = $this->purchaseServices($pdo);
            [$first] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 1]);
            $original = $this->cached($cache,$first['id'],'first',['cpf' => '52998224725']);
            $purchases->purchaseCached('tenant',$first['id'],$original['id']); $purchases->close('tenant',$first['id']);
            [$refresh] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 1,'requestKey' => 'fresh','refresh' => true]);
            $this->assertSame([],$cache->eligible('tenant',$refresh['id'],'cpfcnpj'));
            try { $purchases->purchaseCached('tenant',$refresh['id'],$original['id']); $this->fail('Fresh refresh reused old acquisition.'); } catch (Conflict) {}
            $fresh = $this->cached($cache,$refresh['id'],'fresh',['cpf' => '52998224725'],person: 'first');
            $receipt = $purchases->purchaseCached('tenant',$refresh['id'],$fresh['id']);
            $this->assertSame($refresh['id'],$receipt['quoteId']);
            $this->assertSame($receipt,$purchases->purchaseCached('tenant',$refresh['id'],$fresh['id']));
            $duplicate = $this->cached($cache,$refresh['id'],'duplicate',['cpf' => '52998224725'],person: 'first');
            $same = $purchases->purchaseCached('tenant',$refresh['id'],$duplicate['id']);
            $this->assertSame($receipt,$same);
            $this->assertSame(2,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_purchase')->fetchColumn());
            $this->assertSame('6.0000',$purchases->close('tenant',$refresh['id'])['settledCredits']);
            $this->assertAdmissionAudit($pdo);
        });
    }

    private function cached(CandidateCache $cache,string $quote,string $key,array $values = [],string $provider = 'cpfcnpj',
        string $expires = '2026-10-14 00:00:00',?string $person = null): array
    {
        return $cache->store('tenant',$quote,$provider,hash('sha256',$key),hash('sha256',$person ?? $key),
            '2026-10-13 00:00:00',$expires,['name' => 'Person ' . $key,'role' => 'administrator',...$values],
            ['email' => ['hidden@example.test'],'phone' => ['+5511999999999']],true);
    }

    #[DataProvider('dialects')]
    public function testRestrictedFortyCandidatesTenThenTwentyCurrentPricePurchasesWithoutNewAcquisition(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'1000');
            [$purchases,,,,$cache] = $this->purchaseServices($pdo);
            [$first] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 10]);
            $ids = [];
            for ($i = 0; $i < 40; $i++) $ids[] = $this->cached($cache,$first['id'],"person-$i")['id'];
            $this->assertSame($ids,$cache->eligible('tenant',$first['id'],'cpfcnpj'));
            for ($i = 0; $i < 10; $i++) $purchases->purchaseCached('tenant',$first['id'],$ids[$i]);
            $this->assertSame('60.0000',$purchases->close('tenant',$first['id'])['settledCredits']);
            $next = EnrichmentFixtures::discoveryDefinition('cached-new-price','2026-10-13 00:01:00');
            $next['rates'][14]['credits'] = '7'; $catalog->publish((object) $next,'admin');
            $this->now = '2026-10-13 00:01:00';
            [$second] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 20,'requestKey' => 'next-twenty']);
            $this->assertSame($ids,$cache->eligible('tenant',$second['id'],'cpfcnpj'));
            $free = $purchases->purchaseCached('tenant',$second['id'],$ids[0]);
            $this->assertSame($first['id'],$free['quoteId']);
            for ($i = 10; $i < 30; $i++) {
                $receipt = $purchases->purchaseCached('tenant',$second['id'],$ids[$i]);
                $this->assertSame('7.0000',$receipt['credits']);
                $this->assertSame('2026-10-13 00:00:00',$receipt['acquiredAt']);
                $this->assertStringNotContainsString('Person',json_encode($receipt));
                $this->assertStringNotContainsString('hidden',json_encode($receipt));
            }
            $this->assertSame('140.0000',$purchases->close('tenant',$second['id'])['settledCredits']);
            $review = $this->purchaseServices($pdo)[5];
            $cached = $review->read('tenant',$receipt['id']);
            $this->assertSame('cache',$cached['provenance']['delivery']);
            $this->assertSame($first['id'],$cached['provenance']['acquisitionQuoteId']);
            $this->assertSame(['socios'],$cached['provenance']['requestedProducts']);
            $this->assertFalse($cached['provenance']['refresh']);
            $this->assertSame('live',$review->read('tenant',$free['id'])['provenance']['delivery']);
            $this->assertSame('800.0000',$pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame(40,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_candidate_cache')->fetchColumn());
            $this->assertSame(30,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_purchase')->fetchColumn());
            $this->assertSame(0,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_attempt')->fetchColumn());
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testHiddenExpiryErasesCacheNotPurchasedProjectionAndReplayCannotExtendAcquisition(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'30');
            [$purchases,,,$crypt,$cache] = $this->purchaseServices($pdo);
            [$quote] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 2]);
            $first = $this->cached($cache,$quote['id'],'first',expires: '2026-10-13 00:00:30');
            $second = $this->cached($cache,$quote['id'],'second',expires: '2026-10-13 00:00:30');
            $this->assertSame($first,$this->cached($cache,$quote['id'],'first',expires: '2026-10-13 00:00:30'));
            $cipher = $pdo->query('SELECT payload_ciphertext FROM enrichment_candidate_cache LIMIT 1')->fetchColumn();
            $this->assertStringNotContainsString('hidden@example.test',$cipher);
            $receipt = $purchases->purchaseCached('tenant',$quote['id'],$first['id']);
            $projection = json_decode($crypt->decrypt($pdo->query('SELECT values_ciphertext FROM enrichment_purchase')->fetchColumn()),true);
            $this->assertArrayNotHasKey('incidental',$projection); $this->assertArrayNotHasKey('email',$projection);
            $this->now = '2026-10-13 00:00:30';
            try { $purchases->purchaseCached('tenant',$quote['id'],$second['id']); $this->fail('Expired hidden acquisition purchased.'); } catch (Conflict) {}
            $this->assertSame([],$cache->eligible('tenant',$quote['id'],'cpfcnpj'));
            $this->assertSame(2,$cache->purge('tenant')); $this->assertSame(0,$cache->purge('tenant'));
            $this->assertSame($receipt,$purchases->purchaseCached('tenant',$quote['id'],$first['id']));
            $this->assertSame($first,$this->cached($cache,$quote['id'],'first',expires: '2026-10-13 00:00:30'));
            $this->assertSame(0,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_candidate_cache WHERE payload_ciphertext IS NOT NULL')->fetchColumn());
            try { $this->cached($cache,$quote['id'],'first',expires: '2026-10-15 00:00:00'); $this->fail('Replay extended retention.'); } catch (Conflict) {}
            $this->assertSame('6.0000',$purchases->close('tenant',$quote['id'])['settledCredits']);
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testUsefulSupplementWaitsForDistinctPeopleAndRepeatedHiddenOrConflictingDataCannotCharge(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'30');
            [$purchases,,,,$cache] = $this->purchaseServices($pdo);
            [$quote] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 2,'waterfall' => true,'budget' => '24']);
            $first = $this->cached($cache,$quote['id'],'first',['cpf' => '52998224725']);
            $receipt = $purchases->purchaseCached('tenant',$quote['id'],$first['id']);
            $repeat = $this->cached($cache,$quote['id'],'repeat',['cpf' => '52998224725','role' => ' Administrator '],'hub');
            $this->assertSame($receipt,$purchases->purchaseCached('tenant',$quote['id'],$repeat['id']));
            $extra = $this->cached($cache,$quote['id'],'extra',['cpf' => '52998224725','ownershipPercentage' => '25.0'],'hub');
            try { $purchases->purchaseCached('tenant',$quote['id'],$extra['id']); $this->fail('Supplement displaced new people.'); } catch (Conflict) {}
            $second = $this->cached($cache,$quote['id'],'second');
            $purchases->purchaseCached('tenant',$quote['id'],$second['id']);
            $purchases->purchaseCached('tenant',$quote['id'],$extra['id']);
            $conflict = $this->cached($cache,$quote['id'],'conflict',['cpf' => '11144477735'],person: 'first');
            try { $purchases->purchaseCached('tenant',$quote['id'],$conflict['id']); $this->fail('Changed supplier person auto-merged.'); } catch (Conflict) {}
            $this->assertSame(['candidate','candidate','supplement'],
                $pdo->query('SELECT purchase_kind FROM enrichment_purchase ORDER BY purchase_kind')->fetchAll(PDO::FETCH_COLUMN));
            $this->assertSame('18.0000',$purchases->close('tenant',$quote['id'])['settledCredits']);
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testCacheFencesAccountIdentityCapabilityDeadlineAndRollback(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes,Catalog $catalog) use ($dialect): void {
            $this->discoveryCatalog($pdo,$catalog); $reservations = $this->fundAdmission($pdo,'60');
            [$purchases,,,,$cache] = $this->purchaseServices($pdo);
            [$quote] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 2]);
            $saved = $this->cached($cache,$quote['id'],'first');
            $this->available = false;
            try { $this->cached($cache,$quote['id'],'second'); $this->fail('Unverified capability stored data.'); } catch (Conflict) {}
            try { $purchases->purchaseCached('tenant',$quote['id'],$saved['id']); $this->fail('Unverified capability purchased data.'); } catch (Conflict) {}
            $this->available = true;
            $pdo->exec("UPDATE account SET cnpj='04252011000110'");
            try { $cache->eligible('tenant',$quote['id'],'cpfcnpj'); $this->fail('Changed identity reused cache.'); } catch (Conflict) {}
            [$changed] = $this->fundedDiscovery($pdo,$quotes,$reservations,['count' => 1,'requestKey' => 'changed-identity']);
            try { $purchases->purchaseCached('tenant',$changed['id'],$saved['id']); $this->fail('Cache transferred across company identities.'); } catch (NotFound) {}
            $purchases->close('tenant',$changed['id']);
            $pdo->exec("UPDATE account SET cnpj='11222333000181'");
            $pdo->exec("ALTER TABLE credit_transaction ADD CONSTRAINT cache_debit_failure CHECK (type<>'debit')");
            try { $purchases->purchaseCached('tenant',$quote['id'],$saved['id']); $this->fail('Injected debit failure ignored.'); } catch (PDOException) {}
            finally { $pdo->exec('ALTER TABLE credit_transaction DROP ' . self::dropCheckKeyword($pdo) . ' cache_debit_failure'); }
            $this->assertSame(0,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_purchase')->fetchColumn());
            $this->assertSame(1,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_candidate_cache')->fetchColumn());
            $this->now = '2026-10-13 00:05:00';
            try { $this->cached($cache,$quote['id'],'late'); $this->fail('Late result retained.'); } catch (Conflict) {}
            try { $purchases->purchaseCached('tenant',$quote['id'],$saved['id']); $this->fail('Late result purchased.'); } catch (Conflict) {}
            $this->assertSame('0.0000',$purchases->close('tenant',$quote['id'])['settledCredits']);
            $this->assertAdmissionAudit($pdo);
        });
    }
}
