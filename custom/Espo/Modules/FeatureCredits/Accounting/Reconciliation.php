<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\NotFound;
use PDO;

/** Trusted recovery audit and request-scoped waiver. Provider lookups happen outside financial locks. */
final class Reconciliation
{
    public function __construct(private WalletLock $walletLock) {}

    public function recordUnavailable(ReconciliationInput $input): array
    {
        return $this->walletLock->run($input->tenantId,
            fn (PDO $pdo, array $wallet, string $now): array => $this->recordLocked($pdo, $input, $now));
    }

    /** Policy-authorized review of durable Google SDK cancellations with no
     * response identity. This is a mailbox lookup, NOT a fabricated provider API
     * lookup or a claim of zero usage. The seal + terminal SDK facts prove that
     * dispatch ended. Repeat observations/grace allow late metering to win first. */
    public function reviewSdkCancellation(string $tenantId, string $usageId, string $executionId, ReconciliationPolicy $policy): void
    {
        $this->walletLock->run($tenantId, function (PDO $pdo, array $wallet, string $now) use ($tenantId, $usageId, $executionId, $policy): void {
            $policySnapshot = get_object_vars($policy);
            ksort($policySnapshot);
            $q = $pdo->prepare('SELECT * FROM credit_execution_route WHERE tenant_id=? AND usage_id=? AND execution_id=? AND deleted=FALSE');
            $q->execute([$tenantId, $usageId, $executionId]);
            $route = $q->fetch(PDO::FETCH_ASSOC);
            if (!$route || $route['completion_requested_at'] === null || $route['completion_finished_at'] !== null) return;
            $q = $pdo->prepare("SELECT * FROM credit_request WHERE tenant_id=? AND usage_id=? AND deleted=FALSE
                AND provider='google' AND provider_request_id IS NULL AND outcome IN ('cancelled','superseded')
                AND metering_state='unknown' AND billing_state='pending' ORDER BY id");
            $q->execute([$tenantId, $usageId]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $request) {
                if ($request['completed_at'] === null || $request['outcome_record'] === null) continue;
                $history = json_decode($request['reconciliation_record'] ?? 'null', true, 512, JSON_THROW_ON_ERROR);
                if ($history && ($history['policy'] !== $policySnapshot ||
                    ($history['nextAttemptAt'] ?? $now) > $now)) continue;
                $q = $pdo->prepare('SELECT * FROM credit_execution_attempt WHERE tenant_id=? AND usage_id=? AND request_id=? AND deleted=FALSE');
                $q->execute([$tenantId, $usageId, $request['id']]);
                $attempts = $q->fetchAll(PDO::FETCH_ASSOC);
                if (count($attempts) !== 1) continue;
                $attempt = $attempts[0];
                $intent = json_decode($attempt['intent'], false, 512, JSON_THROW_ON_ERROR);
                DispatchInput::intent($intent);
                if (!in_array($intent->kind, ['stream', 'generate'], true) || $intent->scope->tenantId !== $tenantId || $intent->scope->usageId !== $usageId ||
                    $intent->scope->executionId !== $executionId || $intent->scope->runId !== $route['run_id'] ||
                    $intent->scope->workflowRunId !== $route['workflow_run_id'] || $intent->requestKey !== $request['request_key']) continue;
                $q = $pdo->prepare('SELECT * FROM credit_outcome_delivery WHERE attempt_id=? ORDER BY phase');
                $q->execute([$attempt['id']]);
                $deliveries = $q->fetchAll(PDO::FETCH_ASSOC);
                // A saved resolution, even one not yet delivered, must be processed rather than waived.
                if (count($deliveries) !== 1 || $deliveries[0]['deleted'] || $deliveries[0]['tenant_id'] !== $tenantId || (int) $deliveries[0]['phase'] !== 0) continue;
                $facts = json_decode($deliveries[0]['facts'], false, 512, JSON_THROW_ON_ERROR);
                $terminal = DispatchInput::terminal($intent->scope, $facts);
                $outcome = json_decode($request['outcome_record'], true, 512, JSON_THROW_ON_ERROR);
                if ($terminal->outcome->hash !== ($outcome['initial']['hash'] ?? null) || isset($outcome['resolution']) ||
                    $facts->requestId !== $request['id'] || $facts->outcome !== $request['outcome'] ||
                    $facts->inputTokens !== null || $facts->outputTokens !== null || $facts->cachedInputTokens !== null ||
                    ($facts->cacheWriteInputTokens ?? null) !== null ||
                    ($facts->providerRequestId ?? null) !== null || ($facts->evidence->source ?? null) !== 'vercel-ai-sdk-v4' ||
                    ($facts->evidence->completion ?? null) !== ($intent->kind === 'stream' ? 'stream-open-error' : 'generate-error') || ($facts->evidence->dispatched ?? null) !== true ||
                    ($facts->evidence->requestHash ?? null) !== $intent->requestHash) continue;
                $number = count($history['attempts'] ?? []) + 1;
                $evidence = (object) ['source' => 'crm-durable-sdk-outcome-review-v1',
                    'reference' => $request['id'] . ':sdk-review:' . $number . ':' . $now,
                    'reason' => 'Configured cancellation policy: sealed Google stream ended before any response identity or metering; durable SDK mailbox has no recovered usage.',
                    'deliveryId' => $deliveries[0]['id'], 'facts' => $facts, 'completionRequestedAt' => $route['completion_requested_at']];
                $this->recordLocked($pdo, new ReconciliationInput($tenantId, $usageId, $executionId, $request['id'],
                    'sdk-review-' . $number, $policy->operator, $now, $policy, $evidence), $now);
            }
        });
    }

    /**
     * Policy-authorized recovery of inFlight AI requests whose orchestrator workflow
     * has already reached a durable terminal state (COMPLETED/FAILED/CANCELLED) and
     * whose attempt has no delivery mailbox record. Records a terminal cancelled outcome
     * first (with the route's terminal workflow evidence), then reconciles under policy.
     */
    public function reviewAbandonedInFlight(
        string $tenantId,
        string $usageId,
        string $executionId,
        ReconciliationPolicy $policy,
        Outcomes $outcomes,
    ): void {
        $candidates = $this->walletLock->run($tenantId, function (PDO $pdo, array $wallet, string $now) use ($tenantId, $usageId, $executionId, $policy): array {
            $q = $pdo->prepare('SELECT * FROM credit_execution_route WHERE tenant_id=? AND usage_id=? AND execution_id=? AND deleted=FALSE');
            $q->execute([$tenantId, $usageId, $executionId]);
            $route = $q->fetch(PDO::FETCH_ASSOC);
            if (!$route || $route['completion_requested_at'] === null || $route['completion_finished_at'] !== null ||
                $route['terminal_workflow_evidence'] === null) {
                return [];
            }
            $terminal = json_decode($route['terminal_workflow_evidence'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($terminal) || !in_array($terminal['status'] ?? null, ['COMPLETED', 'FAILED', 'CANCELLED'], true)) {
                return [];
            }
            if (self::after($route['completion_requested_at'], $policy->deadlineSeconds) > $now) {
                return [];
            }
            $q = $pdo->prepare("SELECT * FROM credit_request WHERE tenant_id=? AND usage_id=? AND deleted=FALSE
                AND outcome='inFlight' AND metering_state='unknown' AND billing_state='pending'
                AND completed_at IS NULL AND provider_request_id IS NULL ORDER BY id");
            $q->execute([$tenantId, $usageId]);
            $out = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $request) {
                $aq = $pdo->prepare('SELECT id FROM credit_execution_attempt WHERE tenant_id=? AND usage_id=? AND request_id=? AND deleted=FALSE');
                $aq->execute([$tenantId, $usageId, $request['id']]);
                $attempts = $aq->fetchAll(PDO::FETCH_ASSOC);
                if (count($attempts) !== 1) continue;
                $dq = $pdo->prepare('SELECT COUNT(*) FROM credit_outcome_delivery WHERE attempt_id=?');
                $dq->execute([$attempts[0]['id']]);
                if ((int) $dq->fetchColumn() !== 0) continue;
                $snapshot = json_decode($request['pricing_snapshot'], true, 512, JSON_THROW_ON_ERROR);
                $pricesCacheWrites = ($snapshot['formula'] ?? null) === \Espo\Modules\FeatureCredits\Pricing\AiRate::FORMULA_V2;
                $out[] = [
                    'requestId' => $request['id'],
                    'runId' => $route['run_id'],
                    'workflowRunId' => $route['workflow_run_id'],
                    'completionRequestedAt' => $route['completion_requested_at'],
                    'terminal' => $terminal,
                    'pricesCacheWrites' => $pricesCacheWrites,
                ];
            }
            return $out;
        });

        foreach ($candidates as $c) {
            $identity = new ExecutionIdentity($c['runId'], $c['workflowRunId'], $executionId);
            $evidence = (object) [
                'source' => 'crm-terminal-workflow-abandonment-v1',
                'completion' => 'worker-abandoned-inflight',
                'dispatched' => true,
                'terminalWorkflowStatus' => $c['terminal']['status'],
                'workflowRunId' => $c['workflowRunId'],
                'completionRequestedAt' => $c['completionRequestedAt'],
            ];
            $outcomes->record(new OutcomeInput(
                $tenantId,
                $usageId,
                $executionId,
                $c['requestId'],
                'cancelled',
                null,
                null,
                null,
                $evidence,
                null,
                $c['pricesCacheWrites'] ? null : false,
            ), $identity);
        }

        // Also advance reconciliation on any previously abandoned cancelled requests for this route.
        $this->walletLock->run($tenantId, function (PDO $pdo, array $wallet, string $now) use ($tenantId, $usageId, $executionId, $policy): void {
            $policySnapshot = get_object_vars($policy);
            ksort($policySnapshot);
            $q = $pdo->prepare('SELECT * FROM credit_execution_route WHERE tenant_id=? AND usage_id=? AND execution_id=? AND deleted=FALSE');
            $q->execute([$tenantId, $usageId, $executionId]);
            $route = $q->fetch(PDO::FETCH_ASSOC);
            if (!$route || $route['completion_requested_at'] === null || $route['completion_finished_at'] !== null ||
                $route['terminal_workflow_evidence'] === null) return;
            $q = $pdo->prepare("SELECT * FROM credit_request WHERE tenant_id=? AND usage_id=? AND deleted=FALSE
                AND provider_request_id IS NULL AND outcome='cancelled' AND metering_state='unknown' AND billing_state='pending' ORDER BY id");
            $q->execute([$tenantId, $usageId]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $request) {
                if ($request['completed_at'] === null || $request['outcome_record'] === null) continue;
                $outcome = json_decode($request['outcome_record'], true, 512, JSON_THROW_ON_ERROR);
                if (($outcome['initial']['input']['evidence']['source'] ?? null) !== 'crm-terminal-workflow-abandonment-v1' || isset($outcome['resolution'])) continue;
                $history = json_decode($request['reconciliation_record'] ?? 'null', true, 512, JSON_THROW_ON_ERROR);
                if ($history && ($history['policy'] !== $policySnapshot || ($history['nextAttemptAt'] ?? $now) > $now)) continue;
                $number = count($history['attempts'] ?? []) + 1;
                $evidence = (object) [
                    'source' => 'crm-terminal-workflow-abandonment-review-v1',
                    'reference' => $request['id'] . ':abandoned-review:' . $number . ':' . $now,
                    'reason' => 'Configured policy: orchestrator workflow reached terminal state while request remained inFlight with no delivery mailbox record.',
                    'completionRequestedAt' => $route['completion_requested_at'],
                ];
                $this->recordLocked($pdo, new ReconciliationInput($tenantId, $usageId, $executionId, $request['id'],
                    'abandoned-review-' . $number, $policy->operator, $now, $policy, $evidence), $now);
            }
        });
    }

    private function recordLocked(PDO $pdo, ReconciliationInput $input, string $now): array
    {
        $query = $pdo->prepare('SELECT * FROM credit_usage WHERE id = ? AND tenant_id = ?');
        $query->execute([$input->usageId, $input->tenantId]);
        $usage = $query->fetch(PDO::FETCH_ASSOC);
        if (!$usage || $usage['deleted']) {
            throw new NotFound('Unknown credit operation.');
        }
        if ($usage['execution_id'] !== $input->executionId || $usage['operation_type'] !== 'ai' ||
            $usage['billing_regime'] !== 'unified-prepaid-v1') {
            throw new Conflict('Reconciliation does not own a unified AI operation.');
        }
        $query = $pdo->prepare('SELECT * FROM credit_request WHERE id = ? AND usage_id = ? AND tenant_id = ?');
        $query->execute([$input->requestId, $input->usageId, $input->tenantId]);
        $request = $query->fetch(PDO::FETCH_ASSOC);
        if (!$request || $request['deleted']) {
            throw new NotFound('Unknown authorized request.');
        }
        $record = $request['reconciliation_record'] === null ? null :
            json_decode($request['reconciliation_record'], true, 512, JSON_THROW_ON_ERROR);
        foreach ($record['attempts'] ?? [] as $attempt) {
            if ($attempt['input']['attemptKey'] === $input->attemptKey) {
                if ($attempt['hash'] !== $input->hash) {
                    throw new Conflict('Conflicting reconciliation attempt replay.');
                }
                return $this->receipt($request, $record);
            }
        }
        $query = $pdo->prepare('SELECT * FROM credit_reservation WHERE id = ? AND usage_id = ? AND tenant_id = ?');
        $query->execute([$request['reservation_id'], $input->usageId, $input->tenantId]);
        $reservation = $query->fetch(PDO::FETCH_ASSOC);
        if (!$reservation || $reservation['deleted'] || $reservation['state'] !== 'held' ||
            $reservation['execution_id'] !== $input->executionId || $usage['state'] !== 'admitted') {
            throw new Conflict('Operation is not open for reconciliation.');
        }
        if (!in_array($request['outcome'], ['cancelled', 'superseded'], true) ||
            $request['metering_state'] !== 'unknown' || $request['billing_state'] !== 'pending' ||
            $request['completed_at'] === null || $request['outcome_record'] === null ||
            $request['priced_credits_exact'] !== null || $request['waiver_reason'] !== null) {
            throw new Conflict('Only terminal cancellation with unknown metering may be reconciled as unavailable.');
        }
        $payload = json_decode($input->json, true, 512, JSON_THROW_ON_ERROR);
        $record ??= ['policy' => $payload['policy'],
            'deadlineAt' => self::after($request['completed_at'], $input->policy->deadlineSeconds), 'attempts' => []];
        if ($record['policy'] !== $payload['policy']) {
            throw new Conflict('Reconciliation policy and owner are immutable for this request.');
        }
        $earliest = $record['nextAttemptAt'] ?? $request['completed_at'];
        if ($input->observedAt < $earliest || $input->observedAt > $now) {
            throw new Conflict('Lookup observation violates reconciliation cadence or server time.');
        }
        foreach ($record['attempts'] as $attempt) {
            if ($attempt['input']['evidence']['source'] === $payload['evidence']['source'] &&
                $attempt['input']['evidence']['reference'] === $payload['evidence']['reference']) {
                throw new Conflict('A lookup evidence reference cannot count as another attempt.');
            }
        }
        $record['attempts'][] = ['hash' => $input->hash, 'recordedAt' => $now, 'input' => $payload];
        $waive = count($record['attempts']) >= $input->policy->minimumAttempts && $input->observedAt >= $record['deadlineAt'];
        $record['nextAttemptAt'] = $waive ? null : self::after($input->observedAt, $input->policy->retrySeconds);
        if ($waive) {
            $record['waivedAt'] = $now;
            $request['metering_state'] = 'unrecoverable';
            $request['billing_state'] = 'waived';
            $request['waiver_reason'] = 'unrecoverable_cancellation';
        }
        // Preserve original/partial evidence. Only a completed waiver releases its request hold.
        $pdo->prepare('UPDATE credit_request SET reconciliation_record = ?, metering_state = ?, billing_state = ?, waiver_reason = ? WHERE id = ?')
            ->execute([json_encode($record, JSON_THROW_ON_ERROR), $request['metering_state'], $request['billing_state'],
                $request['waiver_reason'], $input->requestId]);
        RequestHolds::trim($pdo, $input->tenantId, $input->requestId, $now);
        return $this->receipt($request, $record);
    }

    private static function after(string $time, int $seconds): string
    {
        return (new DateTimeImmutable($time, new DateTimeZone('UTC')))->modify('+' . $seconds . ' seconds')->format('Y-m-d H:i:s');
    }

    private function receipt(array $request, array $record): array
    {
        return ['requestId' => $request['id'], 'meteringState' => $request['metering_state'],
            'billingState' => $request['billing_state'], 'waiverReason' => $request['waiver_reason'],
            'attemptCount' => count($record['attempts']), 'deadlineAt' => $record['deadlineAt'],
            'nextAttemptAt' => $request['billing_state'] === 'pending' ? $record['nextAttemptAt'] : null,
            'operator' => $record['policy']['operator']];
    }
}
