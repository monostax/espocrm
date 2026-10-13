<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Utils\{Config,Crypt};
use Espo\Modules\FeatureCredits\Accounting\{Clock,FixedSettlement,Funding,WalletLock};
use Espo\Modules\FeatureCredits\Enrichment\{ActorAccess,Execution,Quotes};
use Espo\Modules\FeatureCredits\Enrichment\Providers\{Registry,Result,Supplier};
use Espo\ORM\EntityManager;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

trait EnrichmentExecutionCases
{
    private array $supplierResults = [];
    private array $supplierCalls = [];

    private function executor(PDO $pdo): Execution
    {
        $manager = $this->createStub(EntityManager::class); $manager->method('getPDO')->willReturn($pdo);
        $clock = $this->createStub(Clock::class); $clock->method('now')->willReturnCallback(fn () => $this->now);
        $lock = new WalletLock($manager,$clock);
        $actors = $this->createStub(ActorAccess::class);
        $actors->method('forActor')->willReturnCallback(function ($actor) {
            $this->assertSame('user',$actor);
            return $this->executionAccess;
        });
        $registry = $this->createStub(Registry::class);
        $registry->method('get')->willReturnCallback(function ($provider): Supplier {
            $supplier = $this->createMock(Supplier::class);
            $supplier->method('revision')->willReturnCallback(fn () => $this->available ? $this->revision : null);
            $supplier->method('lookup')->willReturnCallback(function ($type,$product,$identity,$timeout) use ($provider): Result {
                $this->assertGreaterThan(0,$timeout); $this->assertLessThanOrEqual(30000,$timeout);
                $this->supplierCalls[] = [$provider,$product,'lookup'];
                return array_shift($this->supplierResults) ?? new Result('no_match');
            });
            $supplier->method('poll')->willReturnCallback(function ($request,$subject,$timeout) use ($provider): Result {
                $this->supplierCalls[] = [$provider,'phone','poll'];
                return array_shift($this->supplierResults) ?? new Result('pending',requestId: $request,subjectId: $subject);
            });
            return $supplier;
        });
        $config = $this->createStub(Config::class); $config->method('get')->willReturn('enrichment-test-encryption-key');
        return new Execution($manager,$lock,$actors,$registry,new FixedSettlement(new Funding($lock)),new Crypt($config),$clock);
    }

    private function assertExecuted(PDO $pdo, string $credits): void
    {
        $this->assertSame('settled',$pdo->query('SELECT state FROM credit_usage')->fetchColumn());
        $this->assertSame($credits,$pdo->query('SELECT settled_credits FROM credit_usage')->fetchColumn());
        $this->assertSame('0.0000',$pdo->query('SELECT reserved_credits FROM tenant_credit_balance')->fetchColumn());
        $this->assertSame(0,(int) $pdo->query('SELECT COUNT(*) FROM credit_request')->fetchColumn(),'No artificial token metering.');
        $this->assertAdmissionAudit($pdo);
    }

    #[DataProvider('dialects')]
    public function testDurableWaterfallKeepsSharedCapAndAtomicallyPurchasesSuccessfulSibling(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes): void {
            $this->fundAdmission($pdo); $quote = $this->accepted($quotes);
            $quotes->admit('tenant',$quote['id']); $execution = $this->executor($pdo);
            $this->supplierResults = [new Result('no_match'),new Result('match',['found@example.test']),new Result('ambiguous')];
            $execution->run('tenant',$quote['id']);
            // Email: cpfcnpj no-match, Hub 7.5 winner. Remaining 0.5 cannot afford any phone provider.
            $this->assertSame([['cpfcnpj','email','lookup'],['hub','email','lookup']],$this->supplierCalls);
            $this->assertExecuted($pdo,'7.5000');
            $this->assertSame(1,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_attempt WHERE purchased_at IS NOT NULL')->fetchColumn());
            $stored = $pdo->query("SELECT result_ciphertext FROM enrichment_attempt WHERE state='matched'")->fetchColumn();
            $this->assertStringNotContainsString('found@example.test',$stored);
            $execution->run('tenant',$quote['id']);
            $this->assertCount(2,$this->supplierCalls);
            $this->assertSame(1,(int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type='debit'")->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testSuccessfulEmailSurvivesAmbiguousPhoneWithoutFallbackOrRedispatch(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes): void {
            $this->fundAdmission($pdo); $quote = $this->accepted($quotes); $quotes->admit('tenant',$quote['id']);
            $execution = $this->executor($pdo);
            $this->supplierResults = [new Result('match',['email@example.test']),new Result('ambiguous')];
            $execution->run('tenant',$quote['id']);
            $this->assertExecuted($pdo,'2.7000');
            $this->assertSame([['cpfcnpj','email','lookup'],['cpfcnpj','phone','lookup']],$this->supplierCalls);
            $this->assertSame(['matched','waived'],$pdo->query('SELECT state FROM enrichment_attempt ORDER BY product')->fetchAll(PDO::FETCH_COLUMN));
        });
    }

    #[DataProvider('dialects')]
    public function testLostDispatchExpiresAndLateResultCannotPurchaseOrReopen(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes): void {
            $reservations = $this->fundAdmission($pdo); $quote = $this->accepted($quotes,['products' => ['email']]);
            $hold = $quotes->admit('tenant',$quote['id']); $execution = $this->executor($pdo);
            $claim = $execution->claim('tenant',$quote['id']);
            $this->assertNotNull($claim); $this->assertNull($execution->claim('tenant',$quote['id']));
            try { $reservations->release('tenant',$hold['usageId'],'enrichment:' . $quote['id']); $this->fail('Claimed supplier work released as unstarted.'); } catch (Conflict) {}
            $this->now = '2026-10-12 00:00:30';
            $this->assertNull($execution->dispatchWindow('tenant',$quote['id'],$claim['id'],$claim['token']));
            $execution->claim('tenant',$quote['id'],false);
            $this->assertExecuted($pdo,'0.0000');
            $execution->record('tenant',$quote['id'],$claim['id'],$claim['token'],new Result('match',['late@example.test']));
            $this->assertSame(0,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_attempt WHERE result_ciphertext IS NOT NULL')->fetchColumn());
            $execution->run('tenant',$quote['id']); $this->assertSame([],$this->supplierCalls);
        });
    }

    #[DataProvider('dialects')]
    public function testApolloPollingHasFiveMinuteDeadlineAndPreservesLargeRequestIdentity(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes): void {
            $this->fundAdmission($pdo); $quote = $this->accepted($quotes,['products' => ['phone'],'waterfall' => false,'budget' => null]);
            $quotes->admit('tenant',$quote['id']); $execution = $this->executor($pdo);
            $this->supplierResults = [new Result('pending',requestId: '9223372036854775807',subjectId: 'person')];
            $execution->run('tenant',$quote['id']);
            $this->assertSame('2026-10-12 00:05:00',$pdo->query('SELECT deadline_at FROM enrichment_attempt')->fetchColumn());
            $this->assertSame('9223372036854775807',$pdo->query('SELECT request_id FROM enrichment_attempt')->fetchColumn());
            $this->now = '2026-10-12 00:00:10';
            $claim = $execution->claim('tenant',$quote['id']);
            $this->assertSame('poll',$claim['action']); $this->assertNull($execution->claim('tenant',$quote['id']));
            $this->now = '2026-10-12 00:05:00';
            $execution->claim('tenant',$quote['id'],false);
            $execution->record('tenant',$quote['id'],$claim['id'],$claim['token'],new Result('match',['+14155550164']));
            $this->assertExecuted($pdo,'0.0000'); $this->assertCount(1,$this->supplierCalls);
            $this->assertSame('waived',$pdo->query('SELECT state FROM enrichment_attempt')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testPendingPollSuccessUsesOriginalRateAndOldLeaseCannotOverwrite(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes): void {
            $this->fundAdmission($pdo); $quote = $this->accepted($quotes,['products' => ['phone'],'waterfall' => false,'budget' => null]);
            $quotes->admit('tenant',$quote['id']); $execution = $this->executor($pdo);
            $first = $execution->claim('tenant',$quote['id']);
            $execution->record('tenant',$quote['id'],$first['id'],$first['token'],new Result('pending',requestId: '123',subjectId: 'person'));
            $this->now = '2026-10-12 00:00:10'; $poll = $execution->claim('tenant',$quote['id']);
            $execution->record('tenant',$quote['id'],$first['id'],$first['token'],new Result('match',['+14155550100']));
            $this->assertSame('pending',$pdo->query('SELECT state FROM enrichment_attempt')->fetchColumn());
            $execution->record('tenant',$quote['id'],$poll['id'],$poll['token'],new Result('match',['+14155550164']));
            $execution->claim('tenant',$quote['id'],false);
            $this->assertExecuted($pdo,'8.0000');
        });
    }

    #[DataProvider('dialects')]
    public function testIdentityChangesWaiveUndeliveredResults(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes): void {
            $this->fundAdmission($pdo); $quote = $this->accepted($quotes); $quotes->admit('tenant',$quote['id']);
            $execution = $this->executor($pdo); $claim = $execution->claim('tenant',$quote['id']);
            $execution->record('tenant',$quote['id'],$claim['id'],$claim['token'],new Result('match',['original@example.test']));
            $pdo->exec("UPDATE contact SET first_name='Changed'");
            $execution->run('tenant',$quote['id']);
            $this->assertExecuted($pdo,'0.0000');
            $this->assertNull($pdo->query('SELECT result_ciphertext FROM enrichment_attempt')->fetchColumn());
            $this->assertSame([],$this->supplierCalls);
        });
    }

    #[DataProvider('dialects')]
    public function testSettlementAndAccessWriteFaultRollbackTogetherThenReplayClosesOnce(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes) use ($dialect): void {
            $this->fundAdmission($pdo); $quote = $this->accepted($quotes,['products' => ['email']]); $quotes->admit('tenant',$quote['id']);
            $execution = $this->executor($pdo); $claim = $execution->claim('tenant',$quote['id']);
            $execution->record('tenant',$quote['id'],$claim['id'],$claim['token'],new Result('match',['bought@example.test']));
            $pdo->exec('ALTER TABLE enrichment_attempt ADD CONSTRAINT injected_purchase_failure CHECK (purchased_at IS NULL)');
            try { $execution->claim('tenant',$quote['id'],false); $this->fail('Purchase write failure swallowed.'); } catch (PDOException) {}
            finally { $pdo->exec('ALTER TABLE enrichment_attempt DROP ' . self::dropCheckKeyword($pdo) . ' injected_purchase_failure'); }
            $this->assertSame('admitted',$pdo->query('SELECT state FROM credit_usage')->fetchColumn());
            $this->assertSame('10.0000',$pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame(0,(int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type='debit'")->fetchColumn());
            $execution->claim('tenant',$quote['id'],false); $this->assertExecuted($pdo,'2.7000');
            $this->assertSame([],$this->supplierCalls);
        });
    }

    #[DataProvider('dialects')]
    public function testGrantExpiryRetainsSuccessfulChargeAndExpiresOnlyOriginalUnusedHold(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes): void {
            $this->fundAdmission($pdo,'10','2026-10-12 00:00:20');
            $quote = $this->accepted($quotes,['products' => ['email']]); $quotes->admit('tenant',$quote['id']);
            $execution = $this->executor($pdo); $claim = $execution->claim('tenant',$quote['id']);
            $this->now = '2026-10-12 00:00:21';
            $execution->record('tenant',$quote['id'],$claim['id'],$claim['token'],new Result('match',['bought@example.test']));
            $execution->claim('tenant',$quote['id'],false); $this->assertExecuted($pdo,'2.7000');
            $this->assertSame('0.0000',$pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame('4.8000',$pdo->query('SELECT expired_credits FROM credit_allocation')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testIndependentClaimRecordAndSettlementRacesHaveOneFinancialEffect(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes) use ($dialect): void {
            $this->fundAdmission($pdo); $quote = $this->accepted($quotes,['products' => ['email']]); $quotes->admit('tenant',$quote['id']);
            $payload = ['tenantId' => 'tenant','quoteId' => $quote['id']];
            $results = $this->race($pdo,$dialect,'execution-claim',[$payload,$payload]);
            $claims = array_values(array_filter(array_column($results,'result')));
            $this->assertCount(1,$claims);
            $claim = $claims[0];
            $record = [...$payload,'attemptId' => $claim['id'],'claimToken' => $claim['token']];
            $results = $this->race($pdo,$dialect,'execution-record',[$record,$record]);
            $this->assertSame([['result' => null],['result' => null]],$results);
            $results = $this->race($pdo,$dialect,'execution-run',[$payload,$payload]);
            $this->assertSame([['result' => null],['result' => null]],$results);
            $this->assertExecuted($pdo,'2.7000');
            $this->assertSame(1,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_attempt')->fetchColumn());
            $this->assertSame(1,(int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type='debit'")->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testSigkillAfterProviderReturnWaivesOnRecoveryWithoutResubmission(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes) use ($dialect): void {
            $this->fundAdmission($pdo); $quote = $this->accepted($quotes,['products' => ['email']]); $quotes->admit('tenant',$quote['id']);
            $payload = ['tenantId' => 'tenant','quoteId' => $quote['id']];
            $process = proc_open([PHP_BINARY,__DIR__ . '/fixtures/enrichment-quote-worker.php',$dialect,'execution-hang',
                base64_encode(json_encode($payload,JSON_THROW_ON_ERROR)),$this->now],
                [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']],$pipes);
            $this->assertIsResource($process); stream_set_timeout($pipes[1],15);
            try {
                $this->assertSame("ready\n",fgets($pipes[1]));
                $signal = json_decode(fgets($pipes[1]),true,32,JSON_THROW_ON_ERROR);
                $this->assertTrue($signal['providerReturned']);
                $this->assertTrue(proc_terminate($process,9));
                stream_get_contents($pipes[1]);
                $this->assertSame('',stream_get_contents($pipes[2]));
                $this->now = '2026-10-12 00:00:31';
                $execution = $this->executor($pdo); $execution->claim('tenant',$quote['id'],false);
                $execution->run('tenant',$quote['id']); $this->assertExecuted($pdo,'0.0000');
                $this->assertSame([],$this->supplierCalls);
                $this->assertSame('waived',$pdo->query('SELECT state FROM enrichment_attempt')->fetchColumn());
            } finally {
                if (proc_get_status($process)['running']) proc_terminate($process,9);
                foreach ($pipes as $pipe) fclose($pipe);
                proc_close($process);
            }
        });
    }

    #[DataProvider('dialects')]
    public function testRevokedAccessAndNeverStartedQueueAreClosedByNoIoSweep(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes): void {
            $this->fundAdmission($pdo); $quote = $this->accepted($quotes,['products' => ['email']]); $quotes->admit('tenant',$quote['id']);
            $execution = $this->executor($pdo); $this->allowed = false;
            $execution->claim('tenant',$quote['id'],false); $this->assertExecuted($pdo,'0.0000');
            $this->assertSame([],$this->supplierCalls);
            $this->allowed = true;
            $second = $this->accepted($quotes,['requestKey' => 'queue-expiry','products' => ['email']]); $quotes->admit('tenant',$second['id']);
            $this->now = '2026-10-12 00:05:00';
            $execution->claim('tenant',$second['id'],false);
            $this->assertSame(0,(int) $pdo->query("SELECT COUNT(*) FROM credit_usage WHERE state='admitted'")->fetchColumn());
            $this->assertSame(0,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_attempt')->fetchColumn());
            $this->assertSame('0.0000',$pdo->query('SELECT reserved_credits FROM tenant_credit_balance')->fetchColumn());
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testLinkedinIdentitySelectionIsOwnReadablePrimaryAndImmutableAcrossConfirmation(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes): void {
            $pdo->exec("INSERT INTO contact_channel_identity (id,contact_id,tenant_id,channel_type,source_id,is_primary) VALUES
                ('own','contact','tenant','linkedin','https://linkedin.com/in/person/?trk=example',TRUE),
                ('foreign','contact','other','linkedin','https://linkedin.com/in/foreign',TRUE),
                ('other-contact','another','tenant','linkedin','https://linkedin.com/in/another',TRUE)");
            $target = $this->executionAccess->target($this->input());
            $identity = $this->executionAccess->identity($target);
            $this->assertSame('https://www.linkedin.com/in/person',$identity['linkedinUrl']);
            $this->assertSame('own',$identity['linkedinIdentityId']);
            $quote = $quotes->quote($this->input());
            $this->assertStringNotContainsString('linkedin.com',json_encode($quote));
            $pdo->exec("UPDATE contact_channel_identity SET source_id='https://linkedin.com/in/changed' WHERE id='own'");
            try { $quotes->confirm('tenant',$quote['id']); $this->fail('Changed LinkedIn channel identity confirmed.'); } catch (Conflict) {}
            $this->hiddenIdentityFields = ['ContactChannelIdentity.sourceId'];
            $this->assertArrayNotHasKey('linkedinUrl',$this->executionAccess->identity($target));
            $this->hiddenIdentityFields = [];
            $this->deniedIdentityIds = ['own'];
            $this->assertArrayNotHasKey('linkedinUrl',$this->executionAccess->identity($target));
            $this->deniedIdentityIds = [];
            $pdo->exec("UPDATE contact_channel_identity SET ownership_status='rejected' WHERE id='own'");
            $this->assertArrayNotHasKey('linkedinUrl',$this->executionAccess->identity($target));
            $pdo->exec("UPDATE contact_channel_identity SET ownership_status=NULL WHERE id='own'");
            $pdo->exec("INSERT INTO contact_channel_identity (id,contact_id,tenant_id,channel_type,source_id,is_primary)
                VALUES ('second','contact','tenant','linkedin','https://linkedin.com/in/second',TRUE)");
            $this->assertArrayNotHasKey('linkedinUrl',$this->executionAccess->identity($target),'Ambiguous primaries must not pick arbitrarily.');
        });
    }

    #[DataProvider('dialects')]
    public function testLinkedinMutationHookNormalizesAndIdentityChangeBeforeRevealWaives(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes): void {
            $this->fundAdmission($pdo);
            $pdo->exec("INSERT INTO contact_channel_identity (id,contact_id,tenant_id,channel_type,source_id,is_primary)
                VALUES ('own','contact','tenant','linkedin','https://linkedin.com/in/person',TRUE)");
            $quote = $this->accepted($quotes,['products' => ['email']]); $quotes->admit('tenant',$quote['id']);
            $execution = $this->executor($pdo); $claim = $execution->claim('tenant',$quote['id']);
            $manager = $this->createStub(EntityManager::class); $manager->method('getPDO')->willReturn($pdo);
            $entity = new \Espo\ORM\BaseEntity('ContactChannelIdentity',['attributes' => array_fill_keys(['id','contactId','tenantId','channelType','sourceId','handle'],['type' => 'varchar'])]);
            $entity->set(['id' => 'own','contactId' => 'contact','tenantId' => 'tenant','channelType' => 'linkedin','sourceId' => 'http://br.linkedin.com/in/changed/?trk=example']);
            $hook = new \Espo\Modules\Chatwoot\Hooks\ContactChannelIdentity\LinkedInProfile($manager);
            $pdo->beginTransaction();
            try {
                $hook->beforeSave($entity,[]);
                $this->assertSame('https://www.linkedin.com/in/changed',$entity->get('sourceId'));
                $q = $pdo->prepare('UPDATE contact_channel_identity SET source_id=? WHERE id=?'); $q->execute([$entity->get('sourceId'),'own']);
                $pdo->commit();
            } catch (\Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
            $execution->record('tenant',$quote['id'],$claim['id'],$claim['token'],new Result('match',['late@example.test']));
            $this->assertExecuted($pdo,'0.0000');
            $this->assertNull($pdo->query('SELECT result_ciphertext FROM enrichment_attempt')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testFailureStatusIsNotNoMatchAndPurchasedMaterialSurvivesHiddenExpiryWithoutRepricing(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes): void {
            $this->fundAdmission($pdo);
            $quote = $this->accepted($quotes,['products' => ['email']]); $quotes->admit('tenant',$quote['id']);
            $execution = $this->executor($pdo);
            $this->supplierResults = [new Result('failed'),new Result('failed'),new Result('failed')];
            $execution->run('tenant',$quote['id']);
            $receipt = $quotes->confirm('tenant',$quote['id']);
            $this->assertSame('failed',$receipt['executionProducts']['email']['state']);
            $this->assertCount(3,$receipt['executionProducts']['email']['attempts']);
            $second = $this->accepted($quotes,['requestKey' => 'purchase','products' => ['email']]); $quotes->admit('tenant',$second['id']);
            $this->supplierResults = [new Result('match',['retained@example.test'])];
            $execution->run('tenant',$second['id']);
            $this->assertSame('purchased',$quotes->confirm('tenant',$second['id'])['executionProducts']['email']['state']);
            $this->now = '2027-01-10 00:00:00';
            $execution->expireResults();
            $this->assertSame(1,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_attempt WHERE result_ciphertext IS NOT NULL')->fetchColumn());
            $this->assertSame(1,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_attempt WHERE purchased_at IS NOT NULL')->fetchColumn());
            $this->assertSame('7.3000',$pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            [,,,,,,$history,$review,$erasure] = $this->purchaseServices($pdo);
            $id = $pdo->query('SELECT id FROM enrichment_attempt WHERE purchased_at IS NOT NULL')->fetchColumn();
            $read = $review->read('tenant',$id);
            $this->assertSame(['retained@example.test'],(array) $read['values']);
            $this->assertSame($read['receipt'],$history->read(['tenantId' => 'tenant','targetType' => 'Contact','targetId' => 'contact','product' => 'email'])['list'][0]['receipt']);
            $entity = new \Espo\ORM\BaseEntity('Contact',['attributes' => array_fill_keys(['id','tenantId','cpf'],['type' => 'varchar'])]);
            $entity->set(['id' => 'contact','tenantId' => 'tenant','cpf' => '52998224725']);
            $pdo->beginTransaction(); $erasure->erase($entity); $pdo->commit();
            $erased = $review->read('tenant',$id);
            $this->assertSame($read['receipt'],$erased['receipt']); $this->assertTrue($erased['erased']);
            $this->assertSame([],(array) $erased['values']); $this->assertSame('data_erased',$erased['application']['reason']);
            $this->assertNull($pdo->query('SELECT result_ciphertext FROM enrichment_attempt WHERE purchased_at IS NOT NULL')->fetchColumn());
            $this->assertStringNotContainsString('52998224725',$pdo->query("SELECT snapshot FROM enrichment_quote WHERE request_key='purchase'")->fetchColumn());
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testLinkedinWriterAndDispatchContendOnContactAndFenceChangedOwnerBeforePurchase(string $dialect): void
    {
        $this->database($dialect,function (PDO $pdo,Quotes $quotes) use ($dialect): void {
            $this->fundAdmission($pdo);
            $pdo->exec("INSERT INTO contact_channel_identity (id,contact_id,tenant_id,channel_type,source_id,is_primary)
                VALUES ('own','contact','tenant','linkedin','https://linkedin.com/in/person',TRUE)");
            $quote = $this->accepted($quotes,['products' => ['email']]); $quotes->admit('tenant',$quote['id']);
            $payload = ['tenantId' => 'tenant','quoteId' => $quote['id']];
            $results = $this->race($pdo,$dialect,'execution-claim',[$payload,[...$payload,'testOperation' => 'linkedin-mutate']],lockContact: true);
            $this->assertSame(['result' => 'changed'],$results[1]);
            $this->assertArrayHasKey('result',$results[0]);
            $execution = $this->executor($pdo);
            if ($claim = $results[0]['result']) {
                $execution->record('tenant',$quote['id'],$claim['id'],$claim['token'],new Result('match',['stale-owner@example.test']));
            }
            $execution->claim('tenant',$quote['id'],false);
            $this->assertExecuted($pdo,'0.0000');
            $this->assertSame(0,(int) $pdo->query('SELECT COUNT(*) FROM enrichment_attempt WHERE purchased_at IS NOT NULL')->fetchColumn());
        });
    }
}
