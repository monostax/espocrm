<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\Conflict;
use Espo\Modules\FeatureCredits\Accounting\{Amount, FundingRefunds, OutcomeInput, Outcomes, Requests, Settlements, WalletDebt};
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

trait FundingRefundCases
{
    private function refundResponse(BillingPaymentFixtures $f, string $id, array $amounts, string $status = 'RECEIVED'): void
    {
        foreach ($f->transport->objects as &$object) if ($object['id'] === $id) {
            $object['status'] = $status;
            $object['refunds'] = array_map(static fn (string $value): array => ['status' => 'DONE', 'value' => $value], $amounts);
        }
        unset($object);
    }

    public function testCumulativeRefundRoundingUsesOriginalPriceAndExactFullAllowance(): void
    {
        $this->assertSame('0.3333', (string) FundingRefunds::credits('1', '3', '1'));
        $this->assertSame('0.6667', (string) FundingRefunds::credits('1', '3', '2'));
        $this->assertSame('1.0000', (string) FundingRefunds::credits('1', '3', '3'));
        $this->assertSame('0.0001', (string) FundingRefunds::credits('0.0001', '2', '1'));
        $this->assertSame('2000.0000', (string) FundingRefunds::credits('2000', '997', '997'));
        foreach ([['0','1'], ['10','10.01'], ['10','-1'], ['10','1e0']] as [$price, $refund]) {
            try { FundingRefunds::credits('10', $price, $refund); $this->fail('Invalid monetary refund accepted.'); } catch (Conflict) {}
        }
    }

    #[DataProvider('dialects')]
    public function testCumulativeRefundPaymentPartialFullReplayAndStaleReads(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$f, , $id, $paid] = $this->billingReversalFixture($pdo);
            $value = $pdo->query('SELECT evidence FROM credit_billing_payment')->fetchColumn();
            $price = json_decode($value, true)['value'];
            $half = (string) \Brick\Math\BigDecimal::of($price)->dividedBy('2', 2);
            $this->refundResponse($f, $id, [$half]); $partial = $f->read($id);
            $this->assertSame('partially_reversed', $partial['state']);
            $this->assertSame('25.0000', $partial['reversal']['cumulativeCredits']);
            $this->assertSame($partial, $f->read($id)); $this->assertConsistent($pdo, '25.0000');
            $this->refundResponse($f, $id, []); $this->assertSame($partial, $f->read($id));
            $this->refundResponse($f, $id, [$half, $half], 'REFUNDED'); $full = $f->read($id);
            $this->assertSame('reversed', $full['state']); $this->assertSame('50.0000', $full['reversal']['cumulativeCredits']);
            $this->assertSame('25.0000', $full['reversal']['revokedCredits']);
            $this->assertSame($paid['funding'], $full['funding']); $this->assertConsistent($pdo, '0.0000');
            $this->refundResponse($f, $id, [$half]); $this->assertSame($full, $f->read($id));
            $this->assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type='purchaseReversal'")->fetchColumn());
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testCumulativeRefundBeforeOriginalFundingCommitsNetOnce(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $f = new BillingPaymentFixtures($pdo, $this->now); $f->catalog->publish(BillingFixtures::definition(), 'admin');
            $this->now = '2026-10-13 00:00:00'; [, $charge] = $f->charge(); $id = $f->payment($charge);
            $this->refundResponse($f, $id, ['1']); $result = $f->read($id);
            $this->assertSame('partially_reversed', $result['state']);
            $this->assertSame($result, $f->read($id));
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM credit_grant')->fetchColumn());
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testCumulativeRefundExpiredSubscriptionCountsUnusedExpiryAndOnlyConsumedDebt(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $f = new BillingPaymentFixtures($pdo, $this->now); $f->catalog->publish(BillingFixtures::definition(), 'admin');
            $this->now = '2026-10-13 00:00:00'; [, $charge] = $f->charge(subscription: true); $id = $f->payment($charge);
            $this->now = '2026-10-16 00:00:00'; $paid = $f->read($id);
            $usage = $this->reservations($pdo)->reserve($this->admission())['usageId'];
            $request = (new Requests($f->lock, $f->funding))->authorize($this->request($usage))['requestId'];
            (new Outcomes($f->lock))->record(new OutcomeInput('tenant', $usage, 'execution', $request, 'success', 0, 0, 1000, (object) ['source' => 'provider']));
            $settled = (new Settlements($f->lock, $f->funding))->settle('tenant', $usage, 'execution');
            $this->now = '2026-11-16 00:00:00'; $this->refundResponse($f, $id, [], 'REFUNDED'); $result = $f->read($id);
            $this->assertSame('reversed', $result['state']);
            $this->assertSame('0.1875', $result['reversal']['debtCredits']);
            $this->assertSame('0.0000', $result['reversal']['recoveredCredits']);
            $this->assertSame('0.1875', (string) WalletDebt::billing($pdo, 'tenant'));
            $this->assertSame($paid['funding'], $result['funding']); $this->assertSame($result, $f->read($id));
            $this->assertSame($settled, (new Settlements($f->lock, $f->funding))->settle('tenant', $usage, 'execution'));
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testCumulativeRefundSubscriptionWaitsForRealHoldReleaseThenCountsExpiry(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $f = new BillingPaymentFixtures($pdo, $this->now); $f->catalog->publish(BillingFixtures::definition(), 'admin');
            $this->now = '2026-10-13 00:00:00'; [, $charge] = $f->charge(subscription: true); $id = $f->payment($charge);
            $this->now = '2026-10-16 00:00:00'; $f->read($id);
            $reservations = $this->reservations($pdo); $usage = $reservations->reserve($this->admission())['usageId'];
            $this->now = '2026-11-16 00:00:00'; $this->refundResponse($f, $id, [], 'REFUNDED');
            $this->assertSame('awaiting_refund_release', $f->read($id)['state']); $this->assertHeld($pdo, '2.0000');
            $f->funding->grant($this->input('other-eligible-purchase', '5'));
            try { $reservations->reserve($this->admission('new-work')); $this->fail('Pending refund admitted new work.'); } catch (Conflict) {}
            try { (new Requests($f->lock, $f->funding))->authorize($this->request($usage)); $this->fail('Pending refund authorized new request.'); } catch (Conflict) {}
            $reservations->release('tenant', $usage, 'execution');
            // Delayed older GET cannot discard the previously verified refund while waiting.
            $this->refundResponse($f, $id, []); $result = $f->read($id);
            $this->assertSame('reversed', $result['state']); $this->assertSame('0.0000', $result['reversal']['debtCredits']);
            $this->assertConsistent($pdo, '5.0000'); $this->assertHeld($pdo, '0.0000'); $this->assertAuditHealthy($pdo);
            $this->assertSame('held', $reservations->reserve($this->admission('after-refund'))['state']);
        });
    }

    #[DataProvider('dialects')]
    public function testCumulativeRefundPurchaseHoldsAndConcurrentFundingKeepOneEffect(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            [$f, , , $paid] = $this->billingReversalFixture($pdo);
            $reservations = $this->reservations($pdo); $usage = $reservations->reserve($this->admission('hold', '50'))['usageId'];
            $results = $this->race($pdo, $dialect, [['funding-refund', $paid['funding']['grantId'], '25', ''],
                ['funding-refund', $paid['funding']['grantId'], '25', ''], ['grant', 'fund', '3', '']]);
            $this->assertCount(3, array_column($results, 'result')); $this->assertSame($results[0], $results[1]);
            $this->assertHeld($pdo, '50.0000'); $this->assertSame('22.0000', (string) WalletDebt::billing($pdo, 'tenant'));
            $reservations->release('tenant', $usage, 'execution');
            $this->assertConsistent($pdo, '28.0000'); $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testCumulativeRefundRollbackAndBrokenChainFailClosed(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$f, , $id] = $this->billingReversalFixture($pdo); $this->refundResponse($f, $id, ['1']);
            $before = $this->auditSnapshot($pdo);
            $pdo->exec("ALTER TABLE credit_transaction ADD CONSTRAINT injected_refund CHECK (type<>'purchaseReversal')");
            try { $f->read($id); $this->fail('Partial refund committed.'); }
            catch (PDOException) { $this->assertSame($before, $this->auditSnapshot($pdo)); }
            finally { $pdo->exec('ALTER TABLE credit_transaction DROP ' . self::dropCheckKeyword($pdo) . ' injected_refund'); }
            $first = $f->read($id); $this->refundResponse($f, $id, ['2']); $f->read($id);
            $pdo->prepare('UPDATE credit_transaction SET deleted=TRUE WHERE id=?')->execute([$first['reversal']['transactionId']]);
            $this->assertContains('transaction.funding_refund', array_column($this->audit($pdo)->inspect('tenant')['findings'], 'code'));
            try { $f->read($id); $this->fail('Broken refund chain accepted.'); } catch (Conflict) {}
        });
    }

    #[DataProvider('dialects')]
    public function testCumulativeRefundEventRetriesHeldMonthlyRefundAndPreservesSuccessfulUsage(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $f = new BillingPaymentFixtures($pdo, $this->now); $f->catalog->publish(BillingFixtures::definition(), 'admin');
            $this->now = '2026-10-13 00:00:00'; [, $charge] = $f->charge(subscription: true); $id = $f->payment($charge);
            $this->now = '2026-10-16 00:00:00'; $f->read($id);
            $usage = $this->reservations($pdo)->reserve($this->admission())['usageId'];
            $request = (new Requests($f->lock, $f->funding))->authorize($this->request($usage))['requestId'];
            $this->refundResponse($f, $id, [], 'REFUNDED');
            $f->events->receive('asaas-br', 'fixture-webhook-token', json_encode([
                'id' => 'evt_monthly_refund', 'event' => 'PAYMENT_REFUNDED', 'payment' => ['id' => $id]], JSON_THROW_ON_ERROR));
            $f->events->processBatch();
            $this->assertSame('awaiting_refund_release', $pdo->query('SELECT state FROM credit_billing_payment')->fetchColumn());
            $this->assertNotSame('processed', $pdo->query('SELECT state FROM credit_billing_event')->fetchColumn());
            (new Outcomes($f->lock))->record(new OutcomeInput('tenant', $usage, 'execution', $request, 'success', 0, 0, 1000, (object) ['source' => 'provider']));
            (new Settlements($f->lock, $f->funding))->settle('tenant', $usage, 'execution');
            $this->now = '2026-10-16 00:03:00'; $f->events->processBatch();
            $this->assertSame('processed', $pdo->query('SELECT state FROM credit_billing_event')->fetchColumn());
            $this->assertSame('reversed', $pdo->query('SELECT state FROM credit_billing_payment')->fetchColumn());
            $this->assertSame('0.1875', (string) WalletDebt::billing($pdo, 'tenant')); $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testCumulativeRefundWonDisputeKeepsOriginalGrantAndFinalLossCannotRegrant(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$f, , $id, $paid] = $this->billingReversalFixture($pdo); $before = $this->auditSnapshot($pdo);
            foreach (['REQUESTED', 'IN_DISPUTE', 'REVERSED'] as $status) {
                foreach ($f->transport->objects as &$object) if ($object['id'] === $id) {
                    $object['status'] = $status === 'REVERSED' ? 'RECEIVED' : 'CHARGEBACK_DISPUTE';
                    $object['chargeback'] = ['id' => 'cb_original', 'payment' => $id, 'customerAccount' => $object['customer'], 'status' => $status, 'value' => $object['value']];
                }
                unset($object);
                $result = $f->read($id);
                $this->assertSame($status === 'REVERSED' ? 'funded' : 'review_required', $result['state']);
                $this->assertSame($paid['funding'], $result['funding']); $this->assertSame($before, $this->auditSnapshot($pdo));
            }
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testCumulativeRefundAdditionalWriteFailuresRollbackDebtAndProjections(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$f, , $id] = $this->billingReversalFixture($pdo);
            $this->reservations($pdo)->reserve($this->admission('hold', '40')); $this->refundResponse($f, $id, ['10']);
            foreach ([['credit_transaction', "type<>'billingDebt'"], ['credit_grant', 'remaining_credits>=50'],
                ['tenant_credit_balance', 'balance>=50'], ['credit_billing_payment', "state<>'partially_reversed'"],
                ['credit_billing_verification', "state<>'partially_reversed'"]] as [$table, $rule]) {
                $before = $this->auditSnapshot($pdo); $pdo->exec("ALTER TABLE $table ADD CONSTRAINT injected_cumulative_refund CHECK ($rule)");
                try { $f->read($id); $this->fail('Refund write fault did not roll back.'); }
                catch (PDOException) { $this->assertSame($before, $this->auditSnapshot($pdo)); }
                finally { $pdo->exec("ALTER TABLE $table DROP " . self::dropCheckKeyword($pdo) . ' injected_cumulative_refund'); }
            }
            $this->assertSame('partially_reversed', $f->read($id)['state']); $this->assertAuditHealthy($pdo);
        });
    }
}
