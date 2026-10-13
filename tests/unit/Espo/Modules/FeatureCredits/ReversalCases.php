<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\{Conflict, NotFound};
use Espo\Modules\FeatureCredits\Accounting\{Funding, NativeSearchDebt, OutcomeInput, Outcomes, Requests, ReversalInput, Reversals, Settlements};
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

trait ReversalCases
{
    private function reversalFixture(PDO $pdo, ?string $expires = null): array
    {
        $reservations = $this->reservations($pdo);
        $funding = new Funding($this->lock($pdo));
        $funding->grant($this->input('opening', '10', expires: $expires));
        $usage = $reservations->reserve($this->admission())['usageId'];
        $request = (new Requests($this->lock($pdo), $funding))->authorize($this->request($usage))['requestId'];
        (new Outcomes($this->lock($pdo)))->record(new OutcomeInput('tenant', $usage, 'execution', $request,
            'success', 0, 0, 1000, (object) ['source' => 'provider']));
        $settled = (new Settlements($this->lock($pdo), $funding))->settle('tenant', $usage, 'execution');
        $debit = $pdo->query("SELECT * FROM credit_transaction WHERE type = 'debit'")->fetch(PDO::FETCH_ASSOC);
        return [new Reversals($this->lock($pdo), $funding), $debit, $settled, $funding];
    }

    #[DataProvider('dialects')]
    public function testReversalMixedExpiryLotsRetainOriginalAllocationAndExistingHolds(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input('monthly', '0.1', expires: '2026-10-11 00:00:00'));
            $funding->grant($this->input('purchase', '10'));
            $usage = $reservations->reserve($this->admission())['usageId'];
            $request = (new Requests($this->lock($pdo), $funding))->authorize($this->request($usage))['requestId'];
            (new Outcomes($this->lock($pdo)))->record(new OutcomeInput('tenant', $usage, 'execution', $request,
                'success', 0, 0, 1000, (object) ['source' => 'provider']));
            (new Settlements($this->lock($pdo), $funding))->settle('tenant', $usage, 'execution');
            $reservations->reserve($this->admission('another-hold', '2'));
            $debits = $pdo->query("SELECT * FROM credit_transaction WHERE type='debit' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            $this->assertCount(2, $debits);
            $this->now = '2026-10-11 00:00:00';
            $service = new Reversals($this->lock($pdo), $funding);
            foreach ($debits as $debit) {
                $amount = (string) \Espo\Modules\FeatureCredits\Accounting\Amount::fromString('0')
                    ->minus(\Espo\Modules\FeatureCredits\Accounting\Amount::fromString($debit['credits']));
                $receipt = $service->reverse(new ReversalInput('tenant', $debit['id'], 'lot:' . $debit['id'], $amount, 'Correction', 'admin'));
                $this->assertSame($debit['grant_id'], $receipt['grantId']);
                $this->assertSame($debit['allocation_id'], $receipt['allocationId']);
                $this->assertSame($amount === '0.1000' ? '0.1000' : '0.0000', $receipt['expiredCredits']);
            }
            $this->assertConsistent($pdo, '10.0000');
            $this->assertHeld($pdo, '2.0000');
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testReversalRejectsNonDebitsOpenUsageAndBrokenAllocation(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$service, $debit] = $this->reversalFixture($pdo);
            $grant = $pdo->query("SELECT id FROM credit_transaction WHERE type='grant'")->fetchColumn();
            try { $service->reverse(new ReversalInput('tenant', $grant, 'not-debit', '0.1', 'Correction', 'admin')); $this->fail('Grant reversed as usage.'); }
            catch (Conflict) {}
            foreach ([['credit_usage', 'state', 'admitted', $debit['usage_id']],
                ['credit_allocation', 'debit_transaction_id', 'missing', $debit['allocation_id']]] as [$table, $field, $value, $id]) {
                $q = $pdo->prepare("SELECT $field FROM $table WHERE id = ?"); $q->execute([$id]); $original = $q->fetchColumn();
                $q = $pdo->prepare("UPDATE $table SET $field = ? WHERE id = ?"); $q->execute([$value, $id]);
                $before = $this->auditSnapshot($pdo);
                try { $service->reverse(new ReversalInput('tenant', $debit['id'], 'broken', '0.1', 'Correction', 'admin')); $this->fail('Invalid allocation reversed.'); }
                catch (Conflict) { $this->assertSame($before, $this->auditSnapshot($pdo)); }
                $q->execute([$original, $id]);
            }
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testReversalPartialReplayCapIsolationAndOriginalSettlement(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$service, $debit, $settled, $funding] = $this->reversalFixture($pdo);
            $input = new ReversalInput('tenant', $debit['id'], 'correct-1', '0.1', 'Correction', 'admin');
            $receipt = $service->reverse($input);
            $this->assertSame($receipt, $service->reverse(new ReversalInput('tenant', $debit['id'], 'correct-1', '0.1000', 'Correction', 'admin')));
            $this->assertSame($debit['allocation_id'], $receipt['allocationId']);
            $this->assertSame('0.0000', $receipt['expiredCredits']);
            $this->assertConsistent($pdo, '9.9125');
            foreach ([['correct-1', '0.1', 'Changed', 'admin'], ['correct-1', '0.1', 'Correction', 'other-admin'],
                ['correct-1', '0.11', 'Correction', 'admin'], ['correct-2', '0.0876', 'Correction', 'admin']] as [$key, $amount, $reason, $actor]) {
                try { $service->reverse(new ReversalInput('tenant', $debit['id'], $key, $amount, $reason, $actor)); $this->fail('Conflicting reversal accepted.'); }
                catch (Conflict) {}
            }
            try { $service->reverse(new ReversalInput('other', $debit['id'], 'foreign', '0.1', 'Correction', 'admin')); $this->fail('Foreign debit accepted.'); }
            catch (NotFound) {}
            $service->reverse(new ReversalInput('tenant', $debit['id'], 'correct-2', '0.0875', 'Correction', 'admin'));
            $this->assertConsistent($pdo, '10.0000');
            $this->assertSame($settled, (new Settlements($this->lock($pdo), $funding))->settle('tenant', $debit['usage_id'], 'execution'));
            $this->assertSame($debit, $pdo->query("SELECT * FROM credit_transaction WHERE type='debit'")->fetch(PDO::FETCH_ASSOC));
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testReversalExpiredOriginalGrantExpiresAtomicallyWithImmutableReplay(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$service, $debit, , $funding] = $this->reversalFixture($pdo, '2026-10-11 00:00:00');
            $this->now = '2026-10-11 00:00:00';
            $funding->grant($this->input('purchase', '7'));
            $input = new ReversalInput('tenant', $debit['id'], 'expired', '0.1875', 'Correction', 'admin');
            $receipt = $service->reverse($input);
            $this->assertSame('0.1875', $receipt['expiredCredits']);
            $this->assertNotNull($receipt['expirationTransactionId']);
            $this->assertConsistent($pdo, '7.0000');
            $this->assertSame('0.0000', $pdo->query("SELECT remaining_credits FROM credit_grant WHERE source_key='opening'")->fetchColumn());
            $before = $this->auditSnapshot($pdo);
            $this->now = '2026-11-12 00:00:00';
            $this->assertSame($receipt, $service->reverse($input));
            $this->assertSame($before, $this->auditSnapshot($pdo));
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testReversalExpiredRestorationDoesNotRepayDebtButLiveRestorationDoes(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [, $funding, $usage] = $this->nativeDebtFixture($pdo);
            (new Settlements($this->lock($pdo), $funding))->settle('tenant', $usage, 'execution');
            $debit = $pdo->query("SELECT id FROM credit_transaction WHERE type='debit'")->fetchColumn();
            $service = new Reversals($this->lock($pdo), $funding);
            $service->reverse(new ReversalInput('tenant', $debit, 'live', '0.01', 'Correction', 'admin'));
            $this->assertSame('4.9900', (string) NativeSearchDebt::outstanding($pdo, 'tenant'));
            $this->now = '2026-10-11 00:00:00';
            $service->reverse(new ReversalInput('tenant', $debit, 'expired', '0.01', 'Correction', 'admin'));
            $this->assertSame('4.9900', (string) NativeSearchDebt::outstanding($pdo, 'tenant'));
            $this->assertSame('-4.9900', $this->balanceStatus($pdo)->inspect('tenant')['balance']);
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testReversalWriteFailureRollsBackAndRetrySucceeds(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            [$service, $debit] = $this->reversalFixture($pdo, '2026-10-11 00:00:00');
            $this->now = '2026-10-11 00:00:00';
            $input = new ReversalInput('tenant', $debit['id'], 'fault', '0.1875', 'Correction', 'admin');
            foreach (['credit_transaction' => "type <> 'expiration' OR reversal_of_id IS NULL",
                'credit_grant' => 'remaining_credits > 0', 'tenant_credit_balance' => 'balance > 0'] as $table => $check) {
                $before = $this->auditSnapshot($pdo);
                $pdo->exec("ALTER TABLE $table ADD CONSTRAINT injected_reversal_failure CHECK ($check)");
                try { $service->reverse($input); $this->fail('Partial reversal committed.'); }
                catch (PDOException) { $this->assertSame($before, $this->auditSnapshot($pdo)); }
                finally { $pdo->exec("ALTER TABLE $table DROP " . self::dropCheckKeyword($pdo) . ' injected_reversal_failure'); }
            }
            $service->reverse($input);
            $this->assertConsistent($pdo, '0.0000');
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testReversalControllerUsesAuthenticatedActorAndAuditDetectsBrokenLinks(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$service, $debit] = $this->reversalFixture($pdo, '2026-10-11 00:00:00');
            $this->now = '2026-10-11 00:00:00';
            $user = $this->createStub(\Espo\Entities\User::class);
            $user->method('isAdmin')->willReturn(true);
            $user->method('getId')->willReturn('trusted-admin');
            $request = $this->createStub(\Espo\Core\Api\Request::class);
            $request->method('getBodyContents')->willReturn(json_encode(['tenantId' => 'tenant', 'transactionId' => $debit['id'],
                'credits' => '0.1875', 'idempotencyKey' => 'controller', 'reason' => 'Correction'], JSON_THROW_ON_ERROR));
            $controller = new \Espo\Modules\FeatureCredits\Controllers\CreditReversals($user, $service);
            $receipt = $controller->postActionReverse($request, $this->createStub(\Espo\Core\Api\Response::class));
            $this->assertEquals($receipt, $controller->postActionReverse($request, $this->createStub(\Espo\Core\Api\Response::class)));
            $this->assertSame('trusted-admin', $pdo->query("SELECT actor_id FROM credit_transaction WHERE type='reversal'")->fetchColumn());
            $this->assertAuditHealthy($pdo);
            $pdo->prepare('UPDATE credit_transaction SET reversal_of_id = ? WHERE id = ?')->execute(['missing', $receipt->expirationTransactionId]);
            $codes = array_column($this->audit($pdo)->inspect('tenant')['findings'], 'code');
            $this->assertContains('transaction.reversal', $codes);
            $this->assertContains('transaction.reversal_expiry', $codes);
            $pdo->prepare('UPDATE credit_transaction SET reversal_of_id = ? WHERE id = ?')->execute([$receipt->transactionId, $receipt->expirationTransactionId]);
            $pdo->prepare('UPDATE credit_transaction SET credits = ? WHERE id = ?')->execute(['0.1876', $receipt->transactionId]);
            $this->assertContains('transaction.reversal', array_column($this->audit($pdo)->inspect('tenant')['findings'], 'code'));
        });
    }

    #[DataProvider('dialects')]
    public function testReversalRacesExpirationRenewalAndNewHold(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            [, $debit, , $funding] = $this->reversalFixture($pdo, '2026-10-11 00:00:00');
            $funding->grant($this->input('purchase', '5'));
            $this->now = '2026-10-11 00:00:00';
            $results = $this->race($pdo, $dialect, [
                ['reverse', $debit['id'], '0.1875', 'expire-race'], ['expire', '', '', ''],
                ['grant', 'renewal', '3', '2026-11-11 00:00:00'], ['reserve', 'new-hold', '2', ''],
            ]);
            $this->assertCount(4, array_column($results, 'result'));
            $this->assertSame('0.1875', $results[0]['result']['expiredCredits']);
            $this->assertConsistent($pdo, '8.0000');
            $this->assertHeld($pdo, '2.0000');
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testReversalIndependentProcessReplayAndCompetingKeysCannotOverRestore(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            [, $debit] = $this->reversalFixture($pdo);
            $results = $this->race($pdo, $dialect, [
                ['reverse', $debit['id'], '0.1', 'same'], ['reverse', $debit['id'], '0.1', 'same'],
            ]);
            $this->assertArrayHasKey('result', $results[0]);
            $this->assertSame($results[0], $results[1]);
            $results = $this->race($pdo, $dialect, [
                ['reverse', $debit['id'], '0.0875', 'a'], ['reverse', $debit['id'], '0.0875', 'b'],
            ]);
            $this->assertCount(1, array_column($results, 'result'));
            $this->assertSame([Conflict::class], array_values(array_column($results, 'error')));
            $this->assertConsistent($pdo, '10.0000');
            $this->assertAuditHealthy($pdo);
        });
    }
}
