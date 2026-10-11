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
        return $this->walletLock->run($input->tenantId, function (PDO $pdo, array $wallet, string $now) use ($input): array {
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
            // Pending usage contributes no accrued price. Preserve original/partial evidence and all assigned holds.
            $pdo->prepare('UPDATE credit_request SET reconciliation_record = ?, metering_state = ?, billing_state = ?, waiver_reason = ? WHERE id = ?')
                ->execute([json_encode($record, JSON_THROW_ON_ERROR), $request['metering_state'], $request['billing_state'],
                    $request['waiver_reason'], $input->requestId]);
            return $this->receipt($request, $record);
        });
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
