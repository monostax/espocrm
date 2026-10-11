<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Brick\Math\BigDecimal;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\FeatureCredits\Pricing\AiPricing;
use Espo\Modules\FeatureCredits\Pricing\AiRate;
use PDO;

/** Records request facts, never a debit. Full authorized holds survive until operation settlement. */
final class Outcomes
{
    public function __construct(private WalletLock $walletLock) {}

    public function record(OutcomeInput $input, ?ExecutionIdentity $execution = null): array
    {
        return $this->walletLock->run($input->tenantId, function (PDO $pdo, array $wallet, string $now) use ($input, $execution): array {
            $query = $pdo->prepare('SELECT * FROM credit_usage WHERE id = ? AND tenant_id = ?');
            $query->execute([$input->usageId, $input->tenantId]);
            $usage = $query->fetch(PDO::FETCH_ASSOC);
            if (!$usage || $usage['deleted']) {
                throw new NotFound('Unknown credit operation.');
            }
            if ($execution) ExecutionRouting::requireUnifiedLocked($pdo, $input->tenantId, $execution, $usage);
            if ($usage['execution_id'] !== $input->executionId || $usage['operation_type'] !== 'ai' ||
                $usage['billing_regime'] !== 'unified-prepaid-v1') {
                throw new Conflict('Outcome does not own a unified AI operation.');
            }
            $query = $pdo->prepare('SELECT * FROM credit_request WHERE id = ? AND usage_id = ? AND tenant_id = ?');
            $query->execute([$input->requestId, $input->usageId, $input->tenantId]);
            $request = $query->fetch(PDO::FETCH_ASSOC);
            if (!$request || $request['deleted']) {
                throw new NotFound('Unknown authorized request.');
            }
            $history = $request['outcome_record'] === null ? [] : json_decode($request['outcome_record'], true, 512, JSON_THROW_ON_ERROR);
            foreach ($history as $entry) {
                if ($entry['hash'] === $input->hash) {
                    return $this->receipt($request);
                }
            }
            $query = $pdo->prepare('SELECT * FROM credit_reservation WHERE id = ? AND usage_id = ? AND tenant_id = ?');
            $query->execute([$request['reservation_id'], $input->usageId, $input->tenantId]);
            $reservation = $query->fetch(PDO::FETCH_ASSOC);
            if (!$reservation || $reservation['deleted'] || $reservation['state'] !== 'held' ||
                $reservation['execution_id'] !== $input->executionId || $usage['state'] !== 'admitted') {
                throw new Conflict('Operation is not open for request outcomes.');
            }
            // One initial terminal report, then at most one authoritative completion of unknown metering.
            if ($history === []) {
                if ($request['outcome'] !== 'inFlight' || $request['billing_state'] !== 'pending') {
                    throw new Conflict('Request already has an outcome.');
                }
            } elseif (isset($history['resolution']) || $request['metering_state'] !== 'unknown' ||
                $request['outcome'] !== $input->outcome || !$input->measured() ||
                ($request['provider_request_id'] !== null && $request['provider_request_id'] !== $input->providerRequestId)) {
                throw new Conflict('Conflicting terminal request outcome.');
            }

            $price = null;
            if ($input->measured()) {
                $snapshot = json_decode($request['pricing_snapshot'], true, 512, JSON_THROW_ON_ERROR);
                $rate = new AiRate($snapshot['id'], $snapshot['provider'], $snapshot['model'], $snapshot['multiplier']);
                foreach ($rate->jsonSerialize() as $key => $value) {
                    if ($snapshot[$key] !== $value) {
                        throw new Conflict('Unsupported applied pricing snapshot.');
                    }
                }
                if ($snapshot['provider'] !== $request['provider'] || $snapshot['model'] !== $request['model']) {
                    throw new Conflict('Request pricing identity mismatch.');
                }
                $price = (new AiPricing())->request($rate, $input->inputTokens, $input->cachedInputTokens, $input->outputTokens);
                // Only the explicitly selected native-search policy permits a
                // measured overrun. Other provider-bound violations remain closed.
                if ($input->outcome !== 'infrastructureFailure' && !NativeSearchDebt::permitted($snapshot) &&
                    ($input->inputTokens > $snapshot['inputTokenBound'] || $input->outputTokens > $snapshot['outputTokenLimit'] ||
                    $price->compareTo(BigDecimal::of($request['authorized_credits'])) > 0)) {
                    throw new Conflict('Measured usage exceeds authorized request limits; operator reconciliation required.');
                }
            }
            $waived = $input->outcome === 'infrastructureFailure';
            $billing = $waived ? 'waived' : ($input->measured() ? 'billable' : 'pending');
            $payload = json_decode($input->json, true, 512, JSON_THROW_ON_ERROR);
            $history[$history === [] ? 'initial' : 'resolution'] = ['hash' => $input->hash, 'recordedAt' => $now, 'input' => $payload];
            $exact = $price === null ? null : (string) $price->strippedOfTrailingZeros();
            $pdo->prepare('UPDATE credit_request SET outcome = ?, metering_state = ?, billing_state = ?, waiver_reason = ?,
                priced_credits_exact = ?, metering = ?, evidence = ?, provider_request_id = ?, completed_at = ?, outcome_record = ? WHERE id = ?')
                ->execute([$input->outcome, $input->measured() ? 'measured' : 'unknown', $billing,
                    $waived ? 'infrastructure_failure' : null, $exact,
                    json_encode(['inputTokens' => $input->inputTokens, 'cachedInputTokens' => $input->cachedInputTokens,
                        'outputTokens' => $input->outputTokens], JSON_THROW_ON_ERROR),
                    json_encode($payload['evidence'], JSON_THROW_ON_ERROR), $input->providerRequestId,
                    $request['completed_at'] ?? $now, json_encode($history, JSON_THROW_ON_ERROR), $input->requestId]);
            $query = $pdo->prepare('SELECT * FROM credit_request WHERE reservation_id = ?');
            $query->execute([$reservation['id']]);
            $accrued = BigDecimal::zero();
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['deleted'] || $row['tenant_id'] !== $input->tenantId || $row['usage_id'] !== $input->usageId) {
                    throw new Conflict('Invalid request ownership in reservation.');
                }
                if ($row['billing_state'] === 'billable') {
                    if ($row['metering_state'] !== 'measured' || $row['priced_credits_exact'] === null ||
                        BigDecimal::of($row['priced_credits_exact'])->isNegative()) {
                        throw new Conflict('Invalid billable request metering.');
                    }
                    $accrued = $accrued->plus($row['priced_credits_exact']);
                }
            }
            $accrued = (string) $accrued->strippedOfTrailingZeros();
            if (strlen($accrued) > 128 || BigDecimal::of($accrued)->isGreaterThan(
                NativeSearchDebt::accrualLimit($pdo, $input->tenantId, $reservation['id'], $reservation['reserved_credits']))) {
                throw new Conflict('Accrued usage exceeds held funds and approved native overruns.');
            }
            $pdo->prepare('UPDATE credit_reservation SET accrued_credits_exact = ?, modified_at = ? WHERE id = ?')
                ->execute([$accrued, $now, $reservation['id']]);
            return $this->receipt(['id' => $input->requestId, 'outcome' => $input->outcome,
                'metering_state' => $input->measured() ? 'measured' : 'unknown', 'billing_state' => $billing,
                'priced_credits_exact' => $exact, 'waiver_reason' => $waived ? 'infrastructure_failure' : null]);
        });
    }

    private function receipt(array $request): array
    {
        return ['requestId' => $request['id'], 'outcome' => $request['outcome'], 'meteringState' => $request['metering_state'],
            'billingState' => $request['billing_state'], 'pricedCreditsExact' => $request['priced_credits_exact'],
            'waiverReason' => $request['waiver_reason']];
    }
}
