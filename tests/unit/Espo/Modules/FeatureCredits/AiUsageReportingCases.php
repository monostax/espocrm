<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use DateTimeImmutable;
use Espo\Modules\FeatureAiUsage\Services\{Credits, Dataset, Ledger, Period};
use Espo\Modules\FeatureCredits\Accounting\{ReversalInput, Funding, Requests, Outcomes, OutcomeInput, Settlements};
use Espo\ORM\EntityManager;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

trait AiUsageReportingCases
{
    #[DataProvider('dialects')]
    public function testAiUsageReportingNativeDebtTransfersAreNotAdditionalConsumption(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$reversals, $debit, $funding, $settled] = $this->nativeCorrectionFixture($pdo);
            $reader = $this->aiCreditReport($pdo);
            $period = Period::create('2026-10', 'UTC', new DateTimeImmutable('2026-11-01'));
            $initial = $reader->report('tenant', $period, [])['summary'];
            $this->assertSame($settled['settledCredits'], $initial['chargedCredits']);
            $funding->grant($this->input('repay', '5', expires: '2026-10-11 00:00:00'));
            $this->assertSame($initial, $reader->report('tenant', $period, [])['summary']);
            $this->now = '2026-10-11 00:00:00';
            $amount = (string) \Espo\Modules\FeatureCredits\Accounting\Amount::fromString('0')
                ->minus(\Espo\Modules\FeatureCredits\Accounting\Amount::fromString($debit['credits']));
            $reversals->reverse($this->nativeCorrection($debit, $amount));
            $updated = $reader->report('tenant', $period, [])['summary'];
            $this->assertSame($initial['chargedCredits'], $updated['chargedCredits']);
            $this->assertSame($amount, $updated['reversedCredits']);
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testAiUsageReportingRejectsForeignOrDeletedRouteAndMissingPostedParent(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [, $debit] = $this->reversalFixture($pdo);
            $this->reportingRoute($pdo, 'run', $debit['usage_id']);
            $reader = $this->aiCreditReport($pdo);
            $period = Period::create('2026-10', 'UTC', new DateTimeImmutable('2026-11-01'));
            $runs = [['id' => 'run', 'runAt' => $this->now]];
            try { $reader->report('other', $period, $runs); $this->fail('Foreign route accepted.'); }
            catch (LogicException) { $this->assertFalse($pdo->inTransaction()); }
            $pdo->exec('UPDATE credit_execution_route SET deleted=TRUE');
            try { $reader->report('tenant', $period, $runs); $this->fail('Deleted route accepted.'); }
            catch (LogicException) { $this->assertFalse($pdo->inTransaction()); }
            $pdo->exec("UPDATE credit_transaction SET usage_id='missing' WHERE type='debit'");
            try { $reader->report('tenant', $period, []); $this->fail('Missing posted parent accepted.'); }
            catch (LogicException) { $this->assertFalse($pdo->inTransaction()); }
        });
    }

    private function aiCreditReport(PDO $pdo): Credits
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($pdo);
        return new Credits($em);
    }

    private function reportingRoute(PDO $pdo, string $run, ?string $usage, string $regime = 'unified-prepaid-v1'): void
    {
        $pdo->prepare('INSERT INTO credit_execution_route
            (id,tenant_id,run_id,workflow_run_id,execution_id,billing_regime,usage_id,admitted_at)
            VALUES (?,?,?,?,?,?,?,?)')->execute([$run, 'tenant', $run, 'workflow-' . $run, 'execution', $regime, $usage, $this->now]);
    }

    #[DataProvider('dialects')]
    public function testAiUsageReportingPostedCorrectionsMissingTelemetryAndCutover(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$reversals, $debit] = $this->reversalFixture($pdo);
            $this->reportingRoute($pdo, 'unified', $debit['usage_id']);
            $this->reportingRoute($pdo, 'legacy', null, 'legacy-engagement-v1');
            $pdo->exec("INSERT INTO tenant_credit_cutover (id,tenant_id,cutover_at,input_hash,evidence,created_at)
                VALUES ('cutover','tenant','2026-10-10 00:00:00','hash','{}','2026-10-09 00:00:00')");
            $period = Period::create('2026-10', 'America/Sao_Paulo', new DateTimeImmutable('2026-12-01'));
            $runs = array_map(fn ($id) => ['id' => $id, 'runAt' => '2026-10-10 12:00:00',
                'kind' => 'customer-message', 'conversationId' => 'conversation', 'runOutcome' => 'failed', 'billingWaived' => true],
                ['unified', 'legacy', 'missing']);
            $reader = $this->aiCreditReport($pdo);
            $before = $this->auditSnapshot($pdo);
            $report = $reader->report('tenant', $period, $runs);
            $this->assertSame($before, $this->auditSnapshot($pdo));
            $this->assertSame(['unified-prepaid-v1', 'legacy-engagement-v1', 'unattributed'], array_column($report['runs'], 'billingRegime'));
            $this->assertSame('0.1875', $report['summary']['chargedCredits']);
            $this->assertSame('0.1875', $report['runs'][0]['creditBilling']['settledCredits']);
            $this->assertNull(Ledger::exemption($report['runs'][0]));
            $this->assertSame('waived', Ledger::exemption($report['runs'][1]));
            $this->assertSame($report['summary'], $reader->report('tenant', $period, [])['summary']);
            $this->assertSame('0.0000', $reader->report('other', $period, [])['summary']['chargedCredits']);
            $this->now = '2026-11-01 04:00:00';
            $input = new ReversalInput('tenant', $debit['id'], 'report-correction', '0.1', 'Correction', 'admin');
            $reversals->reverse($input);
            $reversals->reverse($input);
            $updated = $reader->report('tenant', $period, $runs);
            $this->assertSame($report['summary'], $updated['summary']);
            $this->assertSame('0.1875', $updated['runs'][0]['creditBilling']['settledCredits']);
            $this->assertSame('0.0875', $updated['runs'][0]['creditBilling']['netCredits']);
            $november = Period::create('2026-11', 'America/Sao_Paulo', new DateTimeImmutable('2026-12-01'));
            $this->assertSame('-0.1000', $reader->report('tenant', $november, [])['summary']['netCredits']);
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testAiUsageReportingUnknownThenSettlementNeverLegacyReprices(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input());
            $usage = $reservations->reserve($this->admission())['usageId'];
            $this->reportingRoute($pdo, 'pending', $usage);
            $id = (new Requests($this->lock($pdo), $funding))->authorize($this->request($usage))['requestId'];
            $outcomes = new Outcomes($this->lock($pdo));
            $outcomes->record(new OutcomeInput('tenant', $usage, 'execution', $id, 'cancelled', null, null, null, (object) ['source' => 'provider']));
            $period = Period::create('2026-10', 'UTC', new DateTimeImmutable('2026-11-01'));
            $runs = [['id' => 'pending', 'runAt' => $this->now, 'kind' => 'customer-message', 'conversationId' => 'c']];
            $reader = $this->aiCreditReport($pdo);
            $report = $reader->report('tenant', $period, $runs);
            $this->assertNull($report['runs'][0]['creditBilling']['settledCredits']);
            $ledger = (new Ledger())->build($report['runs'], $period, [], 'tenant');
            $this->assertSame([], $ledger['groups']);
            $this->assertSame(1, (new Dataset())->stats($ledger['runs'])['pendingRuns']);
            $outcomes->record(new OutcomeInput('tenant', $usage, 'execution', $id, 'cancelled', 0, 0, 1, (object) ['source' => 'provider']));
            $settlements = new Settlements($this->lock($pdo), $funding);
            $settlements->settle('tenant', $usage, 'execution');
            $settlements->settle('tenant', $usage, 'execution');
            $report = $reader->report('tenant', $period, $runs);
            $this->assertSame('0.0002', $report['runs'][0]['creditBilling']['settledCredits']);
            $ledger = (new Ledger())->build($report['runs'], $period, [], 'tenant');
            $this->assertSame(0, (new Dataset())->stats($ledger['runs'])['pendingRuns']);
            $pdo->exec("UPDATE credit_transaction SET deleted=TRUE WHERE type='debit'");
            try { $reader->report('tenant', $period, $runs); $this->fail('Deleted posting accepted.'); }
            catch (LogicException) { $this->assertFalse($pdo->inTransaction()); }
        });
    }
}
