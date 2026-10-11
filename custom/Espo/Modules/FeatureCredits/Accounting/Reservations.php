<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\NotFound;
use PDO;

/** Trusted operation holds. Request authorization and measured settlement are separate services. */
final class Reservations
{
    public function __construct(private WalletLock $walletLock, private Funding $funding) {}

    /** Replays return current state, never a fresh permission to execute. */
    public function reserve(ReservationInput $input): array
    {
        return $this->walletLock->run($input->tenantId, function (PDO $pdo, array $wallet, string $now) use ($input): array {
            $routing = $input->execution ? ExecutionRouting::unifiedLocked($pdo, $input->tenantId, $input->execution, $now) : null;
            $query = $pdo->prepare('SELECT * FROM credit_usage WHERE tenant_id = ? AND operation_type = ? AND operation_key = ?');
            $query->execute([$input->tenantId, $input->operationType, $input->operationKey]);
            if ($usage = $query->fetch(PDO::FETCH_ASSOC)) {
                if ($usage['deleted'] || $usage['input_hash'] !== $input->hash) {
                    throw new Conflict('Operation identity already has different input.');
                }
                if ($routing !== null && (($routing['route']['usage_id'] ?? null) !== $usage['id'] ||
                    $usage['billing_regime'] !== ExecutionRouting::UNIFIED)) {
                    throw new Conflict('Missing or conflicting persisted execution route.');
                }
                return $this->receipt($pdo, $input->tenantId, $usage['id']);
            }
            if (($routing['route'] ?? null) !== null) throw new Conflict('Execution route has no matching accounting operation.');
            NativeSearchDebt::requireNoPendingOverrun($pdo, $input->tenantId);
            $query = $pdo->prepare('SELECT id FROM tenant_credit_billing_rate WHERE tenant_id = ? AND deleted = FALSE
                AND effective_from <= ? AND (effective_until IS NULL OR effective_until > ?)');
            $query->execute([$input->tenantId, $now, $now]);
            if ($query->fetchAll(PDO::FETCH_COLUMN) !== [$input->billingRateId]) {
                throw new Conflict('Admission requires exactly one matching active tenant agreement.');
            }
            $input->source?->assertTenantLocked($pdo, $input->tenantId);
            $balance = $this->funding->expireLocked($pdo, $input->tenantId, Amount::fromString($wallet['balance']), $now);
            $held = Amount::fromString($wallet['reserved_credits']);
            $amount = Amount::fromString($input->credits);
            if ($balance->minus($held)->compareTo($amount) < 0) {
                throw new Conflict('Insufficient eligible credits.');
            }
            $query = $pdo->prepare('SELECT * FROM credit_grant WHERE tenant_id = ?
                AND (expires_at IS NULL OR expires_at > ?)
                ORDER BY CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END, expires_at, id FOR UPDATE');
            $query->execute([$input->tenantId, $now]);
            $lots = [];
            $needed = $amount;
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $grant) {
                if ($grant['deleted']) {
                    throw new Conflict('Deleted financial grant.');
                }
                WalletLock::assertProjection($grant['remaining_credits'], $grant['reserved_credits']);
                $free = Amount::fromString($grant['remaining_credits'])->minus(Amount::fromString($grant['reserved_credits']));
                $take = $free->compareTo($needed) < 0 ? $free : $needed;
                if ($take->compareTo(Amount::fromString('0')) > 0) {
                    $lots[] = [$grant, $take];
                    $needed = $needed->minus($take);
                }
            }
            if ((string) $needed !== '0.0000') {
                throw new Conflict('Wallet and eligible grant projections disagree.');
            }
            $usageId = RecordId::generate();
            $reservationId = RecordId::generate();
            $pdo->prepare('INSERT INTO credit_usage
                (id, tenant_id, operation_type, operation_key, input_hash, execution_id, billing_regime, billing_rate_id, state, admitted_at, source_type, source_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                    $usageId, $input->tenantId, $input->operationType, $input->operationKey, $input->hash,
                    $input->executionId, 'unified-prepaid-v1', $input->billingRateId, 'admitted', $now,
                    $input->source?->type, $input->source?->id,
                ]);
            $pdo->prepare('INSERT INTO credit_reservation
                (id, tenant_id, usage_id, execution_id, state, reserved_credits, accrued_credits_exact, created_at, modified_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                    $reservationId, $input->tenantId, $usageId, $input->executionId, 'held', $input->credits, '0', $now, $now,
                ]);
            foreach ($lots as [$grant, $take]) {
                $pdo->prepare('UPDATE credit_grant SET reserved_credits = ? WHERE id = ? AND tenant_id = ?')->execute([
                    (string) Amount::fromString($grant['reserved_credits'])->plus($take), $grant['id'], $input->tenantId,
                ]);
                $pdo->prepare('INSERT INTO credit_allocation
                    (id, tenant_id, grant_id, reservation_id, allocation_key, reserved_credits,
                     consumed_credits, released_credits, expired_credits, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                        RecordId::generate(), $input->tenantId, $grant['id'], $reservationId, 'initial', (string) $take,
                        '0.0000', '0.0000', '0.0000', $now,
                    ]);
            }
            $this->saveWallet($pdo, $wallet, $balance, $held->plus($amount), $now);
            if ($input->execution) {
                ExecutionRouting::recordLocked($pdo, $input->tenantId, $input->execution, ExecutionRouting::UNIFIED,
                    $now, $routing['cutoverAt'], $usageId);
            }
            return $this->receipt($pdo, $input->tenantId, $usageId);
        });
    }

    /** Only cancellation before any persisted request is allowed here; unknown/in-flight usage cannot be waived. */
    public function release(string $tenantId, string $usageId, string $executionId, ?ExecutionIdentity $execution = null): array
    {
        GrantInput::identity($usageId);
        return $this->walletLock->run($tenantId, function (PDO $pdo, array $wallet, string $now) use ($tenantId, $usageId, $executionId, $execution): array {
            $query = $pdo->prepare('SELECT * FROM credit_usage WHERE id = ? AND tenant_id = ?');
            $query->execute([$usageId, $tenantId]);
            $usage = $query->fetch(PDO::FETCH_ASSOC);
            if (!$usage || $usage['deleted']) {
                throw new NotFound('Unknown credit operation.');
            }
            if ($execution) ExecutionRouting::requireUnifiedLocked($pdo, $tenantId, $execution, $usage);
            if ($usage['execution_id'] !== $executionId) {
                throw new Conflict('Execution does not own this operation.');
            }
            $receipt = $this->receipt($pdo, $tenantId, $usageId);
            if ($usage['state'] === 'released' && $receipt['state'] === 'released') {
                return $receipt;
            }
            $query = $pdo->prepare('SELECT COUNT(*) FROM credit_request WHERE usage_id = ?');
            $query->execute([$usageId]);
            if ($usage['state'] !== 'admitted' || $receipt['state'] !== 'held' || (int) $query->fetchColumn() !== 0) {
                throw new Conflict('Only pre-request operations may be released.');
            }
            $balance = $this->funding->expireLocked($pdo, $tenantId, Amount::fromString($wallet['balance']), $now);
            $query = $pdo->prepare('SELECT * FROM credit_grant WHERE tenant_id = ? ORDER BY id FOR UPDATE');
            $query->execute([$tenantId]);
            $grants = array_column($query->fetchAll(PDO::FETCH_ASSOC), null, 'id');
            $query = $pdo->prepare('SELECT * FROM credit_allocation WHERE reservation_id = ? ORDER BY id FOR UPDATE');
            $query->execute([$receipt['reservationId']]);
            $total = Amount::fromString('0');
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $allocation) {
                $grant = $grants[$allocation['grant_id']] ?? null;
                if (!$grant || $grant['deleted'] || $allocation['deleted'] || $allocation['tenant_id'] !== $tenantId ||
                    $allocation['settled_at'] !== null || $allocation['request_id'] !== null ||
                    $allocation['consumed_credits'] !== '0.0000' || $allocation['released_credits'] !== '0.0000' ||
                    $allocation['expired_credits'] !== '0.0000') {
                    throw new Conflict('Invalid pre-request allocation.');
                }
                $amount = Amount::fromString($allocation['reserved_credits']);
                WalletLock::assertProjection((string) $amount, '0.0000');
                $total = $total->plus($amount);
                $remaining = Amount::fromString($grant['remaining_credits']);
                $held = Amount::fromString($grant['reserved_credits'])->minus($amount);
                $expired = $grant['expires_at'] !== null && $grant['expires_at'] <= $now;
                $posting = null;
                if ($expired) {
                    $remaining = $remaining->minus($amount);
                    $balance = $balance->minus($amount);
                    $posting = RecordId::generate();
                    $key = 'release:' . $allocation['id'];
                    $pdo->prepare('INSERT INTO credit_transaction
                        (id, tenant_id, type, idempotency_key, input_hash, credits, usage_id, grant_id, allocation_id, occurred_at, posted_at, evidence)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                            $posting, $tenantId, 'expiration', $key, hash('sha256', $key),
                            (string) Amount::fromString('0')->minus($amount), $usageId, $grant['id'], $allocation['id'], $now, $now,
                            '{"reason":"pre_request_late_release"}',
                        ]);
                }
                WalletLock::assertProjection((string) $remaining, (string) $held);
                $pdo->prepare('UPDATE credit_grant SET remaining_credits = ?, reserved_credits = ? WHERE id = ?')->execute([
                    (string) $remaining, (string) $held, $grant['id'],
                ]);
                $grants[$grant['id']]['remaining_credits'] = (string) $remaining;
                $grants[$grant['id']]['reserved_credits'] = (string) $held;
                $pdo->prepare('UPDATE credit_allocation SET released_credits = ?, expired_credits = ?, expiration_transaction_id = ?, settled_at = ? WHERE id = ?')
                    ->execute([$expired ? '0.0000' : (string) $amount, $expired ? (string) $amount : '0.0000', $posting, $now, $allocation['id']]);
            }
            if ((string) $total !== $receipt['reservedCredits']) {
                throw new Conflict('Reservation and allocation projections disagree.');
            }
            $pdo->prepare("UPDATE credit_reservation SET state = 'released', reserved_credits = '0.0000', settled_at = ?, modified_at = ?
                WHERE id = ? AND accrued_credits_exact = '0'")->execute([$now, $now, $receipt['reservationId']]);
            // Guard against any accrued usage even if request evidence is corrupt/missing.
            if ($this->receipt($pdo, $tenantId, $usageId)['state'] !== 'released') {
                throw new Conflict('Accrued usage requires settlement.');
            }
            $pdo->prepare("UPDATE credit_usage SET state = 'released', settled_credits = '0.0000', settled_at = ? WHERE id = ?")
                ->execute([$now, $usageId]);
            $this->saveWallet($pdo, $wallet, $balance, Amount::fromString($wallet['reserved_credits'])->minus($total), $now);
            return $this->receipt($pdo, $tenantId, $usageId);
        });
    }

    private function receipt(PDO $pdo, string $tenantId, string $usageId): array
    {
        $query = $pdo->prepare('SELECT * FROM credit_reservation WHERE tenant_id = ? AND usage_id = ?');
        $query->execute([$tenantId, $usageId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['deleted']) {
            throw new Conflict('Missing or deleted reservation.');
        }
        return ['usageId' => $usageId, 'reservationId' => $row['id'], 'state' => $row['state'], 'reservedCredits' => $row['reserved_credits']];
    }

    private function saveWallet(PDO $pdo, array $wallet, Amount $balance, Amount $held, string $now): void
    {
        NativeSearchDebt::repayLocked($pdo, $wallet['tenant_id'], $now);
        NativeSearchDebt::assertWallet($pdo, $wallet['tenant_id'], (string) $balance, (string) $held);
        $pdo->prepare('UPDATE tenant_credit_balance SET balance = ?, reserved_credits = ?, modified_at = ? WHERE id = ?')
            ->execute([(string) $balance, (string) $held, $now, $wallet['id']]);
    }
}
