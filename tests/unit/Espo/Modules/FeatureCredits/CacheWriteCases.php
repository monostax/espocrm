<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\Conflict;
use Espo\Modules\FeatureCredits\Accounting\{CutoverInput, Funding, OutcomeInput, Outcomes, Requests, Settlements};
use Espo\Modules\FeatureCredits\Pricing\AiModelPolicy;
use Espo\Modules\FeatureCredits\Pricing\AiRate;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

/** Formula ai-per-10000-v2: real accounting on both dialects, plus the unchanged v1 path. */
trait CacheWriteCases
{
    private function cacheWritePolicy(): AiModelPolicy
    {
        return new AiModelPolicy(['provider' => 'openrouter', 'model' => 'anthropic/claude-sonnet-5.5', 'multiplier' => '2.4',
            'inputTokenBound' => 1000, 'outputTokenLimit' => 1000, 'boundProfile' => 'openrouter-incremental-bound-v1',
            'formula' => AiRate::FORMULA_V2]);
    }

    private function cacheWriteOutcome(string $usage, string $request, ?int $input, ?int $cached, ?int $output,
        int|false|null $written, string $source = 'provider'): OutcomeInput
    {
        return new OutcomeInput('tenant', $usage, 'execution', $request, 'success', $input, $cached, $output,
            (object) ['source' => $source], null, $written);
    }

    #[DataProvider('dialects')]
    public function testCacheWritePolicyHoldsAtWriteRateAndSettlesMeasuredWrites(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input(credits: '10'));
            $usage = $this->reservations($pdo)->reserve($this->admission(credits: '0.5'))['usageId'];
            $requests = new Requests($this->lock($pdo), $funding);
            $id = $requests->authorize($this->cacheWritePolicy()->request('tenant', $usage, 'execution', 'conversation.main.0'))['requestId'];
            $row = $pdo->query("SELECT authorized_credits, pricing_snapshot FROM credit_request WHERE id = '$id'")->fetch(PDO::FETCH_ASSOC);
            // (1000 x 0.46875 + 1000 x 1.875) x 2.4 / 10000
            $this->assertSame('0.5625', (string) \Espo\Modules\FeatureCredits\Accounting\Amount::fromString((string) $row['authorized_credits']));
            $snapshot = json_decode($row['pricing_snapshot'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame([AiRate::FORMULA_V2, '0.46875'], [$snapshot['formula'], $snapshot['cacheWriteInputCredits']]);

            $outcomes = new Outcomes($this->lock($pdo));
            $input = $this->cacheWriteOutcome($usage, $id, 1000, 200, 100, 500);
            $receipt = $outcomes->record($input);
            // (300 x 0.375 + 200 x 0.0375 + 500 x 0.46875 + 100 x 1.875) x 2.4 / 10000
            $this->assertSame(['billable', '0.13005'], [$receipt['billingState'], $receipt['pricedCreditsExact']]);
            $metering = json_decode((string) $pdo->query("SELECT metering FROM credit_request WHERE id = '$id'")->fetchColumn(), true);
            $this->assertSame(['inputTokens' => 1000, 'cachedInputTokens' => 200, 'outputTokens' => 100, 'cacheWriteInputTokens' => 500], $metering);
            $before = $this->auditSnapshot($pdo);
            $this->assertSame($receipt, $outcomes->record($input), 'Replay must be idempotent.');
            $this->assertSame($before, $this->auditSnapshot($pdo));
            $this->assertSame('0.1301', (new Settlements($this->lock($pdo), $funding))->settle('tenant', $usage, 'execution')['settledCredits']);
            $this->assertConsistent($pdo, '9.8699');
            $this->assertHeld($pdo, '0.0000');
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testCacheWritePolicyRequiresExplicitMeteringAndKeepsUnknownPending(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input(credits: '10'));
            $usage = $this->reservations($pdo)->reserve($this->admission(credits: '0.5'))['usageId'];
            $requests = new Requests($this->lock($pdo), $funding);
            $id = $requests->authorize($this->cacheWritePolicy()->request('tenant', $usage, 'execution', 'conversation.main.0'))['requestId'];
            $outcomes = new Outcomes($this->lock($pdo));
            $before = $this->auditSnapshot($pdo);
            // Omitted cache-write field, then input beyond the policy bound.
            foreach ([[1000, 0, 100, false], [1001, 0, 100, 0]] as [$in, $cached, $out, $written]) {
                try {
                    $outcomes->record($this->cacheWriteOutcome($usage, $id, $in, $cached, $out, $written));
                    $this->fail('Omitted cache writes or out-of-bound usage were accepted.');
                } catch (Conflict) {}
                $this->assertSame($before, $this->auditSnapshot($pdo));
            }
            $pending = $outcomes->record($this->cacheWriteOutcome($usage, $id, 1000, 0, 100, null));
            $this->assertSame(['pending', null], [$pending['billingState'], $pending['pricedCreditsExact']]);
            $settlements = new Settlements($this->lock($pdo), $funding);
            try { $settlements->settle('tenant', $usage, 'execution'); $this->fail('Unknown cache writes were settled.'); } catch (Conflict) {}
            $resolved = $outcomes->record($this->cacheWriteOutcome($usage, $id, 1000, 0, 100, 1000, 'provider-resolution'));
            // (1000 x 0.46875 + 100 x 1.875) x 2.4 / 10000
            $this->assertSame(['billable', '0.1575'], [$resolved['billingState'], $resolved['pricedCreditsExact']]);
            $this->assertSame('0.1575', $settlements->settle('tenant', $usage, 'execution')['settledCredits']);
            $this->assertConsistent($pdo, '9.8425');
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testV1PolicyRejectsCacheWritesAcceptsExplicitZeroAndKeepsOriginalMetering(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input(credits: '10'));
            $usage = $this->reservations($pdo)->reserve($this->admission(credits: '0.5'))['usageId'];
            $requests = new Requests($this->lock($pdo), $funding);
            $outcomes = new Outcomes($this->lock($pdo));
            $legacy = $requests->authorize($this->request($usage, 'legacy'))['requestId'];
            $zero = $requests->authorize($this->request($usage, 'zero'))['requestId'];
            $before = $this->auditSnapshot($pdo);
            foreach ([5, null] as $written) {
                try {
                    $outcomes->record($this->cacheWriteOutcome($usage, $legacy, 10, 0, 10, $written));
                    $this->fail('A v1 request accepted cache-write metering.');
                } catch (Conflict) {}
                $this->assertSame($before, $this->auditSnapshot($pdo));
            }
            $original = $outcomes->record($this->cacheWriteOutcome($usage, $legacy, 0, 0, 10, false));
            $explicit = $outcomes->record($this->cacheWriteOutcome($usage, $zero, 0, 0, 10, 0));
            $this->assertSame($original['pricedCreditsExact'], $explicit['pricedCreditsExact']);
            $this->assertSame('0.001875', $original['pricedCreditsExact']);
            $this->assertSame(['inputTokens' => 0, 'cachedInputTokens' => 0, 'outputTokens' => 10],
                json_decode((string) $pdo->query("SELECT metering FROM credit_request WHERE id = '$legacy'")->fetchColumn(), true));
            $history = json_decode((string) $pdo->query("SELECT outcome_record FROM credit_request WHERE id = '$legacy'")->fetchColumn(), true);
            $this->assertSame('ai-outcome-v1', $history['initial']['input']['version']);
            $this->assertArrayNotHasKey('cacheWriteInputTokens', $history['initial']['input']);
            $this->assertSame($original, $outcomes->record($this->cacheWriteOutcome($usage, $legacy, 0, 0, 10, false)));
            $this->assertSame('0.0038', (new Settlements($this->lock($pdo), $funding))->settle('tenant', $usage, 'execution')['settledCredits']);
            $this->assertAuditHealthy($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testTamperedCacheWriteSnapshotFailsClosed(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $funding = new Funding($this->lock($pdo));
            $funding->grant($this->input(credits: '10'));
            $usage = $this->reservations($pdo)->reserve($this->admission(credits: '0.5'))['usageId'];
            $id = (new Requests($this->lock($pdo), $funding))
                ->authorize($this->cacheWritePolicy()->request('tenant', $usage, 'execution', 'conversation.main.0'))['requestId'];
            $snapshot = json_decode((string) $pdo->query("SELECT pricing_snapshot FROM credit_request WHERE id = '$id'")->fetchColumn(), true);
            foreach ([['cacheWriteInputCredits' => '0.1'], ['formula' => 'ai-per-10000-v3'], ['formula' => AiRate::FORMULA]] as $edit) {
                $tampered = $edit + $snapshot;
                if (($edit['formula'] ?? null) === AiRate::FORMULA) unset($tampered['cacheWriteInputCredits']);
                $pdo->prepare('UPDATE credit_request SET pricing_snapshot = ? WHERE id = ?')->execute([json_encode($tampered), $id]);
                try {
                    (new Outcomes($this->lock($pdo)))->record($this->cacheWriteOutcome($usage, $id, 1000, 0, 100, 500));
                    $this->fail('A tampered cache-write snapshot was priced.');
                } catch (Conflict) {}
            }
        });
    }

    #[DataProvider('dialects')]
    public function testCacheWritePolicyEndToEndThroughSignedExecutionCommands(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            $service = $this->configuration($pdo);
            $main = $service->publishModel((object) ExecutionFixtures::policy());
            $definition = (object) ['provider' => 'openrouter', 'model' => 'anthropic/claude-sonnet-5.5', 'multiplier' => '2.4',
                'inputTokenBound' => 1000, 'outputTokenLimit' => 1000, 'boundProfile' => 'openrouter-incremental-bound-v1',
                'formula' => AiRate::FORMULA_V2];
            $stream = $service->publishModel($definition);
            $this->assertSame($stream, $service->publishModel($definition));
            $this->assertSame($this->cacheWritePolicy()->rate->id, $stream['policyId']);
            $this->assertArrayNotHasKey('formula', $stream['policy']);
            $this->assertSame(AiRate::FORMULA_V2, $service->inspect('model', $stream['id'])['data']['formula']);
            $this->assertSame(AiRate::FORMULA, $service->inspect('model', $main['id'])['data']['formula']);
            $agreement = $this->agreement($main['id']); $agreement->aiStreamModelCreditRateId = $stream['id'];
            $rate = $service->publishAgreement($agreement);
            $this->cutovers($pdo)->schedule(new CutoverInput('tenant', $this->now, (object) ['test' => true]));
            $command = ExecutionFixtures::admission();
            $command->operation = 'configuration'; unset($command->modelRateId, $command->billingRateId);
            $command->provider = 'openrouter'; $command->model = 'anthropic/claude-sonnet-5.5';
            $selection = $this->entityCommand($pdo, $command)['selection'];
            $this->assertSame($stream['policyId'], $selection['modelRateId']);
            (new Funding($this->lock($pdo)))->grant($this->input());
            $admit = ExecutionFixtures::admission(); $admit->billingRateId = $rate['id']; $admit->modelRateId = $stream['policyId'];
            $admitted = $this->entityCommand($pdo, $admit);
            $this->assertSame($stream['policy'], $admitted['policy']);
            $usage = $admitted['receipt']['usageId'];
            $authorize = (object) ['operation' => 'authorize', 'billingRegime' => $admit->billingRegime, 'tenantId' => 'tenant',
                'usageId' => $usage, 'runId' => $admit->runId, 'workflowRunId' => $admit->workflowRunId,
                'executionId' => $admit->executionId, 'requestKey' => 'conversation.main.0', 'modelRateId' => $stream['policyId']];
            $this->assertTrue($this->entityCommand($pdo, $authorize)['receipt']['dispatchAllowed']);
            $request = $pdo->query("SELECT id, authorized_credits FROM credit_request WHERE request_key = 'conversation.main.0'")->fetch(PDO::FETCH_ASSOC);
            $this->assertSame('0.5625', (string) \Espo\Modules\FeatureCredits\Accounting\Amount::fromString((string) $request['authorized_credits']));
            $outcome = ExecutionFixtures::outcome();
            $outcome->usageId = $usage; $outcome->requestId = $request['id']; $outcome->executionId = $admit->executionId;
            $outcome->runId = $admit->runId; $outcome->workflowRunId = $admit->workflowRunId;
            $outcome->inputTokens = 1000; $outcome->cachedInputTokens = 200; $outcome->outputTokens = 100;
            $outcome->cacheWriteInputTokens = 500; $outcome->providerRequestId = 'gen-1';
            $receipt = $this->entityCommand($pdo, $outcome)['receipt'];
            $this->assertSame(['billable', '0.13005'], [$receipt['billingState'], $receipt['pricedCreditsExact']]);
        });
    }
}
