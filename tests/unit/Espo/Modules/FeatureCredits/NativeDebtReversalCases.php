<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\{Conflict, NotFound};
use Espo\Modules\FeatureCredits\Accounting\{Amount, Funding, NativeSearchDebt, OutcomeInput, Outcomes, PurchaseReversals, RequestInput, ReversalInput, Reversals, Settlements, TransactionHistory, TransactionHistoryQuery, WalletDebt};
use Espo\Modules\FeatureCredits\Pricing\AiRate;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

trait NativeDebtReversalCases
{
    private function nativeCorrectionFixture(PDO $pdo): array
    {
        [$reservations, $funding, $usage, $requests] = $this->nativeDebtFixture($pdo);
        $settled = (new Settlements($this->lock($pdo), $funding))->settle('tenant', $usage, 'execution');
        $debit = $pdo->query("SELECT * FROM credit_transaction WHERE type='nativeSearchOverrun'")->fetch(PDO::FETCH_ASSOC);
        return [new Reversals($this->lock($pdo), $funding), $debit, $funding, $settled, $reservations, $requests];
    }

    private function nativeCorrection(array $debit, string $credits, string $key = 'native-correction'): ReversalInput
    { return new ReversalInput('tenant', $debit['id'], $key, $credits, 'Approved native correction', 'admin'); }

    #[DataProvider('dialects')]
    public function testNativeCorrectionCancelsUnpaidDebtOnceWithoutChangingOriginalUsage(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$service, $debit, $funding, $settled] = $this->nativeCorrectionFixture($pdo);
            $input = $this->nativeCorrection($debit, '2');
            $receipt = $service->reverse($input);
            $this->assertSame('2.0000', $receipt['unpaidCredits']); $this->assertSame('0.0000', $receipt['restoredCredits']);
            $this->assertSame([], $receipt['restorations']);
            $this->assertSame('3.0000', (string) NativeSearchDebt::outstanding($pdo, 'tenant'));
            $this->assertSame('-3.0000', $this->balanceStatus($pdo)->inspect('tenant')['balance']);
            $snapshot = $this->auditSnapshot($pdo);
            $this->assertSame($receipt, $service->reverse($input)); $this->assertSame($snapshot, $this->auditSnapshot($pdo));
            foreach ([new ReversalInput('tenant', $debit['id'], 'native-correction', '2', 'Changed', 'admin'),
                new ReversalInput('tenant', $debit['id'], 'native-correction', '2', 'Approved native correction', 'other-admin')] as $changed) {
                try { $service->reverse($changed); $this->fail('Changed command accepted.'); } catch (Conflict) {}
            }
            $service->reverse($this->nativeCorrection($debit, '3', 'rest-unpaid'));
            $this->assertConsistent($pdo, '0.0000');
            $funding->grant($this->input('after-correction', '4')); $this->assertConsistent($pdo, '4.0000');
            $this->assertSame($settled, (new Settlements($this->lock($pdo), $funding))->settle('tenant', $debit['usage_id'], 'execution'));
            $this->assertSame($debit, $pdo->query("SELECT * FROM credit_transaction WHERE type='nativeSearchOverrun'")->fetch(PDO::FETCH_ASSOC));
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testNativeCorrectionRestoresFullyRepaidDebtToMixedOriginalExpiryGrants(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$service, $debit, $funding] = $this->nativeCorrectionFixture($pdo);
            $amount = (string) Amount::fromString('0')->minus(Amount::fromString($debit['credits']));
            $later = $funding->grant($this->input('later-month', '5', expires: '2026-10-12 00:00:00'));
            $this->now = '2026-10-11 00:00:00';
            $receipt = $service->reverse($this->nativeCorrection($debit, $amount));
            $this->assertSame('0.0000', $receipt['unpaidCredits']); $this->assertSame($amount, $receipt['restoredCredits']);
            $this->assertSame((string) Amount::fromString($amount)->minus(Amount::fromString('5')), $receipt['expiredCredits']);
            $parts = array_column($receipt['restorations'], null, 'grantId'); $this->assertCount(2, $parts);
            $this->assertSame('5.0000', $parts[$later['grantId']]['credits']);
            $this->assertNull($parts[$later['grantId']]['expirationTransactionId']);
            $this->assertSame('2026-10-12 00:00:00', $parts[$later['grantId']]['expiresAt']);
            $this->assertConsistent($pdo, '5.0000'); $this->assertAuditHealthy($pdo);
            $before = $this->auditSnapshot($pdo); $this->now = '2027-01-01 00:00:00';
            $this->assertSame($receipt, $service->reverse($this->nativeCorrection($debit, $amount)));
            $this->assertSame($before, $this->auditSnapshot($pdo));
            $funding->expire('tenant'); $this->assertConsistent($pdo, '0.0000'); $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testNativeCorrectionAfterAllRepaymentGrantsExpireCreatesNoSpendableReplacement(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$service, $debit, $funding] = $this->nativeCorrectionFixture($pdo);
            $funding->grant($this->input('same-expiry', '5', expires: '2026-10-11 00:00:00'));
            $this->now = '2026-10-11 00:00:00';
            $amount = (string) Amount::fromString('0')->minus(Amount::fromString($debit['credits']));
            $receipt = $service->reverse($this->nativeCorrection($debit, $amount));
            $this->assertSame($amount, $receipt['expiredCredits']);
            foreach ($receipt['restorations'] as $part) $this->assertNotNull($part['expirationTransactionId']);
            $this->assertConsistent($pdo, '0.0000');
            $this->assertSame('0.0000', (string) NativeSearchDebt::outstanding($pdo, 'tenant'));
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testNativeCorrectionCombinesUnpaidAndPaidPortionsAndPreservesUnrelatedHolds(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$service, $debit, $funding, , $reservations] = $this->nativeCorrectionFixture($pdo);
            $funding->grant($this->input('partial', '2'));
            $receipt = $service->reverse($this->nativeCorrection($debit, '4'));
            $this->assertSame('3.0000', $receipt['unpaidCredits']); $this->assertSame('1.0000', $receipt['restoredCredits']);
            $this->assertConsistent($pdo, '1.0000');
            $reservations->reserve($this->admission('unrelated-held', '1'));
            $held = $pdo->query('SELECT * FROM credit_allocation WHERE settled_at IS NULL ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            $service->reverse($this->nativeCorrection($debit, '1', 'further-paid-correction'));
            $this->assertHeld($pdo, '1.0000'); $this->assertConsistent($pdo, '2.0000');
            $this->assertSame($held, $pdo->query('SELECT * FROM credit_allocation WHERE settled_at IS NULL ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testNativeCorrectionLiveRestorationRepaysAnotherOverrunWithoutReopeningOriginalDebt(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$service, $debit, $funding, , $reservations, $requests] = $this->nativeCorrectionFixture($pdo);
            $funding->grant($this->input('repay-and-next-hold', '7'));
            $usage = $reservations->reserve($this->admission('second-native'))['usageId'];
            $request = $requests->authorize(new RequestInput('tenant', $usage, 'execution', 'next-native',
                new AiRate('native-test-v1', 'google', 'gemini-3.8-flash', '1'), 1000, 1, NativeSearchDebt::PROFILE))['requestId'];
            (new Outcomes($this->lock($pdo)))->record(new OutcomeInput('tenant', $usage, 'execution', $request, 'success', 400000, 0, 0, (object) ['source' => 'provider']));
            (new Settlements($this->lock($pdo), $funding))->settle('tenant', $usage, 'execution');
            $this->assertSame('13.0000', (string) NativeSearchDebt::outstanding($pdo, 'tenant'));
            $receipt = $service->reverse($this->nativeCorrection($debit, '1'));
            $this->assertSame('0.0000', $receipt['unpaidCredits']); $this->assertSame('1.0000', $receipt['restoredCredits']);
            $this->assertSame('12.0000', (string) NativeSearchDebt::outstanding($pdo, 'tenant'));
            $this->assertSame('-12.0000', $this->balanceStatus($pdo)->inspect('tenant')['balance']);
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testNativeCorrectionRestorationCanRepayBillingDebt(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$service, $debit, $funding] = $this->nativeCorrectionFixture($pdo);
            $purchase = $funding->grant($this->input('refunded-repayment', '7'));
            $this->lock($pdo)->run('tenant', static fn (PDO $pdo, array $wallet, string $now) => PurchaseReversals::reverseLocked($pdo, $wallet, $now, $purchase['grantId'], ['verified' => 'fixture']));
            $this->assertSame('5.0000', (string) WalletDebt::billing($pdo, 'tenant'));
            $service->reverse($this->nativeCorrection($debit, '1'));
            $this->assertSame('4.0000', (string) WalletDebt::billing($pdo, 'tenant'));
            $this->assertSame('0.0000', (string) NativeSearchDebt::outstanding($pdo, 'tenant'));
            $this->assertSame('-4.0000', $this->balanceStatus($pdo)->inspect('tenant')['balance']);
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testNativeCorrectionRejectsLegacyAttributionWithoutChangingFinancialState(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$service, $debit] = $this->nativeCorrectionFixture($pdo);
            foreach ($pdo->query("SELECT * FROM credit_transaction WHERE type IN ('searchDebtPayment','searchDebtRecovery')")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $e = json_decode($row['evidence'], true, 32, JSON_THROW_ON_ERROR); unset($e['debtTransactionId'], $e['attribution']);
                $hash = hash('sha256', $row['tenant_id'] . ':' . $row['type'] . ':' . $row['idempotency_key'] . ':' . $row['credits']);
                $pdo->prepare('UPDATE credit_transaction SET debt_transaction_id=NULL,evidence=?,input_hash=? WHERE id=?')->execute([json_encode($e, JSON_THROW_ON_ERROR), $hash, $row['id']]);
            }
            $this->assertAuditHealthy($pdo); $before = $this->auditSnapshot($pdo);
            try { $service->reverse($this->nativeCorrection($debit, '1')); $this->fail('Legacy attribution guessed.'); }
            catch (Conflict $e) { $this->assertStringContainsString('attribution is incomplete', $e->getMessage()); }
            $this->assertSame($before, $this->auditSnapshot($pdo));
        });
    }

    #[DataProvider('dialects')]
    public function testNativeCorrectionCapsAmountsAndSharesReversalKeyNamespace(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$service, $debit] = $this->nativeCorrectionFixture($pdo);
            $amount = (string) Amount::fromString('0')->minus(Amount::fromString($debit['credits']));
            $service->reverse($this->nativeCorrection($debit, $amount));
            $before = $this->auditSnapshot($pdo);
            try { $service->reverse($this->nativeCorrection($debit, '0.0001', 'excess')); $this->fail('Over-correction accepted.'); } catch (Conflict) {}
            try { $service->reverse(new ReversalInput('other', $debit['id'], 'foreign', '1', 'Correction', 'admin')); $this->fail('Foreign debt accepted.'); } catch (NotFound) {}
            $grantDebit = $pdo->query("SELECT id FROM credit_transaction WHERE type='debit'")->fetchColumn();
            try { $service->reverse(new ReversalInput('tenant', $grantDebit, 'native-correction', '0.0001', 'Correction', 'admin')); $this->fail('Reversal key reused across debit kinds.'); } catch (Conflict) {}
            $this->assertSame($before, $this->auditSnapshot($pdo)); $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testNativeCorrectionWriteFailuresRollbackEveryLedgerAndProjectionLeg(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$service, $debit, $funding] = $this->nativeCorrectionFixture($pdo);
            $funding->grant($this->input('repayment', '5'));
            $input = $this->nativeCorrection($debit, '1');
            foreach ([['credit_transaction', "type <> 'searchDebtReversal'"], ['credit_transaction', "type <> 'searchDebtReopen'"],
                ['credit_transaction', "type <> 'searchDebtRestore'"], ['credit_grant', 'remaining_credits=0'], ['tenant_credit_balance', 'balance<=0']] as [$table, $check]) {
                $before = $this->auditSnapshot($pdo); $pdo->exec("ALTER TABLE $table ADD CONSTRAINT injected_native_correction CHECK ($check)");
                try { $service->reverse($input); $this->fail('Partial correction committed.'); }
                catch (PDOException) { $this->assertSame($before, $this->auditSnapshot($pdo)); }
                finally { $pdo->exec("ALTER TABLE $table DROP " . self::dropCheckKeyword($pdo) . ' injected_native_correction'); }
            }
            $service->reverse($input); $this->assertConsistent($pdo, '1.0000'); $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testNativeCorrectionExpiredRestorationFailureRollsBackAndAuditFindsBrokenExpiry(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$service, $debit, $funding] = $this->nativeCorrectionFixture($pdo);
            $funding->grant($this->input('repayment', '5', expires: '2026-10-11 00:00:00')); $this->now = '2026-10-11 00:00:00';
            $input = $this->nativeCorrection($debit, '1'); $before = $this->auditSnapshot($pdo);
            $pdo->exec("ALTER TABLE credit_transaction ADD CONSTRAINT injected_native_expiry CHECK (type <> 'expiration' OR debt_transaction_id IS NULL)");
            try { $service->reverse($input); $this->fail('Expired correction committed without expiration.'); }
            catch (PDOException) { $this->assertSame($before, $this->auditSnapshot($pdo)); }
            finally { $pdo->exec('ALTER TABLE credit_transaction DROP ' . self::dropCheckKeyword($pdo) . ' injected_native_expiry'); }
            $receipt = $service->reverse($input); $this->assertConsistent($pdo, '0.0000'); $this->assertAuditHealthy($pdo);
            $expiry = $receipt['restorations'][0]['expirationTransactionId'];
            $pdo->prepare('UPDATE credit_transaction SET reversal_of_id=? WHERE id=?')->execute(['missing', $expiry]);
            $codes = array_column($this->audit($pdo)->inspect('tenant')['findings'], 'code');
            $this->assertContains('transaction.native_correction_expiry', $codes);
            $this->assertContains('transaction.native_correction_evidence', $codes);
        });
    }

    #[DataProvider('dialects')]
    public function testNativeCorrectionControllerUsesAuthenticatedActorAndHistoryAcceptsCorrectionTypes(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$service, $debit, $funding] = $this->nativeCorrectionFixture($pdo); $funding->grant($this->input('repayment', '5'));
            $user = $this->createStub(\Espo\Entities\User::class); $user->method('isAdmin')->willReturn(true); $user->method('getId')->willReturn('trusted-admin');
            $request = $this->createStub(\Espo\Core\Api\Request::class); $request->method('getBodyContents')->willReturn(json_encode([
                'tenantId' => 'tenant', 'transactionId' => $debit['id'], 'credits' => '1', 'idempotencyKey' => 'controller-native', 'reason' => 'Approved correction'], JSON_THROW_ON_ERROR));
            $controller = new \Espo\Modules\FeatureCredits\Controllers\CreditReversals($user, $service);
            $receipt = $controller->postActionReverse($request, $this->createStub(\Espo\Core\Api\Response::class));
            $this->assertEquals($receipt, $controller->postActionReverse($request, $this->createStub(\Espo\Core\Api\Response::class)));
            $this->assertSame('trusted-admin', $receipt->actorId);
            $manager = $this->createStub(\Espo\ORM\EntityManager::class); $manager->method('getPDO')->willReturn($pdo);
            $clock = $this->createStub(\Espo\Modules\FeatureCredits\Accounting\Clock::class); $clock->method('now')->willReturn($this->now);
            $rows = (new TransactionHistory($manager, $clock))->inspect(new TransactionHistoryQuery('tenant'))['list'];
            foreach (['searchDebtReversal', 'searchDebtReopen', 'searchDebtRestore'] as $type) $this->assertContains($type, array_column($rows, 'type'));
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testNativeCorrectionRacesPreserveReplayCapAndConcurrentFunding(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            [, $debit] = $this->nativeCorrectionFixture($pdo);
            $results = $this->race($pdo, $dialect, [['reverse', $debit['id'], '1', 'same-native'], ['reverse', $debit['id'], '1', 'same-native'], ['grant', 'race-fund', '7', '']]);
            $this->assertCount(3, array_column($results, 'result')); $this->assertSame($results[0], $results[1]);
            $this->assertConsistent($pdo, '3.0000'); $this->assertAuditHealthy($pdo);
            $remaining = (string) Amount::fromString('0')->minus(Amount::fromString($debit['credits']))->minus(Amount::fromString('1'));
            $results = $this->race($pdo, $dialect, [['reverse', $debit['id'], $remaining, 'competing-a'], ['reverse', $debit['id'], $remaining, 'competing-b']]);
            $this->assertCount(1, array_column($results, 'result')); $this->assertCount(1, array_column($results, 'error'));
            $this->assertSame('0.0000', (string) NativeSearchDebt::outstanding($pdo, 'tenant')); $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testNativeCorrectionReplayAndAuditRejectMissingLegsOrChangedGrantExpiry(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$service, $debit, $funding] = $this->nativeCorrectionFixture($pdo);
            $funding->grant($this->input('repayment', '5'));
            $input = $this->nativeCorrection($debit, '1'); $receipt = $service->reverse($input);
            $part = $receipt['restorations'][0];
            foreach (['missing-leg', 'changed-expiry'] as $fault) {
                if ($fault === 'missing-leg') $pdo->prepare('UPDATE credit_transaction SET deleted=TRUE WHERE id=?')->execute([$part['restoreTransactionId']]);
                else $pdo->prepare('UPDATE credit_grant SET expires_at=? WHERE id=?')->execute(['2027-01-01 00:00:00', $part['grantId']]);
                $this->assertContains('transaction.native_correction_evidence', array_column($this->audit($pdo)->inspect('tenant')['findings'], 'code'));
                try { $service->reverse($input); $this->fail('Corrupt correction replay accepted.'); } catch (Conflict) {}
                if ($fault === 'missing-leg') $pdo->prepare('UPDATE credit_transaction SET deleted=FALSE WHERE id=?')->execute([$part['restoreTransactionId']]);
                else $pdo->prepare('UPDATE credit_grant SET expires_at=? WHERE id=?')->execute([$part['expiresAt'], $part['grantId']]);
            }
            $this->assertSame($receipt, $service->reverse($input)); $this->assertAuditHealthy($pdo);
        });
    }
}
