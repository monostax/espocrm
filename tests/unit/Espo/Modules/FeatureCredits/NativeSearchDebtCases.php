<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\Conflict;
use Espo\Modules\FeatureCredits\Accounting\{Funding, NativeSearchDebt, OutcomeInput, Outcomes, Requests, RequestInput, Settlements};
use Espo\Modules\FeatureCredits\Pricing\AiRate;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

trait NativeSearchDebtCases
{
    private function nativeDebtFixture(PDO $pdo, bool $siblings = false): array
    {
        $reservations = $this->reservations($pdo);
        $pdo->exec("INSERT INTO ai_model_credit_rate (id, name, policy_id, definition, created_at)
            VALUES ('native-rate', 'native', 'native-test-v1', '{}', '2026-10-10 12:00:00')");
        $pdo->exec("UPDATE tenant_credit_billing_rate SET ai_native_search_model_credit_rate_id = 'native-rate' WHERE tenant_id = 'tenant'");
        $funding = new Funding($this->lock($pdo));
        $funding->grant($this->input('opening', '10', expires: '2026-10-11 00:00:00'));
        $usage = $reservations->reserve($this->admission())['usageId'];
        $requests = new Requests($this->lock($pdo), $funding);
        if ($siblings) {
            $main = $requests->authorize($this->request($usage, 'main-success'))['requestId'];
            (new Outcomes($this->lock($pdo)))->record(new OutcomeInput('tenant', $usage, 'execution', $main,
                'success', 0, 0, 1, (object) ['source' => 'provider']));
            $failed = $requests->authorize($this->request($usage, 'main-failed'))['requestId'];
        }
        $input = new RequestInput('tenant', $usage, 'execution', 'knowledge-0',
            new AiRate('native-test-v1', 'google', 'gemini-3.8-flash', '1'), 1000, 1, NativeSearchDebt::PROFILE);
        $request = $requests->authorize($input)['requestId'];
        $outcome = new OutcomeInput('tenant', $usage, 'execution', $request, 'success', 400000, 0, 0,
            (object) ['source' => 'provider-native-search']);
        (new Outcomes($this->lock($pdo)))->record($outcome);
        if ($siblings) (new Outcomes($this->lock($pdo)))->record(new OutcomeInput('tenant', $usage, 'execution', $failed,
            'infrastructureFailure', null, null, null, (object) ['source' => 'later-provider-failure']));
        return [$reservations, $funding, $usage, $requests, $outcome];
    }

    #[DataProvider('dialects')]
    public function testNativeSearchDebtKeepsSuccessfulMainUsageWhenAnotherRequestFails(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [, $funding, $usage] = $this->nativeDebtFixture($pdo, true);
            $receipt = (new Settlements($this->lock($pdo), $funding))->settle('tenant', $usage, 'execution');
            $this->assertSame('15.0002', $receipt['settledCredits']);
            $this->assertSame('-5.0002', $this->balanceStatus($pdo)->inspect('tenant')['balance']);
            $this->assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM credit_request WHERE billing_state='billable'")->fetchColumn());
            $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM credit_request WHERE billing_state='waived'")->fetchColumn());
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testNativeSearchDebtSettlesOnceSurvivesExpiryAndFundingRepaysFirst(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$reservations, $funding, $usage, $requests, $outcome] = $this->nativeDebtFixture($pdo);
            foreach ([fn () => $requests->authorize($this->request($usage, 'after-overrun')),
                fn () => $reservations->reserve($this->admission('concurrent-operation'))] as $dispatch) {
                try { $dispatch(); $this->fail('Unsettled overrun permitted new inference.'); } catch (Conflict) {}
            }
            $settlements = new Settlements($this->lock($pdo), $funding);
            $receipt = $settlements->settle('tenant', $usage, 'execution');
            $this->assertSame('15.0000', $receipt['settledCredits']);
            $this->assertSame('-5.0000', $this->balanceStatus($pdo)->inspect('tenant')['availableCredits']);
            $this->assertSame('5.0000', (string) NativeSearchDebt::outstanding($pdo, 'tenant'));
            $this->assertAuditHealthy($pdo);
            $before = $this->auditSnapshot($pdo);
            (new Outcomes($this->lock($pdo)))->record($outcome);
            $this->assertSame($receipt, $settlements->settle('tenant', $usage, 'execution'));
            $this->assertSame($before, $this->auditSnapshot($pdo));
            $this->now = '2026-10-11 00:00:00';
            $funding->expire('tenant');
            $this->assertSame('-5.0000', $this->balanceStatus($pdo)->inspect('tenant')['balance']);
            $grant = $funding->grant($this->input('partial-payment', '3'));
            $this->assertSame($grant, $funding->grant($this->input('partial-payment', '3')));
            $this->assertSame('-2.0000', $this->balanceStatus($pdo)->inspect('tenant')['availableCredits']);
            try { $reservations->reserve($this->admission('still-negative')); $this->fail('Debt bypassed.'); } catch (Conflict) {}
            $funding->grant($this->input('complete-payment', '4'));
            $this->assertSame('0.0000', (string) NativeSearchDebt::outstanding($pdo, 'tenant'));
            $this->assertConsistent($pdo, '2.0000');
            $this->assertAuditHealthy($pdo);
            $this->assertSame('held', $reservations->reserve($this->admission('funded-again'))['state']);
        });
    }

    #[DataProvider('dialects')]
    public function testNativeSearchDebtConcurrentSettlementAndFundingRemainExactlyOnce(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            [, , $usage] = $this->nativeDebtFixture($pdo);
            $results = $this->race($pdo, $dialect, [
                ['settle', $usage, '', ''], ['settle', $usage, '', ''], ['grant', 'repayment', '7', ''],
            ]);
            $this->assertCount(3, array_column($results, 'result'));
            $this->assertSame($results[0], $results[1]);
            $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM credit_transaction WHERE type='nativeSearchOverrun'")->fetchColumn());
            $this->assertConsistent($pdo, '2.0000');
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testNativeSearchDebtTransferFailureRollsBackSettlement(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            [, $funding, $usage] = $this->nativeDebtFixture($pdo);
            $before = $this->auditSnapshot($pdo);
            $pdo->exec("ALTER TABLE credit_transaction ADD CONSTRAINT injected_failure CHECK (type <> 'searchDebtRecovery')");
            try {
                (new Settlements($this->lock($pdo), $funding))->settle('tenant', $usage, 'execution');
                $this->fail('Half a debt repayment committed.');
            } catch (PDOException) {
                $this->assertSame($before, $this->auditSnapshot($pdo));
            } finally {
                $pdo->exec('ALTER TABLE credit_transaction DROP ' . ($dialect === 'Mysql' ? 'CHECK' : 'CONSTRAINT') . ' injected_failure');
            }
            $this->assertSame('15.0000', (new Settlements($this->lock($pdo), $funding))->settle('tenant', $usage, 'execution')['settledCredits']);
            $this->assertAuditHealthy($pdo);
        });
    }
}
