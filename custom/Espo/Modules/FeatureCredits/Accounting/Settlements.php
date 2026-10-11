<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Brick\Math\BigDecimal;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\NotFound;
use PDO;

/** Trusted terminal AI settlement; amounts come only from persisted request outcomes. */
final class Settlements
{
    public function __construct(private WalletLock $walletLock, private Funding $funding) {}

    public function settle(string $tenantId, string $usageId, string $executionId, ?ExecutionIdentity $execution = null): array
    {
        GrantInput::identity($usageId);
        if (!preg_match('/^[a-z0-9][a-z0-9._:\/-]{0,127}$/D', $executionId)) {
            throw new \InvalidArgumentException('Canonical execution identity is required.');
        }
        return $this->walletLock->run($tenantId, function (PDO $pdo, array $wallet, string $now) use ($tenantId, $usageId, $executionId, $execution): array {
            $query = $pdo->prepare('SELECT * FROM credit_usage WHERE id = ? AND tenant_id = ?');
            $query->execute([$usageId, $tenantId]);
            $usage = $query->fetch(PDO::FETCH_ASSOC);
            if (!$usage || $usage['deleted']) {
                throw new NotFound('Unknown credit operation.');
            }
            if ($execution) ExecutionRouting::requireUnifiedLocked($pdo, $tenantId, $execution, $usage);
            if ($usage['execution_id'] !== $executionId || $usage['operation_type'] !== 'ai' ||
                $usage['billing_regime'] !== 'unified-prepaid-v1') {
                throw new Conflict('Settlement does not own a unified AI operation.');
            }
            $query = $pdo->prepare('SELECT * FROM credit_reservation WHERE usage_id = ? AND tenant_id = ?');
            $query->execute([$usageId, $tenantId]);
            $reservation = $query->fetch(PDO::FETCH_ASSOC);
            if (!$reservation || $reservation['deleted'] || $reservation['execution_id'] !== $executionId) {
                throw new Conflict('Invalid operation reservation.');
            }
            if ($usage['state'] === 'settled' && $reservation['state'] === 'settled') {
                return $this->receipt($usage, $reservation);
            }
            if ($usage['state'] !== 'admitted' || $reservation['state'] !== 'held') {
                throw new Conflict('Operation is not open for settlement.');
            }
            $query = $pdo->prepare('SELECT * FROM credit_request WHERE usage_id = ? ORDER BY authorized_at, id');
            $query->execute([$usageId]);
            $requests = [];
            $exact = BigDecimal::zero();
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $request) {
                if ($request['deleted'] || $request['tenant_id'] !== $tenantId || $request['reservation_id'] !== $reservation['id'] ||
                    $request['outcome_record'] === null || $request['completed_at'] === null) {
                    throw new Conflict('Request requires authoritative terminal evidence.');
                }
                $price = BigDecimal::zero();
                $native = NativeSearchDebt::permitted(json_decode($request['pricing_snapshot'], true, 512, JSON_THROW_ON_ERROR));
                if ($request['billing_state'] === 'billable' && $request['metering_state'] === 'measured' &&
                    in_array($request['outcome'], ['success', 'cancelled', 'superseded'], true) && $request['priced_credits_exact'] !== null) {
                    $price = BigDecimal::of($request['priced_credits_exact']);
                    if ($price->isNegative() || (!$native && $price->compareTo(BigDecimal::of($request['authorized_credits'])) > 0)) {
                        throw new Conflict('Invalid billable request price.');
                    }
                } elseif (!$this->waived($request)) {
                    throw new Conflict('Unresolved request requires reconciliation before settlement.');
                }
                $exact = $exact->plus($price);
                $requests[$request['id']] = ['price' => $price, 'native' => $native, 'bound' => Amount::fromString($request['authorized_credits']),
                    'allocated' => Amount::fromString('0')];
            }
            if ($requests === []) {
                throw new Conflict('Pre-request cancellation must use release.');
            }
            if ($exact->compareTo(BigDecimal::of($reservation['accrued_credits_exact'])) !== 0) {
                throw new Conflict('Request and accrued pricing projections disagree.');
            }
            $charge = Amount::settlement($exact);
            $balance = $this->funding->expireLocked($pdo, $tenantId, Amount::fromString($wallet['balance']), $now);
            $query = $pdo->prepare('SELECT * FROM credit_grant WHERE tenant_id = ?
                ORDER BY CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END, expires_at, id FOR UPDATE');
            $query->execute([$tenantId]);
            $grants = array_column($query->fetchAll(PDO::FETCH_ASSOC), null, 'id');
            $query = $pdo->prepare('SELECT * FROM credit_allocation WHERE reservation_id = ? AND settled_at IS NULL ORDER BY id FOR UPDATE');
            $query->execute([$reservation['id']]);
            $lots = [];
            $total = Amount::fromString('0');
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $allocation) {
                if ($allocation['deleted'] || $allocation['tenant_id'] !== $tenantId || !isset($grants[$allocation['grant_id']]) ||
                    $allocation['consumed_credits'] !== '0.0000' || $allocation['released_credits'] !== '0.0000' ||
                    $allocation['expired_credits'] !== '0.0000' || $allocation['debit_transaction_id'] !== null ||
                    $allocation['expiration_transaction_id'] !== null) {
                    throw new Conflict('Invalid open settlement allocation.');
                }
                $amount = Amount::fromString($allocation['reserved_credits']);
                WalletLock::assertProjection((string) $amount, '0.0000');
                $total = $total->plus($amount);
                if ($allocation['request_id'] !== null) {
                    if (!isset($requests[$allocation['request_id']])) {
                        throw new Conflict('Allocation belongs to an unknown request.');
                    }
                    $request = &$requests[$allocation['request_id']];
                    $request['allocated'] = $request['allocated']->plus($amount);
                    unset($request);
                }
                $lots[$allocation['grant_id']][] = $allocation;
            }
            if ((string) $total !== $reservation['reserved_credits']) {
                throw new Conflict('Reservation and allocation projections disagree.');
            }
            foreach ($requests as $request) {
                if ($request['allocated']->compareTo($request['bound']) !== 0) {
                    throw new Conflict('Request bound and allocation projections disagree.');
                }
            }
            // Assign exact consumption only to each request's original lots, earliest expiry first.
            // Rounded prefix differences partition ONE rounded operation total without per-request rounding.
            $prefix = BigDecimal::zero();
            $posted = Amount::fromString('0');
            foreach ($grants as $grantId => $grant) {
                foreach ($lots[$grantId] ?? [] as $allocation) {
                    if ($grant['deleted']) {
                        throw new Conflict('Deleted financial grant.');
                    }
                    WalletLock::assertProjection($grant['remaining_credits'], $grant['reserved_credits']);
                    $amount = Amount::fromString($allocation['reserved_credits']);
                    $take = BigDecimal::zero();
                    if ($allocation['request_id'] !== null) {
                        $left = $requests[$allocation['request_id']]['price'];
                        $take = $left->compareTo(BigDecimal::of((string) $amount)) < 0 ? $left : BigDecimal::of((string) $amount);
                        $requests[$allocation['request_id']]['price'] = $left->minus($take);
                    }
                    $prefix = $prefix->plus($take);
                    $rounded = Amount::settlement($prefix);
                    $consumed = $rounded->minus($posted);
                    $posted = $rounded;
                    WalletLock::assertProjection((string) $amount, (string) $consumed);
                    $unused = $amount->minus($consumed);
                    $expired = $grant['expires_at'] !== null && $grant['expires_at'] <= $now ? $unused : Amount::fromString('0');
                    $debitId = $this->post($pdo, $tenantId, $usageId, $allocation, 'debit', $consumed, $now);
                    $expirationId = $this->post($pdo, $tenantId, $usageId, $allocation, 'expiration', $expired, $now);
                    $deduction = $consumed->plus($expired);
                    $remaining = Amount::fromString($grant['remaining_credits'])->minus($deduction);
                    $held = Amount::fromString($grant['reserved_credits'])->minus($amount);
                    WalletLock::assertProjection((string) $remaining, (string) $held);
                    $pdo->prepare('UPDATE credit_grant SET remaining_credits = ?, reserved_credits = ? WHERE id = ?')
                        ->execute([(string) $remaining, (string) $held, $grantId]);
                    $grant['remaining_credits'] = (string) $remaining;
                    $grant['reserved_credits'] = (string) $held;
                    $balance = $balance->minus($deduction);
                    $pdo->prepare('UPDATE credit_allocation SET consumed_credits = ?, released_credits = ?, expired_credits = ?,
                        debit_transaction_id = ?, expiration_transaction_id = ?, settled_at = ? WHERE id = ?')
                        ->execute([(string) $consumed, (string) $unused->minus($expired), (string) $expired,
                            $debitId, $expirationId, $now, $allocation['id']]);
                }
            }
            foreach ($requests as $request) {
                if (!$request['price']->isZero()) {
                    if (!$request['native'] || $request['price']->isNegative()) throw new Conflict('Unfunded non-native consumption.');
                    $prefix = $prefix->plus($request['price']);
                }
            }
            $overrun = $charge->minus($posted);
            if ($overrun->compareTo(Amount::fromString('0')) > 0) {
                NativeSearchDebt::postOverrun($pdo, $tenantId, $usageId, $overrun, $now);
                $balance = $balance->minus($overrun);
                $posted = $posted->plus($overrun);
            }
            if ($prefix->compareTo($exact) !== 0 || $posted->compareTo($charge) !== 0) {
                throw new Conflict('Consumption does not reconcile with request pricing.');
            }
            $pdo->prepare("UPDATE credit_reservation SET state = 'settled', reserved_credits = '0.0000', settled_at = ?, modified_at = ? WHERE id = ?")
                ->execute([$now, $now, $reservation['id']]);
            $pdo->prepare("UPDATE credit_usage SET state = 'settled', settled_credits = ?, settled_at = ? WHERE id = ?")
                ->execute([(string) $charge, $now, $usageId]);
            $held = Amount::fromString($wallet['reserved_credits'])->minus($total);
            NativeSearchDebt::repayLocked($pdo, $tenantId, $now);
            NativeSearchDebt::assertWallet($pdo, $tenantId, (string) $balance, (string) $held);
            $pdo->prepare('UPDATE tenant_credit_balance SET balance = ?, reserved_credits = ?, modified_at = ? WHERE id = ?')
                ->execute([(string) $balance, (string) $held, $now, $wallet['id']]);
            return $this->receipt(array_replace($usage, ['settled_credits' => (string) $charge, 'settled_at' => $now]), $reservation);
        });
    }

    private function waived(array $request): bool
    {
        if ($request['billing_state'] !== 'waived') {
            return false;
        }
        if ($request['outcome'] === 'infrastructureFailure' && $request['waiver_reason'] === 'infrastructure_failure') {
            return true;
        }
        if (!in_array($request['outcome'], ['cancelled', 'superseded'], true) ||
            $request['metering_state'] !== 'unrecoverable' || $request['waiver_reason'] !== 'unrecoverable_cancellation' ||
            $request['priced_credits_exact'] !== null || $request['reconciliation_record'] === null) {
            return false;
        }
        $record = json_decode($request['reconciliation_record'], true, 512, JSON_THROW_ON_ERROR);
        return isset($record['waivedAt'], $record['deadlineAt'], $record['policy']['minimumAttempts']) &&
            $record['waivedAt'] >= $record['deadlineAt'] &&
            count($record['attempts'] ?? []) >= $record['policy']['minimumAttempts'];
    }

    private function post(PDO $pdo, string $tenantId, string $usageId, array $allocation, string $type, Amount $amount, string $now): ?string
    {
        if ((string) $amount === '0.0000') {
            return null;
        }
        $id = RecordId::generate();
        $key = 'settle:' . $allocation['id'];
        $evidence = json_encode(['formula' => 'ai-settlement-v1', 'reason' => $type === 'debit' ? 'measured_usage' : 'settlement_late_release',
            'requestId' => $allocation['request_id']], JSON_THROW_ON_ERROR);
        $pdo->prepare('INSERT INTO credit_transaction
            (id, tenant_id, type, idempotency_key, input_hash, credits, usage_id, grant_id, allocation_id, occurred_at, posted_at, evidence)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $id, $tenantId, $type, $key, hash('sha256', $tenantId . ':' . $type . ':' . $key . ':' . $amount),
                (string) Amount::fromString('0')->minus($amount), $usageId, $allocation['grant_id'], $allocation['id'], $now, $now, $evidence,
            ]);
        return $id;
    }

    private function receipt(array $usage, array $reservation): array
    {
        return ['usageId' => $usage['id'], 'reservationId' => $reservation['id'], 'state' => 'settled',
            'settledCredits' => $usage['settled_credits'], 'settledAt' => $usage['settled_at']];
    }
}
