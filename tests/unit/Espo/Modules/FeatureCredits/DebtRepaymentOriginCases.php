<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\Conflict;
use Espo\Modules\FeatureCredits\Accounting\{Funding, NativeSearchDebt, OutcomeInput, Outcomes, PurchaseReversals, Requests, RequestInput, Settlements, WalletDebt};
use Espo\Modules\FeatureCredits\Pricing\AiRate;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

trait DebtRepaymentOriginCases
{
    private function assertDebtOrigins(PDO $pdo): void
    {
        $rows = $pdo->query("SELECT p.*, d.type AS original_type FROM credit_transaction p
            LEFT JOIN credit_transaction d ON d.id=p.debt_transaction_id AND d.tenant_id=p.tenant_id
            WHERE p.type IN ('searchDebtPayment','searchDebtRecovery','billingDebtPayment','billingDebtRecovery')")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertNotNull($row['debt_transaction_id']);
            $this->assertSame(str_starts_with($row['type'], 'search') ? NativeSearchDebt::DEBIT : WalletDebt::BILLING, $row['original_type']);
            $evidence = json_decode($row['evidence'], true, 32, JSON_THROW_ON_ERROR);
            $this->assertSame($row['debt_transaction_id'], $evidence['debtTransactionId']);
            $this->assertSame('original-debt-v1', $evidence['attribution']);
        }
        $this->assertAuditHealthy($pdo);
    }

    #[DataProvider('dialects')]
    public function testDebtTransfersRetainOriginalDebtAndFundingGrantThroughPartialRepaymentAndExpiry(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [, $funding, $usage] = $this->nativeDebtFixture($pdo);
            (new Settlements($this->lock($pdo), $funding))->settle('tenant', $usage, 'execution');
            $debtId = $pdo->query("SELECT id FROM credit_transaction WHERE type='nativeSearchOverrun'")->fetchColumn();
            $this->assertDebtOrigins($pdo);
            $this->now = '2026-10-11 00:00:00';
            $expired = $funding->grant($this->input('expired-repayment', '100', expires: $this->now));
            $this->assertSame('5.0000', (string) NativeSearchDebt::outstanding($pdo, 'tenant'));
            $q = $pdo->prepare("SELECT COUNT(*) FROM credit_transaction WHERE grant_id=? AND type='searchDebtPayment'");
            $q->execute([$expired['grantId']]); $this->assertSame(0, (int) $q->fetchColumn());
            $paid = $funding->grant($this->input('partial-origin', '3', expires: '2026-10-12 00:00:00'));
            $q = $pdo->prepare("SELECT debt_transaction_id, credits FROM credit_transaction WHERE grant_id=? AND type='searchDebtPayment'");
            $q->execute([$paid['grantId']]);
            $this->assertSame(['debt_transaction_id' => $debtId, 'credits' => '-3.0000'], $q->fetch(PDO::FETCH_ASSOC));
            $before = $this->auditSnapshot($pdo);
            $this->assertSame($paid, $funding->grant($this->input('partial-origin', '3', expires: '2026-10-12 00:00:00')));
            $this->assertSame($before, $this->auditSnapshot($pdo));
            $this->now = '2026-10-12 00:00:00'; $funding->expire('tenant');
            $this->assertSame('2.0000', (string) NativeSearchDebt::outstanding($pdo, 'tenant'));
            $funding->grant($this->input('final-origin', '4'));
            $this->assertConsistent($pdo, '2.0000'); $this->assertDebtOrigins($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testOneGrantSplitsAcrossTwoOriginallyAuthorizedNativeDebts(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $reservations = $this->reservations($pdo);
            $pdo->exec("INSERT INTO ai_model_credit_rate (id,name,policy_id,definition,created_at) VALUES ('native-rate','native','native-test-v1','{}','2026-10-10 12:00:00')");
            $pdo->exec("UPDATE tenant_credit_billing_rate SET ai_native_search_model_credit_rate_id='native-rate' WHERE tenant_id='tenant'");
            $funding = new Funding($this->lock($pdo)); $funding->grant($this->input('opening', '10'));
            $requests = new Requests($this->lock($pdo), $funding); $outcomes = [];
            foreach (['first', 'second'] as $key) {
                $usage = $reservations->reserve($this->admission($key))['usageId'];
                $request = $requests->authorize(new RequestInput('tenant', $usage, 'execution', 'native-' . $key,
                    new AiRate('native-test-v1', 'google', 'gemini-3.8-flash', '1'), 1000, 1, NativeSearchDebt::PROFILE))['requestId'];
                $outcomes[] = new OutcomeInput('tenant', $usage, 'execution', $request, 'success', 400000, 0, 0, (object) ['source' => 'provider']);
            }
            $settlements = new Settlements($this->lock($pdo), $funding);
            foreach ($outcomes as $index => $outcome) {
                (new Outcomes($this->lock($pdo)))->record($outcome);
                $this->now = '2026-10-10 12:00:0' . ($index + 1);
                $settlements->settle('tenant', $outcome->usageId, 'execution');
            }
            $this->assertSame('20.0000', (string) NativeSearchDebt::outstanding($pdo, 'tenant'));
            $before = $pdo->query("SELECT d.id, -d.credits - COALESCE(SUM(r.credits),0) AS remaining FROM credit_transaction d
                LEFT JOIN credit_transaction r ON r.debt_transaction_id=d.id AND r.type='searchDebtRecovery'
                WHERE d.type='nativeSearchOverrun' GROUP BY d.id,d.credits,d.posted_at ORDER BY d.posted_at,d.id")->fetchAll(PDO::FETCH_ASSOC);
            $this->now = '2026-10-10 12:00:03';
            $grant = $funding->grant($this->input('shared-repayment', '25'));
            $q = $pdo->prepare("SELECT debt_transaction_id, -credits AS amount FROM credit_transaction WHERE type='searchDebtPayment' AND grant_id=?");
            $q->execute([$grant['grantId']]); $parts = array_column($q->fetchAll(PDO::FETCH_ASSOC), 'amount', 'debt_transaction_id');
            $this->assertCount(2, $parts);
            foreach ($before as $origin) $this->assertSame($origin['remaining'], $parts[$origin['id']]);
            $this->assertConsistent($pdo, '5.0000'); $this->assertHeld($pdo, '0.0000'); $this->assertDebtOrigins($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testNativeAndBillingDebtOriginsRemainSeparateAndKeepNativePriority(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [, $funding, $usage] = $this->nativeDebtFixture($pdo);
            (new Settlements($this->lock($pdo), $funding))->settle('tenant', $usage, 'execution');
            $purchase = $funding->grant($this->input('refunded-purchase', '3'));
            $this->lock($pdo)->run('tenant', static fn (PDO $pdo, array $wallet, string $now) =>
                PurchaseReversals::reverseLocked($pdo, $wallet, $now, $purchase['grantId'], ['verifiedRefund' => 'fixture']));
            $this->assertSame('2.0000', (string) NativeSearchDebt::outstanding($pdo, 'tenant'));
            $this->assertSame('3.0000', (string) WalletDebt::billing($pdo, 'tenant'));
            $grant = $funding->grant($this->input('mixed-repayment', '4'));
            $this->assertSame('0.0000', (string) NativeSearchDebt::outstanding($pdo, 'tenant'));
            $this->assertSame('1.0000', (string) WalletDebt::billing($pdo, 'tenant'));
            $q = $pdo->prepare("SELECT type, credits FROM credit_transaction WHERE grant_id=? AND type IN ('searchDebtPayment','billingDebtPayment') ORDER BY type");
            $q->execute([$grant['grantId']]);
            $this->assertSame(['billingDebtPayment' => '-2.0000', 'searchDebtPayment' => '-2.0000'], array_column($q->fetchAll(PDO::FETCH_ASSOC), 'credits', 'type'));
            $this->assertDebtOrigins($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testLegacyPooledDebtHistoryIsNeverRewrittenOrGuessedAndStillRepays(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [, $funding, $usage] = $this->nativeDebtFixture($pdo);
            (new Settlements($this->lock($pdo), $funding))->settle('tenant', $usage, 'execution');
            // Model pre-upgrade postings: the additive nullable column is empty on existing rows.
            $pdo->exec("UPDATE credit_transaction SET debt_transaction_id=NULL WHERE type IN ('searchDebtPayment','searchDebtRecovery')");
            foreach ($pdo->query("SELECT * FROM credit_transaction WHERE type IN ('searchDebtPayment','searchDebtRecovery')")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $evidence = json_decode($row['evidence'], true, 32, JSON_THROW_ON_ERROR);
                unset($evidence['debtTransactionId'], $evidence['attribution']);
                $hash = hash('sha256', $row['tenant_id'] . ':' . $row['type'] . ':' . $row['idempotency_key'] . ':' . $row['credits']);
                $pdo->prepare('UPDATE credit_transaction SET evidence=?, input_hash=? WHERE id=?')
                    ->execute([json_encode($evidence, JSON_THROW_ON_ERROR), $hash, $row['id']]);
            }
            $legacy = $pdo->query('SELECT * FROM credit_transaction ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            $grant = $funding->grant($this->input('legacy-partial', '3'));
            $q = $pdo->prepare("SELECT * FROM credit_transaction WHERE grant_id=? AND type='searchDebtPayment'");
            $q->execute([$grant['grantId']]); $posting = $q->fetch(PDO::FETCH_ASSOC);
            $this->assertNull($posting['debt_transaction_id']);
            $this->assertSame('legacy-pooled', json_decode($posting['evidence'], true)['attribution']);
            $this->assertSame('2.0000', (string) NativeSearchDebt::outstanding($pdo, 'tenant'));
            $all = $pdo->query('SELECT * FROM credit_transaction ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            $this->assertSame($legacy, array_values(array_filter($all, static fn ($row) => in_array($row['id'], array_column($legacy, 'id'), true))));
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testOriginCorruptionIsAuditedAndCannotBeUsedForFurtherRepayment(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [, $funding, $usage] = $this->nativeDebtFixture($pdo);
            (new Settlements($this->lock($pdo), $funding))->settle('tenant', $usage, 'execution');
            $debtId = $pdo->query("SELECT id FROM credit_transaction WHERE type='nativeSearchOverrun'")->fetchColumn();
            $grantTransaction = $pdo->query("SELECT id FROM credit_transaction WHERE type='grant'")->fetchColumn();
            foreach (['missing-origin', null, $grantTransaction] as $invalid) {
                $pdo->prepare("UPDATE credit_transaction SET debt_transaction_id=? WHERE type='searchDebtRecovery'")->execute([$invalid]);
                $codes = array_column($this->audit($pdo)->inspect('tenant')['findings'], 'code');
                $this->assertContains('transaction.shape', $codes);
                if ($invalid !== null) $this->assertContains('transaction.debt_origin', $codes);
                $before = $this->auditSnapshot($pdo);
                try { $funding->grant($this->input('corrupt-origin', '3')); $this->fail('Corrupted repayment provenance accepted.'); } catch (Conflict) {}
                $this->assertSame($before, $this->auditSnapshot($pdo));
                $pdo->prepare("UPDATE credit_transaction SET debt_transaction_id=? WHERE type='searchDebtRecovery'")->execute([$debtId]);
            }
            $pdo->exec("UPDATE credit_transaction SET debt_transaction_id=NULL WHERE type IN ('searchDebtPayment','searchDebtRecovery')");
            $this->assertContains('transaction.debt_evidence', array_column($this->audit($pdo)->inspect('tenant')['findings'], 'code'));
            try { $funding->grant($this->input('erased-origin', '3')); $this->fail('Erased new provenance treated as legacy.'); } catch (Conflict) {}
            $pdo->prepare("UPDATE credit_transaction SET debt_transaction_id=? WHERE type IN ('searchDebtPayment','searchDebtRecovery')")->execute([$debtId]);
            $this->assertDebtOrigins($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testOriginPostingFailureRollsBackFundingAndItsGrantProjection(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [, $funding, $usage] = $this->nativeDebtFixture($pdo);
            (new Settlements($this->lock($pdo), $funding))->settle('tenant', $usage, 'execution');
            $this->now = '2026-10-10 12:00:01';
            $before = $this->auditSnapshot($pdo);
            $pdo->exec("ALTER TABLE credit_transaction ADD CONSTRAINT injected_origin_failure CHECK (posted_at < '2026-10-10 12:00:01' OR debt_transaction_id IS NULL)");
            try { $funding->grant($this->input('origin-failure', '3')); $this->fail('Fault injection missed.'); }
            catch (PDOException) { $this->assertSame($before, $this->auditSnapshot($pdo)); }
            finally { $pdo->exec('ALTER TABLE credit_transaction DROP ' . self::dropCheckKeyword($pdo) . ' injected_origin_failure'); }
            $funding->grant($this->input('origin-failure', '3')); $this->assertDebtOrigins($pdo);
        });
    }
}
