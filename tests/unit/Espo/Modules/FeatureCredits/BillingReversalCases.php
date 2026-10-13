<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\Conflict;
use Espo\Modules\FeatureCredits\Accounting\{Amount, FixedSettlement, NativeSearchDebt, OutcomeInput, Outcomes, Requests, Settlements, WalletDebt};
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/BillingPaymentFixtures.php';

trait BillingReversalCases
{
    private function billingReversalFixture(PDO $pdo): array
    {
        $f = new BillingPaymentFixtures($pdo, $this->now);
        $f->catalog->publish(BillingFixtures::definition(), 'admin');
        $this->now = '2026-10-13 00:00:00';
        [, $charge] = $f->charge(); $id = $f->payment($charge); $paid = $f->read($id);
        return [$f, $charge, $id, $paid];
    }

    #[DataProvider('dialects')]
    public function testBillingReversalRacesSettlementAndFundingWithoutLosingDebtOrUsage(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            [$f, , , $paid] = $this->billingReversalFixture($pdo);
            $usage = $this->reservations($pdo)->reserve($this->admission())['usageId'];
            $request = (new Requests($f->lock, $f->funding))->authorize($this->request($usage))['requestId'];
            (new Outcomes($f->lock))->record(new OutcomeInput('tenant', $usage, 'execution', $request, 'success', 0, 0, 1000, (object) ['source' => 'provider']));
            $results = $this->race($pdo, $dialect, [
                ['purchase-reverse', $paid['funding']['grantId'], '', ''], ['purchase-reverse', $paid['funding']['grantId'], '', ''],
                ['settle', $usage, '', ''], ['grant', 'new-funding', '3', ''],
            ]);
            $this->assertCount(4, array_column($results, 'result'));
            $this->assertSame($results[0], $results[1]);
            $this->assertSame('0.1875', $results[2]['result']['settledCredits']);
            $this->assertSame('0.0000', (string) WalletDebt::billing($pdo, 'tenant'));
            $this->assertConsistent($pdo, '2.8125'); $this->assertHeld($pdo, '0.0000'); $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testBillingReversalPurchaseUsedForNativeDebtBecomesBillingDebtWithoutRewritingUsage(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [, $funding, $usage] = $this->nativeDebtFixture($pdo);
            $settled = (new Settlements($this->lock($pdo), $funding))->settle('tenant', $usage, 'execution');
            $this->assertSame('5.0000', (string) NativeSearchDebt::outstanding($pdo, 'tenant'));
            [$f, $charge, $id] = $this->billingReversalFixture($pdo);
            $this->assertSame('0.0000', (string) NativeSearchDebt::outstanding($pdo, 'tenant'));
            $f->payment($charge, status: 'REFUNDED'); $result = $f->read($id);
            $this->assertSame('45.0000', $result['reversal']['recoveredCredits']);
            $this->assertSame('5.0000', (string) WalletDebt::billing($pdo, 'tenant'));
            $this->assertSame('0.0000', (string) NativeSearchDebt::outstanding($pdo, 'tenant'));
            $this->assertSame($settled, (new Settlements($f->lock, $f->funding))->settle('tenant', $usage, 'execution'));
            $f->funding->grant($this->input('debt-repayment', '7'));
            $this->assertConsistent($pdo, '2.0000'); $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testBillingReversalChargebackReviewFinalLossAndOriginalProviderAfterReroute(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $f = new BillingPaymentFixtures($pdo, $this->now);
            $f->catalog->publish(BillingFixtures::definition(), 'admin'); $this->now = '2026-10-13 00:00:00';
            [, $charge] = $f->charge(method: 'CREDIT_CARD'); $id = $f->payment($charge); $f->read($id);
            $f->accounts->changeCountry('tenant', 'us', 2, 'US', 'admin');
            $f->accounts->changeOverride('tenant', 'stripe', 3, 'stripe', 'admin');
            $f->connections->enabled = false;
            foreach (['REQUESTED', 'IN_DISPUTE', 'REVERSED', 'DISPUTE_LOST'] as $status) {
                foreach ($f->transport->objects as &$object) if ($object['id'] === $id) {
                    $object['status'] = $status === 'DISPUTE_LOST' ? 'REFUNDED' : 'CHARGEBACK_DISPUTE';
                    $object['chargeback'] = ['id' => 'chargeback-original', 'payment' => $id, 'customerAccount' => $object['customer'],
                        'status' => $status, 'value' => $object['value']];
                }
                unset($object);
                $this->assertSame($status === 'DISPUTE_LOST' ? 'reversed' : 'review_required', $f->read($id)['state']);
                $this->assertSame($status === 'DISPUTE_LOST' ? '0.0000' : '50.0000', $this->balanceStatus($pdo)->inspect('tenant')['balance']);
            }
            $source = json_decode($pdo->query("SELECT evidence FROM credit_transaction WHERE type='purchaseReversal'")->fetchColumn(), true, 32, JSON_THROW_ON_ERROR);
            $this->assertSame('asaas-br', $source['verifiedSource']['integrationId']);
            $this->assertSame('chargeback', $source['verifiedSource']['reversal']['kind']);
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testBillingReversalAvailableCreditsReplayAndHistoricalFundingStayImmutable(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$f, $charge, $id, $paid] = $this->billingReversalFixture($pdo);
            $reservations = $this->reservations($pdo);
            $f->events->receive('asaas-br', 'fixture-webhook-token', json_encode([
                'id' => 'evt_false_refund', 'event' => 'PAYMENT_REFUNDED', 'payment' => ['id' => $id, 'status' => 'REFUNDED'],
            ], JSON_THROW_ON_ERROR));
            $f->events->processBatch();
            $this->assertConsistent($pdo, '50.0000'); // Webhook refund claims are not financial authority.
            $f->payment($charge, status: 'REFUNDED'); $reversed = $f->read($id);
            $this->assertSame('reversed', $reversed['state']);
            $this->assertSame('50.0000', $reversed['reversal']['recoveredCredits']);
            $this->assertSame('0.0000', $reversed['reversal']['debtCredits']);
            $this->assertSame($paid['funding'], $reversed['funding']);
            $before = $this->auditSnapshot($pdo);
            $this->assertSame($reversed, $f->read($id));
            $f->payment($charge); // Later paid reads cannot mint a new copy of revoked funding.
            $this->assertSame($reversed, $f->read($id));
            $this->assertSame($before, $this->auditSnapshot($pdo));
            $this->assertConsistent($pdo, '0.0000');
            try { $reservations->reserve($this->admission('denied')); $this->fail('Revoked credits spent.'); } catch (Conflict) {}
            $f->funding->grant($this->input('new-funding', '2'));
            $this->assertSame('held', $reservations->reserve($this->admission('allowed'))['state']);
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testBillingReversalSpentCreditsBecomeDebtAndOnlyLiveFutureFundingRepays(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$f, $charge, $id] = $this->billingReversalFixture($pdo);
            $reservations = $this->reservations($pdo);
            $usage = $reservations->reserve($this->admission())['usageId'];
            $request = (new Requests($f->lock, $f->funding))->authorize($this->request($usage))['requestId'];
            (new Outcomes($f->lock))->record(new OutcomeInput('tenant', $usage, 'execution', $request, 'success', 0, 0, 1000, (object) ['source' => 'provider']));
            $settled = (new Settlements($f->lock, $f->funding))->settle('tenant', $usage, 'execution');
            $f->payment($charge, status: 'REFUNDED'); $reversed = $f->read($id);
            $this->assertSame('49.8125', $reversed['reversal']['recoveredCredits']);
            $this->assertSame('0.1875', $reversed['reversal']['debtCredits']);
            $this->assertSame('0.1875', (string) WalletDebt::billing($pdo, 'tenant'));
            $this->assertSame('-0.1875', $this->balanceStatus($pdo)->inspect('tenant')['availableCredits']);
            $this->assertSame($settled, (new Settlements($f->lock, $f->funding))->settle('tenant', $usage, 'execution'));
            try { $reservations->reserve($this->admission('denied')); $this->fail('Debt bypassed.'); } catch (Conflict) {}
            $f->funding->grant($this->input('ended-period', '100', expires: '2026-10-12 00:00:00'));
            $this->assertSame('0.1875', (string) WalletDebt::billing($pdo, 'tenant'));
            $partial = $f->funding->grant($this->input('partial', '0.1'));
            $this->assertSame($partial, $f->funding->grant($this->input('partial', '0.1')));
            $this->assertSame('0.0875', (string) WalletDebt::billing($pdo, 'tenant'));
            $f->funding->grant($this->input('complete', '2.0875'));
            $this->assertSame('0.0000', (string) WalletDebt::billing($pdo, 'tenant'));
            $this->assertConsistent($pdo, '2.0000');
            $this->assertSame('held', $reservations->reserve($this->admission('allowed'))['state']);
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testBillingReversalUsageCorrectionRepaysShortfallAndAuditDetectsBrokenDebtLinks(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$f, $charge, $id] = $this->billingReversalFixture($pdo);
            $usage = $this->reservations($pdo)->reserve($this->admission())['usageId'];
            $request = (new Requests($f->lock, $f->funding))->authorize($this->request($usage))['requestId'];
            (new Outcomes($f->lock))->record(new OutcomeInput('tenant', $usage, 'execution', $request, 'success', 0, 0, 1000, (object) ['source' => 'provider']));
            (new Settlements($f->lock, $f->funding))->settle('tenant', $usage, 'execution');
            $f->payment($charge, status: 'REFUNDED'); $refund = $f->read($id);
            $debit = $pdo->query("SELECT id FROM credit_transaction WHERE type='debit'")->fetchColumn();
            $corrections = new \Espo\Modules\FeatureCredits\Accounting\Reversals($f->lock, $f->funding);
            $input = new \Espo\Modules\FeatureCredits\Accounting\ReversalInput('tenant', $debit, 'correct-refunded-usage', '0.1875', 'Correction', 'admin');
            $receipt = $corrections->reverse($input);
            $this->assertSame($receipt, $corrections->reverse($input));
            $this->assertSame('0.0000', (string) WalletDebt::billing($pdo, 'tenant'));
            $this->assertConsistent($pdo, '0.0000'); $this->assertAuditHealthy($pdo);
            $pdo->prepare('UPDATE credit_transaction SET reversal_of_id=? WHERE id=?')->execute(['missing', $refund['reversal']['debtTransactionId']]);
            $codes = array_column($this->audit($pdo)->inspect('tenant')['findings'], 'code');
            $this->assertContains('transaction.billing_debt', $codes);
            $this->assertContains('transaction.purchase_reversal', $codes);
        });
    }

    #[DataProvider('dialects')]
    public function testBillingReversalPreservesHoldsBlocksNewRequestsAndReleaseRepays(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$f, $charge, $id] = $this->billingReversalFixture($pdo);
            $reservations = $this->reservations($pdo);
            $usage = $reservations->reserve($this->admission(credits: '5'))['usageId'];
            $f->payment($charge, status: 'REFUNDED'); $reversed = $f->read($id);
            $this->assertSame('45.0000', $reversed['reversal']['recoveredCredits']);
            $this->assertSame('5.0000', (string) WalletDebt::billing($pdo, 'tenant'));
            $this->assertHeld($pdo, '5.0000');
            try { (new Requests($f->lock, $f->funding))->authorize($this->request($usage)); $this->fail('New inference allowed while debt outstanding.'); } catch (Conflict) {}
            $reservations->release('tenant', $usage, 'execution');
            $this->assertSame('0.0000', (string) WalletDebt::billing($pdo, 'tenant'));
            $this->assertConsistent($pdo, '0.0000'); $this->assertHeld($pdo, '0.0000');
            $this->assertSame($reversed, $f->read($id)); $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testBillingReversalAlreadyAuthorizedSuccessSettlesWithoutLosingCharge(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$f, $charge, $id] = $this->billingReversalFixture($pdo);
            $usage = $this->reservations($pdo)->reserve($this->admission())['usageId'];
            $request = (new Requests($f->lock, $f->funding))->authorize($this->request($usage))['requestId'];
            $f->payment($charge, status: 'REFUNDED'); $f->read($id);
            (new Outcomes($f->lock))->record(new OutcomeInput('tenant', $usage, 'execution', $request, 'success', 0, 0, 1000, (object) ['source' => 'provider']));
            $this->assertSame('0.1875', (new Settlements($f->lock, $f->funding))->settle('tenant', $usage, 'execution')['settledCredits']);
            $this->assertSame('0.1875', (string) WalletDebt::billing($pdo, 'tenant'));
            $this->assertSame('-0.1875', $this->balanceStatus($pdo)->inspect('tenant')['balance']);
            $this->assertHeld($pdo, '0.0000'); $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testBillingReversalFullyDepletedPurchaseHasDurableZeroRecoveryRoot(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$f, $charge, $id] = $this->billingReversalFixture($pdo);
            $usageId = $this->reservations($pdo)->reserve($this->admission(credits: '50', type: 'enrichment'))['usageId'];
            $f->lock->run('tenant', function (PDO $pdo, array $wallet, string $now) use ($f, $usageId): void {
                $q = $pdo->prepare('SELECT * FROM credit_usage WHERE id=?'); $q->execute([$usageId]); $usage = $q->fetch(PDO::FETCH_ASSOC);
                $q = $pdo->prepare('SELECT * FROM credit_reservation WHERE usage_id=?'); $q->execute([$usageId]);
                (new FixedSettlement($f->funding))->settleLocked($pdo, $wallet, $now, $usage, $q->fetch(PDO::FETCH_ASSOC), Amount::fromString('50'));
            });
            $f->payment($charge, status: 'REFUNDED'); $reversed = $f->read($id);
            $this->assertSame('0.0000', $reversed['reversal']['recoveredCredits']);
            $this->assertSame('50.0000', (string) WalletDebt::billing($pdo, 'tenant'));
            $this->assertSame($reversed, $f->read($id));
            $this->assertSame('-50.0000', $this->balanceStatus($pdo)->inspect('tenant')['balance']);
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testBillingReversalFinancialVerificationAndRepaymentFaultsRollbackTogether(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            [$f, $charge, $id] = $this->billingReversalFixture($pdo);
            $this->reservations($pdo)->reserve($this->admission());
            $f->funding->grant($this->input('other-funding', '5'));
            $f->payment($charge, status: 'REFUNDED');
            foreach (['credit_transaction' => ["type<>'purchaseReversal'", "type<>'billingDebt'", "type<>'billingDebtRecovery'"],
                'credit_grant' => ['remaining_credits>reserved_credits'], 'tenant_credit_balance' => ['balance>5'],
                'credit_billing_payment' => ["state<>'reversed'"], 'credit_billing_verification' => ["state<>'reversed'"]] as $table => $checks) {
                foreach ($checks as $check) {
                    $before = $this->auditSnapshot($pdo);
                    $pdo->exec("ALTER TABLE $table ADD CONSTRAINT injected_billing_reversal CHECK ($check)");
                    try { $f->read($id); $this->fail('Partial billing reversal committed.'); }
                    catch (PDOException) { $this->assertSame($before, $this->auditSnapshot($pdo)); }
                    finally { $pdo->exec("ALTER TABLE $table DROP " . self::dropCheckKeyword($pdo) . ' injected_billing_reversal'); }
                }
            }
            $this->assertSame('reversed', $f->read($id)['state']);
            $this->assertSame('0.0000', (string) WalletDebt::billing($pdo, 'tenant'));
            $this->assertConsistent($pdo, '5.0000'); $this->assertHeld($pdo, '2.0000'); $this->assertAuditHealthy($pdo);
        });
    }
}
