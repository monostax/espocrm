<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\NotFound;
use PDO;

/** Internal AI authorization. Only a newly committed request permits one dispatch. */
final class Requests
{
    public function __construct(private WalletLock $walletLock, private Funding $funding) {}

    public function authorize(RequestInput $input, ?ExecutionIdentity $execution = null): array
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
                throw new Conflict('Request does not own a unified AI operation.');
            }
            $query = $pdo->prepare('SELECT * FROM credit_request WHERE usage_id = ? AND request_key = ?');
            $query->execute([$input->usageId, $input->requestKey]);
            if ($request = $query->fetch(PDO::FETCH_ASSOC)) {
                if ($request['deleted'] || $request['tenant_id'] !== $input->tenantId || $request['input_hash'] !== $input->hash) {
                    throw new Conflict('Request identity already has different input.');
                }
                return $this->receipt($request, false);
            }
            ExecutionRouting::requireDispatchOpenLocked($pdo, $input->usageId);
            if ($input->boundProfile === NativeSearchDebt::PROFILE) {
                $query = $pdo->prepare('SELECT m.policy_id FROM tenant_credit_billing_rate b JOIN ai_model_credit_rate m
                    ON m.id = b.ai_native_search_model_credit_rate_id AND m.deleted = FALSE
                    WHERE b.id = ? AND b.tenant_id = ? AND b.deleted = FALSE');
                $query->execute([$usage['billing_rate_id'], $input->tenantId]);
                if ($query->fetchColumn() !== $input->rate->id || !NativeSearchDebt::permitted(json_decode($input->snapshotJson, true))) {
                    throw new Conflict('Tenant agreement does not authorize this native-search overrun policy.');
                }
            }
            NativeSearchDebt::requireNoPendingOverrun($pdo, $input->tenantId);
            $query = $pdo->prepare('SELECT * FROM credit_reservation WHERE usage_id = ? AND tenant_id = ?');
            $query->execute([$input->usageId, $input->tenantId]);
            $reservation = $query->fetch(PDO::FETCH_ASSOC);
            if (!$reservation || $reservation['deleted'] || $reservation['state'] !== 'held' ||
                $reservation['execution_id'] !== $input->executionId || $usage['state'] !== 'admitted') {
                throw new Conflict('Operation is not open for authorization.');
            }
            $balance = $this->funding->expireLocked($pdo, $input->tenantId, Amount::fromString($wallet['balance']), $now);
            $walletHeld = Amount::fromString($wallet['reserved_credits']);
            $held = Amount::fromString($reservation['reserved_credits']);
            $query = $pdo->prepare('SELECT * FROM credit_grant WHERE tenant_id = ?
                ORDER BY CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END, expires_at, id FOR UPDATE');
            $query->execute([$input->tenantId]);
            $grants = array_column($query->fetchAll(PDO::FETCH_ASSOC), null, 'id');
            $query = $pdo->prepare('SELECT * FROM credit_allocation WHERE reservation_id = ? AND settled_at IS NULL ORDER BY id FOR UPDATE');
            $query->execute([$reservation['id']]);
            $allocations = $query->fetchAll(PDO::FETCH_ASSOC);
            $total = $buffer = Amount::fromString('0');
            foreach ($allocations as $allocation) {
                $grant = $grants[$allocation['grant_id']] ?? null;
                if (!$grant || $grant['deleted'] || $allocation['deleted'] || $allocation['tenant_id'] !== $input->tenantId ||
                    $allocation['consumed_credits'] !== '0.0000' || $allocation['released_credits'] !== '0.0000' ||
                    $allocation['expired_credits'] !== '0.0000') {
                    throw new Conflict('Invalid open authorization allocation.');
                }
                $amount = Amount::fromString($allocation['reserved_credits']);
                WalletLock::assertProjection((string) $amount, '0.0000');
                $total = $total->plus($amount);
                // Assigned holds survive expiration; never reuse accrued or in-flight funds.
                if ($allocation['request_id'] !== null) {
                    continue;
                }
                $expired = $grant['expires_at'] !== null && $grant['expires_at'] <= $now;
                $remaining = Amount::fromString($grant['remaining_credits']);
                $grantHeld = Amount::fromString($grant['reserved_credits'])->minus($amount);
                $posting = null;
                if ($expired) {
                    $remaining = $remaining->minus($amount);
                    $balance = $balance->minus($amount);
                    $posting = RecordId::generate();
                    $key = 'release:' . $allocation['id'];
                    $pdo->prepare('INSERT INTO credit_transaction
                        (id, tenant_id, type, idempotency_key, input_hash, credits, usage_id, grant_id, allocation_id, occurred_at, posted_at, evidence)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                            $posting, $input->tenantId, 'expiration', $key, hash('sha256', $key),
                            (string) Amount::fromString('0')->minus($amount), $input->usageId, $grant['id'], $allocation['id'], $now, $now,
                            '{"reason":"unassigned_request_expiration"}',
                        ]);
                } else {
                    $buffer = $buffer->plus($amount);
                }
                WalletLock::assertProjection((string) $remaining, (string) $grantHeld);
                $pdo->prepare('UPDATE credit_grant SET remaining_credits = ?, reserved_credits = ? WHERE id = ?')
                    ->execute([(string) $remaining, (string) $grantHeld, $grant['id']]);
                $grants[$grant['id']]['remaining_credits'] = (string) $remaining;
                $grants[$grant['id']]['reserved_credits'] = (string) $grantHeld;
                $pdo->prepare('UPDATE credit_allocation SET released_credits = ?, expired_credits = ?, expiration_transaction_id = ?, settled_at = ? WHERE id = ?')
                    ->execute([$expired ? '0.0000' : (string) $amount, $expired ? (string) $amount : '0.0000', $posting, $now, $allocation['id']]);
                $held = $held->minus($amount);
                $walletHeld = $walletHeld->minus($amount);
            }
            if ((string) $total !== $reservation['reserved_credits']) {
                throw new Conflict('Reservation and allocation projections disagree.');
            }
            $bound = Amount::fromString($input->credits);
            $needed = $buffer->compareTo($bound) > 0 ? $buffer : $bound;
            if ($balance->minus($walletHeld)->compareTo($needed) < 0) {
                throw new Conflict('Insufficient eligible credits for request.');
            }
            $requestId = RecordId::generate();
            $pdo->prepare('INSERT INTO credit_request
                (id, tenant_id, usage_id, reservation_id, request_key, input_hash, provider, model, pricing_snapshot,
                 authorized_credits, outcome, metering_state, billing_state, authorized_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                    $requestId, $input->tenantId, $input->usageId, $reservation['id'], $input->requestKey, $input->hash,
                    $input->rate->provider, $input->rate->model, $input->snapshotJson, $input->credits, 'inFlight', 'unknown', 'pending', $now,
                ]);
            $walletHeld = $walletHeld->plus($needed);
            $held = $held->plus($needed);
            // Reallocate the request first, then any admission buffer, across all eligible grants.
            foreach (['request' => $bound, 'buffer' => $needed->minus($bound)] as $kind => $left) {
                foreach ($grants as $id => $grant) {
                    if ($grant['expires_at'] !== null && $grant['expires_at'] <= $now) {
                        continue;
                    }
                    if ($grant['deleted']) {
                        throw new Conflict('Deleted financial grant.');
                    }
                    WalletLock::assertProjection($grant['remaining_credits'], $grant['reserved_credits']);
                    $free = Amount::fromString($grant['remaining_credits'])->minus(Amount::fromString($grant['reserved_credits']));
                    $take = $free->compareTo($left) < 0 ? $free : $left;
                    if ((string) $take === '0.0000') {
                        continue;
                    }
                    $grants[$id]['reserved_credits'] = (string) Amount::fromString($grant['reserved_credits'])->plus($take);
                    $pdo->prepare('UPDATE credit_grant SET reserved_credits = ? WHERE id = ?')->execute([$grants[$id]['reserved_credits'], $id]);
                    $pdo->prepare('INSERT INTO credit_allocation
                        (id, tenant_id, grant_id, reservation_id, allocation_key, request_id, reserved_credits,
                         consumed_credits, released_credits, expired_credits, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                            RecordId::generate(), $input->tenantId, $id, $reservation['id'], $kind . ':' . $requestId,
                            $kind === 'request' ? $requestId : null, (string) $take, '0.0000', '0.0000', '0.0000', $now,
                        ]);
                    $left = $left->minus($take);
                }
                if ((string) $left !== '0.0000') {
                    throw new Conflict('Wallet and eligible grant projections disagree.');
                }
            }
            NativeSearchDebt::assertWallet($pdo, $input->tenantId, (string) $balance, (string) $walletHeld);
            $pdo->prepare('UPDATE credit_reservation SET reserved_credits = ?, modified_at = ? WHERE id = ?')
                ->execute([(string) $held, $now, $reservation['id']]);
            $pdo->prepare('UPDATE tenant_credit_balance SET balance = ?, reserved_credits = ?, modified_at = ? WHERE id = ?')
                ->execute([(string) $balance, (string) $walletHeld, $now, $wallet['id']]);
            return $this->receipt(['id' => $requestId, 'authorized_credits' => $input->credits,
                'outcome' => 'inFlight', 'metering_state' => 'unknown', 'billing_state' => 'pending'], true);
        });
    }

    private function receipt(array $request, bool $dispatch): array
    {
        return ['requestId' => $request['id'], 'authorizedCredits' => $request['authorized_credits'],
            'outcome' => $request['outcome'], 'meteringState' => $request['metering_state'],
            'billingState' => $request['billing_state'], 'dispatchAllowed' => $dispatch];
    }
}
