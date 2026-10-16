<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\Conflict;
use Espo\Modules\FeatureCredits\Accounting\{DispatchInput, Funding, OutcomeInput, Outcomes, Requests, Settlements};
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

trait RequestHoldsCases
{
    #[DataProvider('dialects')]
    public function testRollingHoldsAllowLongRunAndPreserveOnlyUnknownBoundAndExactUsage(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input(credits: '1'));
            $usage = $reservations->reserve($this->admission(credits: '0.1875'))['usageId'];
            $requests = new Requests($this->lock($pdo), $funding);
            $outcomes = new Outcomes($this->lock($pdo));
            for ($i = 0; $i < 60; $i++) {
                $id = $requests->authorize($this->request($usage, 'step-' . $i))['requestId'];
                $input = new OutcomeInput('tenant', $usage, 'execution', $id, 'success', 0, 0, 1, (object) ['source' => 'provider']);
                $outcomes->record($input);
            }
            $this->assertHeld($pdo, '0.0120');
            $before = $this->auditSnapshot($pdo);
            $outcomes->record($input);
            $this->assertSame($before, $this->auditSnapshot($pdo), 'Replay must not release twice.');
            $pending = $requests->authorize($this->request($usage, 'cancelled'))['requestId'];
            $outcomes->record(new OutcomeInput('tenant', $usage, 'execution', $pending, 'cancelled', null, null, null, (object) ['source' => 'provider']));
            $this->assertHeld($pdo, '0.1995');
            $this->assertAuditHealthy($pdo);
            $settlement = new Settlements($this->lock($pdo), $funding);
            try { $settlement->settle('tenant', $usage, 'execution'); $this->fail('Unknown request was charged or released.'); } catch (Conflict) {}
            $outcomes->record(new OutcomeInput('tenant', $usage, 'execution', $pending, 'cancelled', 0, 0, 0, (object) ['source' => 'provider-resolution']));
            $this->assertSame('0.0113', $settlement->settle('tenant', $usage, 'execution')['settledCredits']);
            $this->assertConsistent($pdo, '0.9887');
            $this->assertHeld($pdo, '0.0000');
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testHoldTrimExpiresOriginalLotsAndRollsBackWithOutcomeOnFailure(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $reservations = $this->reservations($pdo);
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input(credits: '1', expires: '2026-10-11 00:00:00'));
            $usage = $reservations->reserve($this->admission(credits: '0.1875'))['usageId'];
            $id = (new Requests($this->lock($pdo), $funding))->authorize($this->request($usage))['requestId'];
            $input = new OutcomeInput('tenant', $usage, 'execution', $id, 'success', 0, 0, 1, (object) ['source' => 'provider']);
            $before = $this->auditSnapshot($pdo);
            $pdo->exec("ALTER TABLE credit_allocation ADD CONSTRAINT injected_trim_failure CHECK (allocation_key NOT LIKE 'trim:%')");
            try { (new Outcomes($this->lock($pdo)))->record($input); $this->fail('Injected trim failure ignored.'); }
            catch (PDOException) { $this->assertSame($before, $this->auditSnapshot($pdo)); }
            finally { $pdo->exec('ALTER TABLE credit_allocation DROP ' . self::dropCheckKeyword($pdo) . ' injected_trim_failure'); }
            $this->now = '2026-10-11 00:00:00';
            (new Outcomes($this->lock($pdo)))->record($input);
            $this->assertHeld($pdo, '0.3127'); // Includes the unused 0.5000 minimum-admission buffer.
            $this->assertSame('0.1873', $pdo->query('SELECT SUM(expired_credits) FROM credit_allocation')->fetchColumn());
            $this->assertAuditHealthy($pdo);
            $receipt = (new Settlements($this->lock($pdo), $funding))->settle('tenant', $usage, 'execution');
            $this->assertSame('0.0002', $receipt['settledCredits']);
            $this->assertConsistent($pdo, '0.0000');
            $this->assertAuditHealthy($pdo);
        });
    }

    private function sdkCancellationFixture(PDO $pdo, ?callable $adjust = null, bool $seal = true): array
    {
        $policy = ExecutionFixtures::policy(); $policy['provider'] = 'google'; $policy['model'] = 'gemini-3.8-flash';
        [$intent, $binding, $facts] = $this->dispatchFixture($pdo, $policy);
        $intent->kind = $binding->kind = 'stream';
        $facts->outcome = 'cancelled';
        $facts->inputTokens = $facts->cachedInputTokens = $facts->outputTokens = null;
        $facts->evidence = (object) ['source' => 'vercel-ai-sdk-v4', 'requestHash' => $intent->requestHash,
            'completion' => 'stream-open-error', 'dispatched' => true];
        if ($adjust) $adjust($facts);
        $this->dispatch($pdo, 'claimIntent', ['intent' => $intent]);
        $this->dispatch($pdo, 'bind', ['binding' => $binding]);
        $this->dispatch($pdo, 'enqueue', ['binding' => $binding, 'facts' => $facts, 'phase' => 0]);
        if ($seal) $this->dispatch($pdo, 'seal', ['scope' => $intent->scope]);
        return [$intent, $binding, $facts];
    }

    private function sdkCancellationPolicy(): array
    {
        return ['tenantIds' => ['tenant'], 'version' => 'sdk-cancellation-v1', 'operator' => 'credit-completion',
            'deadlineSeconds' => 300, 'retrySeconds' => 60, 'minimumAttempts' => 2];
    }

    #[DataProvider('dialects')]
    public function testSdkCancellationRecoveryIsScopedSpacedAuditedAndReplaySafe(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            [$intent] = $this->sdkCancellationFixture($pdo);
            $command = new DispatchInput((object) ['operation' => 'recover', 'scope' => $intent->scope]);
            $wrongTenant = $this->sdkCancellationPolicy(); $wrongTenant['tenantIds'] = ['other'];
            foreach ([null, $wrongTenant] as $policy) {
                $this->assertSame('pending', $this->dispatchStore($pdo, $policy)->execute($command)['state']);
                $this->assertNull($pdo->query('SELECT reconciliation_record FROM credit_request')->fetchColumn());
            }
            $store = $this->dispatchStore($pdo, $this->sdkCancellationPolicy());
            $this->assertSame('pending', $store->execute($command)['state']);
            $record = $pdo->query('SELECT reconciliation_record FROM credit_request')->fetchColumn();
            $this->assertCount(1, json_decode($record, true)['attempts']);
            $this->now = '2026-10-10 12:00:30';
            $this->assertSame('pending', $store->execute($command)['state']);
            $this->assertSame($record, $pdo->query('SELECT reconciliation_record FROM credit_request')->fetchColumn());
            $this->now = '2026-10-10 12:05:00';
            $receipt = $store->execute($command);
            $this->assertSame('settled', $receipt['state']);
            $this->assertSame('0.0000', $receipt['receipt']['settledCredits']);
            $this->assertSame('unrecoverable_cancellation', $pdo->query('SELECT waiver_reason FROM credit_request')->fetchColumn());
            $this->assertSame($receipt, $store->execute($command));
            $this->assertHeld($pdo, '0.0000');
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testSdkCancellationRecoveryRetainsLateMeasuredUsage(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            [$intent, $binding, $facts] = $this->sdkCancellationFixture($pdo);
            $command = new DispatchInput((object) ['operation' => 'recover', 'scope' => $intent->scope]);
            $store = $this->dispatchStore($pdo, $this->sdkCancellationPolicy());
            $this->assertSame('pending', $store->execute($command)['state']);
            $this->assertSame('delivered', $this->delivery($pdo));
            $this->now = '2026-10-10 12:05:00';
            $facts->inputTokens = $facts->cachedInputTokens = 0; $facts->outputTokens = 1;
            $facts->evidence->completion = 'provider-resolution';
            $this->dispatch($pdo, 'enqueue', ['binding' => $binding, 'facts' => $facts, 'phase' => 1]);
            $receipt = $store->execute($command);
            $this->assertSame('settled', $receipt['state']);
            $this->assertSame('0.0002', $receipt['receipt']['settledCredits']);
            $this->assertNull($pdo->query('SELECT waiver_reason FROM credit_request')->fetchColumn());
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testSdkCancellationRecoveryNeverWaivesOutsideItsEvidenceContract(string $dialect): void
    {
        foreach (['unsealed', 'response-id', 'partial-metering', 'other-completion', 'other-source', 'success'] as $case) {
            $this->executionDatabase($dialect, function (PDO $pdo) use ($case): void {
                [$intent] = $this->sdkCancellationFixture($pdo, function (object $facts) use ($case): void {
                    if ($case === 'response-id') $facts->providerRequestId = 'provider-response';
                    if ($case === 'partial-metering') $facts->inputTokens = $facts->cachedInputTokens = 0;
                    if ($case === 'other-completion') $facts->evidence->completion = 'stream-cancel';
                    if ($case === 'other-source') $facts->evidence->source = 'unverified-source';
                    if ($case === 'success') $facts->outcome = 'success';
                }, $case !== 'unsealed');
                $store = $this->dispatchStore($pdo, $this->sdkCancellationPolicy());
                $command = new DispatchInput((object) ['operation' => 'recover', 'scope' => $intent->scope]);
                foreach (['2026-10-11 00:00:00', '2026-10-11 00:05:00'] as $now) {
                    $this->now = $now;
                    if ($case === 'unsealed') {
                        try { $store->execute($command); $this->fail('Unsealed operation entered completion recovery.'); }
                        catch (Conflict) {}
                    } else {
                        $this->assertSame('pending', $store->execute($command)['state'], $case);
                    }
                }
                $this->assertNull($pdo->query('SELECT reconciliation_record FROM credit_request')->fetchColumn(), $case);
                $this->assertNull($pdo->query('SELECT waiver_reason FROM credit_request')->fetchColumn(), $case);
                $this->assertSame('held', $pdo->query('SELECT state FROM credit_reservation')->fetchColumn(), $case);
                $this->assertAuditHealthy($pdo);
            });
        }
    }
}
