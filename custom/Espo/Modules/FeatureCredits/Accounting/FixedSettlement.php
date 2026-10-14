<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Espo\Core\Exceptions\Conflict;
use Espo\Modules\FeatureCredits\Verification\Kind;
use PDO;

/** Internal fixed-action settlement on the existing ledger; caller atomically publishes purchased access. */
final class FixedSettlement
{
    public function __construct(private Funding $funding) {}

    /** Read-only financial projection, shared by operation/reservation history. No result values are returned. */
    public static function purchasedTotal(PDO $pdo, string $tenant, string $usage): ?Amount
    {
        $q = $pdo->prepare('SELECT p.*,q.id AS parent_quote_id,q.tenant_id AS quote_tenant,q.usage_id AS quote_usage,q.deleted AS quote_deleted,
            q.confirmed_at AS quote_confirmed_at,q.admitted_at AS quote_admitted_at
            FROM enrichment_purchase p LEFT JOIN enrichment_quote q ON q.id=p.quote_id WHERE p.usage_id=?');
        $q->execute([$usage]); $total = null;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $receipt) {
            $amount = Amount::fromString($receipt['credits']);
            if ($receipt['deleted'] || $receipt['tenant_id'] !== $tenant || $receipt['parent_quote_id'] === null ||
                $receipt['quote_deleted'] || $receipt['quote_tenant'] !== $tenant || $receipt['quote_usage'] !== $usage ||
                $receipt['quote_confirmed_at'] === null || $receipt['quote_admitted_at'] === null ||
                $amount->compareTo(Amount::fromString('0')) <= 0) throw new \LogicException('Invalid purchased consumption receipt.');
            $total = ($total ?? Amount::fromString('0'))->plus($amount);
        }
        return $total;
    }

    public static function verificationCharge(PDO $pdo, string $tenant, string $usage): Amount
    {
        $q = $pdo->prepare('SELECT o.*, u.operation_type, u.operation_key, u.execution_id, u.source_type, u.source_id, u.deleted AS usage_deleted
            FROM phone_verification_operation o LEFT JOIN credit_usage u ON u.id=o.usage_id AND u.tenant_id=o.tenant_id WHERE o.usage_id=?');
        $q->execute([$usage]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) throw new \LogicException('Invalid phone verification receipt count.');
        $op = $rows[0];
        $kind = $op['verification_kind'] ?? Kind::PHONE;
        if ($op['deleted'] || $op['usage_deleted'] || $op['tenant_id'] !== $tenant ||
            $op['operation_type'] !== 'verification' ||
            $op['operation_key'] !== 'verification:' . $op['id'] ||
            $op['execution_id'] !== 'verification:' . $op['id'] ||
            $op['source_type'] !== 'Contact' || $op['source_id'] !== $op['contact_id'] ||
            !in_array($kind, [Kind::PHONE, Kind::EMAIL], true) ||
            (string) Amount::fromString($op['price_credits']) !== Kind::price($kind) ||
            $op['completed_at'] === null || $op['admitted_at'] > $op['completed_at']) {
            throw new \LogicException('Invalid phone verification operation binding.');
        }
        $erased = $op['erased_at'] !== null;
        if ($erased && ($op['identity_ciphertext'] !== null || $op['evidence_ciphertext'] !== null || ($op['supplier_task_ciphertext'] ?? null) !== null)) {
            throw new \LogicException('Erased phone verification operation retains ciphertext.');
        }
        if ($op['state'] === 'completed') {
            if (!in_array($op['outcome'], Kind::outcomes($kind), true) ||
                $op['claimed_at'] === null || $op['claimed_at'] > $op['completed_at'] ||
                $op['fresh_until'] === null || $op['retention_expires_at'] === null ||
                $op['evidence_hash'] === null || (!$erased && ($op['identity_ciphertext'] === null || $op['evidence_ciphertext'] === null))) {
                throw new \LogicException('Invalid completed phone verification receipt.');
            }
            return Amount::fromString(Kind::price($kind));
        }
        if ($op['state'] === 'waived') {
            if (!in_array($op['outcome'], ['failed', 'cancelled', 'unavailable'], true) ||
                $op['evidence_ciphertext'] !== null || $op['evidence_hash'] !== null || $op['fresh_until'] !== null ||
                (!$erased && $op['identity_ciphertext'] === null)) {
                throw new \LogicException('Invalid waived phone verification receipt.');
            }
            return Amount::fromString('0.0000');
        }
        throw new \LogicException('Phone verification operation is not terminal.');
    }

    public static function verificationKind(PDO $pdo, string $tenant, string $usage): string
    {
        $q = $pdo->prepare('SELECT verification_kind FROM phone_verification_operation WHERE tenant_id = ? AND usage_id = ?');
        $q->execute([$tenant, $usage]);
        $kind = $q->fetchColumn();
        if (!in_array($kind, [Kind::PHONE, Kind::EMAIL], true)) throw new \LogicException('Invalid verification kind.');
        return $kind;
    }

    public static function openVerificationValid(PDO $pdo, string $tenant, string $usage, ?string $reserved = null): bool
    {
        $q = $pdo->prepare('SELECT o.*, u.operation_type, u.operation_key, u.execution_id, u.source_type, u.source_id
            FROM phone_verification_operation o JOIN credit_usage u ON u.id=o.usage_id AND u.tenant_id=o.tenant_id WHERE o.usage_id=?');
        $q->execute([$usage]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) return false;
        $op = $rows[0];
        $kind = $op['verification_kind'] ?? Kind::PHONE;
        return !$op['deleted'] && $op['tenant_id'] === $tenant &&
            $op['operation_type'] === 'verification' &&
            $op['operation_key'] === 'verification:' . $op['id'] &&
            $op['execution_id'] === 'verification:' . $op['id'] &&
            $op['source_type'] === 'Contact' && $op['source_id'] === $op['contact_id'] &&
            in_array($kind, [Kind::PHONE, Kind::EMAIL], true) &&
            (string) Amount::fromString($op['price_credits']) === Kind::price($kind) &&
            ($reserved === null || $reserved === Kind::price($kind)) &&
            in_array($op['state'], $kind === Kind::EMAIL ? ['admitted', 'claimed', 'awaiting'] : ['admitted', 'claimed'], true) &&
            $op['completed_at'] === null && $op['outcome'] === null &&
            $op['evidence_ciphertext'] === null && $op['evidence_hash'] === null &&
            $op['erased_at'] === null && $op['identity_ciphertext'] !== null;
    }

    public function settleLocked(PDO $pdo, array $wallet, string $now, array $usage, array $reservation, Amount $charge): void
    {
        $this->applyLocked($pdo, $wallet, $now, $usage, $reservation, $charge, null);
    }

    /** Receipt insertion and this debit must share WalletLock's transaction. Never a user-facing command. */
    public function purchaseLocked(PDO $pdo, array $wallet, string $now, array $usage, array $reservation, Amount $charge, string $purchaseId): void
    {
        GrantInput::identity($purchaseId);
        $this->applyLocked($pdo, $wallet, $now, $usage, $reservation, $charge, $purchaseId);
    }

    private function applyLocked(PDO $pdo, array $wallet, string $now, array $usage, array $reservation, Amount $charge, ?string $purchaseId): void
    {
        if (!$pdo->inTransaction() || !in_array($usage['operation_type'], ['enrichment', 'verification'], true) || $usage['state'] !== 'admitted' ||
            $usage['tenant_id'] !== $wallet['tenant_id'] || $reservation['usage_id'] !== $usage['id'] ||
            $reservation['tenant_id'] !== $usage['tenant_id'] || $reservation['state'] !== 'held' ||
            $reservation['execution_id'] !== $usage['execution_id'] || $usage['deleted'] || $reservation['deleted'] ||
            $charge->compareTo(Amount::fromString('0')) < 0 ||
            $charge->compareTo(Amount::fromString($reservation['reserved_credits'])) > 0) {
            throw new Conflict('Invalid fixed-action settlement binding or bound.');
        }
        $tenant = $wallet['tenant_id'];
        $previous = Amount::fromString($usage['settled_credits'] ?? '0');
        if ($previous->compareTo(Amount::fromString('0')) < 0 ||
            Amount::fromString($reservation['accrued_credits_exact'])->compareTo($previous) !== 0) {
            throw new Conflict('Invalid incremental consumption projection.');
        }
        $q = $pdo->prepare('SELECT * FROM enrichment_purchase WHERE usage_id=?'); $q->execute([$usage['id']]);
        $purchases = $q->fetchAll(PDO::FETCH_ASSOC); $purchased = Amount::fromString('0'); $current = null;
        $verificationKind = Kind::PHONE;
        if ($usage['operation_type'] === 'verification') {
            if ($purchaseId !== null || $purchases !== [] || $usage['settled_credits'] !== null ||
                (string) $previous !== '0.0000') {
                throw new Conflict('Invalid verification settlement state.');
            }
            try {
                $expected = self::verificationCharge($pdo, $tenant, $usage['id']);
            } catch (\LogicException $e) {
                throw new Conflict($e->getMessage());
            }
            $q = $pdo->prepare('SELECT completed_at, price_credits, verification_kind FROM phone_verification_operation WHERE usage_id=? AND tenant_id=?');
            $q->execute([$usage['id'], $tenant]);
            $receipt = $q->fetch(PDO::FETCH_ASSOC);
            if ($charge->compareTo($expected) !== 0 || !$receipt || $receipt['completed_at'] !== $now ||
                Amount::fromString($reservation['reserved_credits'])->compareTo(Amount::fromString($receipt['price_credits'])) !== 0) {
                throw new Conflict('Verification settlement does not match its atomic operation record.');
            }
            $verificationKind = $receipt['verification_kind'];
        } else {
            foreach ($purchases as $purchase) {
                if ($purchase['deleted'] || $purchase['tenant_id'] !== $tenant || Amount::fromString($purchase['credits'])->compareTo(Amount::fromString('0')) <= 0) {
                    throw new Conflict('Invalid incremental purchase receipt.');
                }
                $purchased = $purchased->plus(Amount::fromString($purchase['credits']));
                if ($purchase['id'] === $purchaseId) $current = $purchase;
            }
            if ($purchaseId !== null) {
                if (!$current || $current['credits'] !== (string) $charge || $current['purchased_at'] !== $now ||
                    $purchased->compareTo($previous->plus($charge)) !== 0) throw new Conflict('Incremental purchase is not bound to its atomic receipt.');
            } elseif ($purchased->compareTo($previous) !== 0 || ($purchases && (string) $charge !== '0.0000')) {
                throw new Conflict('Incremental closure cannot charge again.');
            }
            if ((self::purchasedTotal($pdo,$tenant,$usage['id']) ?? Amount::fromString('0'))->compareTo($purchased) !== 0) {
                throw new Conflict('Invalid purchase receipt parent binding.');
            }
        }
        $cumulative = $previous->plus($charge);
        $q = $pdo->prepare('SELECT COUNT(*) FROM credit_request WHERE usage_id=?'); $q->execute([$usage['id']]);
        if ((int) $q->fetchColumn() !== 0) throw new Conflict('Fixed-action operation contains incompatible AI requests.');
        $balance = $this->funding->expireLocked($pdo, $tenant, Amount::fromString($wallet['balance']), $now);
        $q = $pdo->prepare('SELECT * FROM credit_grant WHERE tenant_id=? ORDER BY CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END, expires_at, id FOR UPDATE');
        $q->execute([$tenant]);
        $grants = array_column($q->fetchAll(PDO::FETCH_ASSOC), null, 'id');
        $q = $pdo->prepare('SELECT * FROM credit_allocation WHERE reservation_id=? ORDER BY id FOR UPDATE');
        $q->execute([$reservation['id']]);
        $lots = []; $total = Amount::fromString('0'); $consumed = Amount::fromString('0');
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $allocation) {
            if ($allocation['settled_at'] !== null && $purchases) {
                if ($allocation['deleted'] || $allocation['tenant_id'] !== $tenant || $allocation['request_id'] !== null ||
                    !isset($grants[$allocation['grant_id']]) || $grants[$allocation['grant_id']]['deleted'] ||
                    Amount::fromString($allocation['reserved_credits'])->compareTo(Amount::fromString($allocation['consumed_credits'])
                        ->plus(Amount::fromString($allocation['released_credits']))->plus(Amount::fromString($allocation['expired_credits']))) !== 0) {
                    throw new Conflict('Invalid previous incremental allocation.');
                }
                $consumed = $consumed->plus(Amount::fromString($allocation['consumed_credits']));
                continue;
            }
            if ($allocation['deleted'] || $allocation['tenant_id'] !== $tenant || !isset($grants[$allocation['grant_id']]) ||
                $allocation['settled_at'] !== null || $allocation['request_id'] !== null ||
                $allocation['consumed_credits'] !== '0.0000' || $allocation['released_credits'] !== '0.0000' ||
                $allocation['expired_credits'] !== '0.0000' || $allocation['debit_transaction_id'] !== null ||
                $allocation['expiration_transaction_id'] !== null) throw new Conflict('Invalid fixed-action allocation.');
            WalletLock::assertProjection($allocation['reserved_credits'], '0.0000');
            $total = $total->plus(Amount::fromString($allocation['reserved_credits']));
            $lots[$allocation['grant_id']][] = $allocation;
        }
        if ($consumed->compareTo($previous) !== 0) throw new Conflict('Previous incremental consumption differs from allocations.');
        if ((string) $total !== $reservation['reserved_credits']) throw new Conflict('Fixed-action allocation total differs.');
        $left = $charge;
        foreach ($grants as $id => $grant) {
            foreach ($lots[$id] ?? [] as $allocation) {
                if ($grant['deleted']) throw new Conflict('Deleted fixed-action grant.');
                WalletLock::assertProjection($grant['remaining_credits'], $grant['reserved_credits']);
                $amount = Amount::fromString($allocation['reserved_credits']);
                $take = $amount->compareTo($left) < 0 ? $amount : $left;
                if ($purchaseId !== null && (string) $take === '0.0000') continue;
                $left = $left->minus($take);
                $unused = $amount->minus($take);
                $expired = $purchaseId === null && $grant['expires_at'] !== null && $grant['expires_at'] <= $now ? $unused : Amount::fromString('0');
                $debit = $this->post($pdo, $tenant, $usage['id'], $allocation, 'debit', $take, $now, $purchaseId, $usage['operation_type'], $verificationKind);
                $expiry = $this->post($pdo, $tenant, $usage['id'], $allocation, 'expiration', $expired, $now, null, $usage['operation_type']);
                $remaining = Amount::fromString($grant['remaining_credits'])->minus($take)->minus($expired);
                $held = Amount::fromString($grant['reserved_credits'])->minus($purchaseId === null ? $amount : $take);
                WalletLock::assertProjection((string) $remaining, (string) $held);
                $pdo->prepare('UPDATE credit_grant SET remaining_credits=?, reserved_credits=? WHERE id=?')->execute([(string) $remaining, (string) $held, $id]);
                $grant['remaining_credits'] = (string) $remaining; $grant['reserved_credits'] = (string) $held;
                $balance = $balance->minus($take)->minus($expired);
                $pdo->prepare('UPDATE credit_allocation SET consumed_credits=?, released_credits=?, expired_credits=?,
                    debit_transaction_id=?, expiration_transaction_id=?, settled_at=? WHERE id=?')->execute([
                        (string) $take, (string) $unused->minus($expired), (string) $expired, $debit, $expiry, $now, $allocation['id']]);
                if ($purchaseId !== null && (string) $unused !== '0.0000') {
                    // Split within the same original grant. No new funds, expiry reset or observable release gap.
                    $pdo->prepare('INSERT INTO credit_allocation (id,tenant_id,grant_id,reservation_id,allocation_key,
                        reserved_credits,consumed_credits,released_credits,expired_credits,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
                        ->execute([RecordId::generate(),$tenant,$id,$reservation['id'],'remaining:' . $allocation['id'],
                            (string) $unused,'0.0000','0.0000','0.0000',$now]);
                }
            }
        }
        if ((string) $left !== '0.0000') throw new Conflict('Unfunded fixed-action consumption.');
        $final = $purchaseId === null;
        $pdo->prepare('UPDATE credit_reservation SET state=?, reserved_credits=?, accrued_credits_exact=?, settled_at=?, modified_at=? WHERE id=?')
            ->execute([$final ? 'settled' : 'held', $final ? '0.0000' : (string) $total->minus($charge), (string) $cumulative,
                $final ? $now : null, $now, $reservation['id']]);
        $pdo->prepare('UPDATE credit_usage SET state=?, settled_credits=?, settled_at=? WHERE id=?')
            ->execute([$final ? 'settled' : 'admitted', (string) $cumulative, $final ? $now : null, $usage['id']]);
        $held = Amount::fromString($wallet['reserved_credits'])->minus($final ? $total : $charge);
        WalletDebt::repayLocked($pdo, $tenant, $now);
        WalletDebt::assertWallet($pdo, $tenant, (string) $balance, (string) $held);
        $pdo->prepare('UPDATE tenant_credit_balance SET balance=?, reserved_credits=?, modified_at=? WHERE id=?')
            ->execute([(string) $balance, (string) $held, $now, $wallet['id']]);
    }

    private function post(PDO $pdo, string $tenant, string $usage, array $allocation, string $type, Amount $amount, string $now, ?string $purchaseId = null, string $operationType = 'enrichment', string $verificationKind = Kind::PHONE): ?string
    {
        if ((string) $amount === '0.0000') return null;
        $id = RecordId::generate(); $key = 'settle:' . $allocation['id'];
        $formula = $operationType === 'verification' ? 'verification-fixed-v1' : 'enrichment-fixed-v1';
        $debitReason = $operationType === 'verification' ? $verificationKind . '_verification' : 'requested_product_purchase';
        $pdo->prepare('INSERT INTO credit_transaction
            (id, tenant_id, type, idempotency_key, input_hash, credits, usage_id, grant_id, allocation_id, occurred_at, posted_at, evidence)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id, $tenant, $type, $key,
                hash('sha256', $tenant . ':' . $type . ':' . $key . ':' . $amount), (string) Amount::fromString('0')->minus($amount),
                $usage, $allocation['grant_id'], $allocation['id'], $now, $now,
                json_encode(['formula' => $formula, 'reason' => $type === 'debit' ? $debitReason : 'settlement_late_release',
                    ...($purchaseId === null ? [] : ['purchaseId' => $purchaseId])], JSON_THROW_ON_ERROR)]);
        return $id;
    }
}
