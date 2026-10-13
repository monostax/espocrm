<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\{Conflict,NotFound};
use Espo\Modules\FeatureCredits\Enrichment\{Catalog,Quotes};
use Espo\Modules\FeatureCredits\Enrichment\Providers\Result;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

trait EnrichmentFieldCacheCases
{
    private function hiddenPhone(PDO $pdo, Quotes $quotes, int $retention = 7776000): array
    {
        $this->fundAdmission($pdo,'100');
        $quote = $this->accepted($quotes,['products' => ['email']]);
        $quotes->admit('tenant',$quote['id']);
        $execution = $this->executor($pdo);
        $this->supplierResults = [new Result('match',['visible@example.test'],hidden: ['phone' => ['+5511999998888']],retentionSeconds: $retention)];
        $execution->run('tenant',$quote['id']);
        return [$execution,$quote,$pdo->query('SELECT id FROM enrichment_attempt')->fetchColumn()];
    }

    #[DataProvider('dialects')]
    public function testOrdinaryHiddenCacheDoesNotSkipEarlierProviderOrLeakNoMatchSibling(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo, Quotes $quotes): void {
            $this->fundAdmission($pdo,'100');
            $quote = $this->accepted($quotes,['products' => ['email']]); $quotes->admit('tenant',$quote['id']);
            $execution = $this->executor($pdo);
            $this->supplierResults = [new Result('no_match'),new Result('no_match',hidden: ['phone' => ['+5511999998888']]),new Result('no_match')];
            $execution->run('tenant',$quote['id']);
            $this->assertSame('100.0000',$pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            [,,,,,,$history,$review] = $this->purchaseServices($pdo);
            $id = $pdo->query('SELECT id FROM enrichment_attempt WHERE cache_ciphertext IS NOT NULL')->fetchColumn();
            try { $review->read('tenant',$id); $this->fail('Unpurchased sibling was readable.'); } catch (NotFound) {}
            $this->assertSame([],$history->read(['tenantId' => 'tenant','targetType' => 'Contact','targetId' => 'contact','product' => 'phone'])['list']);
            $later = $this->accepted($quotes,['products' => ['phone'],'requestKey' => 'later']); $quotes->admit('tenant',$later['id']);
            $execution->run('tenant',$later['id']);
            $this->assertSame([['cpfcnpj','email','lookup'],['hub','email','lookup'],['apollo','email','lookup'],['cpfcnpj','phone','lookup']],$this->supplierCalls);
            $this->assertSame('92.5000',$pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testOrdinaryHiddenCacheCorruptionAccessRevocationAndErasureFencePurchase(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo, Quotes $quotes): void {
            [$execution] = $this->hiddenPhone($pdo,$quotes);
            $quote = $this->accepted($quotes,['products' => ['phone'],'requestKey' => 'later']); $quotes->admit('tenant',$quote['id']);
            $pdo->exec("UPDATE enrichment_attempt SET cache_hash='invalid'");
            try { $execution->run('tenant',$quote['id']); $this->fail('Corrupt hidden cache bought.'); } catch (Conflict) {}
            $this->assertCount(1,$this->supplierCalls);
            $this->allowed = false; $execution->run('tenant',$quote['id']); $this->allowed = true;
            $this->assertSame('97.3000',$pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            [,,,,,,,,$erasure] = $this->purchaseServices($pdo);
            $entity = new \Espo\ORM\BaseEntity('Contact',['attributes' => array_fill_keys(['id','tenantId','cpf'],['type' => 'varchar'])]);
            $entity->set(['id' => 'contact','tenantId' => 'tenant','cpf' => '52998224725']);
            $pdo->beginTransaction(); $erasure->erase($entity); $pdo->commit();
            $this->assertSame(0,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_attempt WHERE cache_ciphertext IS NOT NULL')->fetchColumn());
            $execution->run('tenant',$quote['id']);
            $this->assertCount(1,$this->supplierCalls); $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testOrdinaryHiddenPhoneLaterPurchasesAtCurrentPriceWithoutSupplierIo(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo, Quotes $quotes, Catalog $catalog): void {
            [$execution,$original,$source] = $this->hiddenPhone($pdo,$quotes);
            [,,,,,,$history,$review] = $this->purchaseServices($pdo);
            $this->assertStringNotContainsString('+5511999998888',json_encode($review->read('tenant',$source)));
            $this->assertSame([],$history->read(['tenantId' => 'tenant','targetType' => 'Contact','targetId' => 'contact','product' => 'phone'])['list']);
            $this->assertStringNotContainsString('+5511999998888',$pdo->query('SELECT cache_ciphertext FROM enrichment_attempt')->fetchColumn());
            $next = EnrichmentFixtures::definition('new-field-price','2026-10-13 00:00:00');
            foreach ($next['rates'] as &$rate) if ($rate['provider'] === 'cpfcnpj' && $rate['target'] === 'Contact' && $rate['product'] === 'phone') $rate['credits'] = '3.1000';
            unset($rate); $catalog->publish((object) $next,'admin');
            $this->now = '2026-10-13 00:00:00';
            $later = $this->accepted($quotes,['products' => ['phone'],'requestKey' => 'reveal']);
            $quotes->admit('tenant',$later['id']); $execution->run('tenant',$later['id']);
            $this->assertCount(1,$this->supplierCalls);
            $row = $pdo->query('SELECT * FROM enrichment_attempt WHERE source_attempt_id IS NOT NULL')->fetch(PDO::FETCH_ASSOC);
            $read = $review->read('tenant',$row['id']);
            $this->assertSame(['+5511999998888'],(array) $read['values']);
            $this->assertSame('3.1000',$read['receipt']['credits']);
            $this->assertSame('2026-10-12 00:00:00',$read['receipt']['acquiredAt']);
            $this->assertSame('cache',$read['provenance']['delivery']);
            $this->assertSame($original['id'],$read['provenance']['acquisitionQuoteId']);
            $this->assertSame($source,$read['provenance']['sourceAttemptId']);
            $execution->run('tenant',$later['id']);
            $this->assertEquals($read,$review->read('tenant',$row['id']));
            $repeat = $this->accepted($quotes,['products' => ['phone'],'requestKey' => 'already-purchased']);
            $quotes->admit('tenant',$repeat['id']); $execution->run('tenant',$repeat['id']);
            $status = $quotes->confirm('tenant',$repeat['id'])['executionProducts']['phone'];
            $this->assertSame('purchased',$status['state']);
            $this->assertSame('reused',$status['attempts'][0]['state']);
            $this->assertSame($row['id'],$status['attempts'][0]['purchaseId']);
            $this->assertCount(1,$this->supplierCalls);
            $this->assertSame('94.2000',$pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame('0.0000',$pdo->query('SELECT reserved_credits FROM tenant_credit_balance')->fetchColumn());
            $this->now = '2027-01-10 00:00:00'; $execution->expireResults();
            $this->assertSame(0,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_attempt WHERE cache_ciphertext IS NOT NULL')->fetchColumn());
            $this->assertEquals($read,$review->read('tenant',$row['id']));
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testOrdinaryCombinedProductsCacheCheckAndSharedBudget(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo, Quotes $quotes): void {
            $this->fundAdmission($pdo);
            $quote = $this->accepted($quotes); $quotes->admit('tenant',$quote['id']);
            $execution = $this->executor($pdo);
            $this->supplierResults = [new Result('match',['visible@example.test'],hidden: ['phone' => ['+5511999998888']])];
            $execution->run('tenant',$quote['id']);
            $this->assertCount(1,$this->supplierCalls); $this->assertExecuted($pdo,'5.4000');
            $this->assertSame(2,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_attempt WHERE purchased_at IS NOT NULL')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testOrdinaryHiddenCacheFencesRouteRefreshExpiryIdentityRevisionAndTarget(string $dialect): void
    {
        foreach (['route','refresh','expiry','identity','revision','target','type','budget'] as $fence) {
            $this->supplierResults = []; $this->supplierCalls = []; $this->revision = str_repeat('a',64);
            $this->now = '2026-10-11 12:00:00';
            $this->database($dialect,function (PDO $pdo, Quotes $quotes) use ($fence): void {
                [$execution] = $this->hiddenPhone($pdo,$quotes,60);
                $changes = ['products' => ['phone'],'requestKey' => 'later'];
                if ($fence === 'route') $changes += ['waterfall' => false,'budget' => null];
                if ($fence === 'refresh') $changes['refresh'] = true;
                if ($fence === 'expiry') $this->now = '2026-10-12 00:01:00';
                if ($fence === 'identity') $pdo->exec("UPDATE contact SET cpf='11144477735'");
                if ($fence === 'revision') $this->revision = str_repeat('b',64);
                if ($fence === 'target') {
                    $pdo->exec("INSERT INTO contact (id,tenant_id,cpf,first_name,email_address) SELECT 'second',tenant_id,cpf,first_name,email_address FROM contact");
                    $changes['targetId'] = 'second';
                }
                if ($fence === 'type') $changes['targetType'] = 'Account';
                if ($fence === 'budget') {
                    $changes['budget'] = '2';
                    try { $this->accepted($quotes,$changes); $this->fail('Unaffordable cache confirmed.'); } catch (Conflict) {}
                    $this->assertCount(1,$this->supplierCalls);
                    $this->assertAdmissionAudit($pdo);
                    return;
                }
                $quote = $this->accepted($quotes,$changes); $quotes->admit('tenant',$quote['id']);
                $execution->run('tenant',$quote['id']);
                $this->assertSame(0,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_attempt WHERE source_attempt_id IS NOT NULL')->fetchColumn(),$fence);
                $this->assertSame('97.3000',$pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn(),$fence);
                $this->assertAdmissionAudit($pdo);
            });
        }
    }

    #[DataProvider('dialects')]
    public function testOrdinaryCachedRevealRollbackAndIndependentWorkersPurchaseOnce(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo, Quotes $quotes) use ($dialect): void {
            [$execution] = $this->hiddenPhone($pdo,$quotes);
            $quote = $this->accepted($quotes,['products' => ['phone'],'requestKey' => 'reveal']); $quotes->admit('tenant',$quote['id']);
            $pdo->exec('ALTER TABLE enrichment_attempt ADD CONSTRAINT injected_cache_purchase_failure CHECK (source_attempt_id IS NULL OR purchased_at IS NULL)');
            try { $execution->run('tenant',$quote['id']); $this->fail('Expected access write failure.'); } catch (PDOException) {}
            finally { $pdo->exec('ALTER TABLE enrichment_attempt DROP ' . self::dropCheckKeyword($pdo) . ' injected_cache_purchase_failure'); }
            $this->assertSame(0,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_attempt WHERE source_attempt_id IS NOT NULL')->fetchColumn());
            $this->assertSame('97.3000',$pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            $payload = ['tenantId' => 'tenant','quoteId' => $quote['id']];
            $this->assertSame([['result' => null],['result' => null]],$this->race($pdo,$dialect,'cached-execution-run',[$payload,$payload]));
            $this->assertSame(1,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_attempt WHERE source_attempt_id IS NOT NULL AND purchased_at IS NOT NULL')->fetchColumn());
            $this->assertSame('94.6000',$pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            $this->assertAdmissionAudit($pdo);
        });
    }
}
