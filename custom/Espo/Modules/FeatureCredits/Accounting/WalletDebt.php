<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Espo\Core\Exceptions\Conflict;
use PDO;

/** Debt remains in the existing ledger. Repayment transfers free, unexpired grant value. */
final class WalletDebt
{
    public const BILLING = 'billingDebt';
    public const PAYMENT = 'billingDebtPayment';
    public const RECOVERY = 'billingDebtRecovery';

    public static function outstanding(PDO $pdo, string $tenant): Amount
    {
        return NativeSearchDebt::outstanding($pdo, $tenant)->plus(self::billing($pdo, $tenant));
    }

    public static function billing(PDO $pdo, string $tenant): Amount
    {
        return self::forTypes($pdo, $tenant, self::BILLING, self::RECOVERY);
    }

    public static function forTypes(PDO $pdo, string $tenant, string $debit, string $recovery): Amount
    {
        $types = [$debit, $recovery];
        if ($debit === NativeSearchDebt::DEBIT && $recovery === NativeSearchDebt::RECOVERY) {
            $types = [...$types, NativeDebtReversals::TYPE, NativeDebtReversals::REOPEN];
        }
        $q = $pdo->prepare('SELECT COALESCE(SUM(credits), 0) FROM credit_transaction WHERE tenant_id = ? AND type IN (' . implode(',', array_fill(0, count($types), '?')) . ')');
        $q->execute([$tenant, ...$types]);
        $debt = Amount::fromString('0')->minus(Amount::fromString((string) $q->fetchColumn()));
        if ($debt->compareTo(Amount::fromString('0')) < 0) throw new Conflict('Ledger debt is over-repaid.');
        return $debt;
    }

    public static function assertWallet(PDO $pdo, string $tenant, string $balance, string $held): void
    {
        WalletLock::assertProjection((string) Amount::fromString($balance)->plus(self::outstanding($pdo, $tenant)), $held);
    }

    public static function repayLocked(PDO $pdo, string $tenant, string $now): void
    {
        // Preserve existing native repayment order; then discharge billing debt from remaining free value.
        NativeSearchDebt::repayLocked($pdo, $tenant, $now);
        self::repayKindLocked($pdo, $tenant, $now, self::BILLING, self::PAYMENT, self::RECOVERY, 'billing-payment');
    }

    public static function repayKindLocked(PDO $pdo, string $tenant, string $now,
        string $debit, string $payment, string $recovery, string $prefix): void
    {
        $debt = self::forTypes($pdo, $tenant, $debit, $recovery);
        if ((string) $debt === '0.0000') return;
        $origins = DebtRepaymentOrigins::available($pdo, $tenant, $debit, $payment, $recovery, $debt);
        $originIndex = 0;
        $q = $pdo->prepare('SELECT * FROM credit_grant WHERE tenant_id = ? AND (expires_at IS NULL OR expires_at > ?)
            ORDER BY CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END, expires_at, id FOR UPDATE');
        $q->execute([$tenant, $now]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $grant) {
            if ($grant['deleted']) throw new Conflict('Deleted financial grant.');
            WalletLock::assertProjection($grant['remaining_credits'], $grant['reserved_credits']);
            $free = Amount::fromString($grant['remaining_credits'])->minus(Amount::fromString($grant['reserved_credits']));
            $take = $free->compareTo($debt) < 0 ? $free : $debt;
            if ((string) $take === '0.0000') continue;
            $unassigned = $take;
            while ((string) $unassigned !== '0.0000') {
                $origin = $origins === null ? null : ($origins[$originIndex] ?? null);
                if ($origins !== null && $origin === null) throw new Conflict('Missing original debt for repayment.');
                $part = $origin !== null && $origin['remaining']->compareTo($unassigned) < 0 ? $origin['remaining'] : $unassigned;
                self::postTransfer($pdo, $tenant, $now, $debit, $payment, $recovery, $prefix, $grant['id'], $origin['id'] ?? null, $part);
                $unassigned = $unassigned->minus($part);
                if ($origin !== null) {
                    $origins[$originIndex]['remaining'] = $origin['remaining']->minus($part);
                    if ((string) $origins[$originIndex]['remaining'] === '0.0000') ++$originIndex;
                }
            }
            $pdo->prepare('UPDATE credit_grant SET remaining_credits = ? WHERE id = ?')->execute([
                (string) Amount::fromString($grant['remaining_credits'])->minus($take), $grant['id'],
            ]);
            $debt = $debt->minus($take);
            if ((string) $debt === '0.0000') break;
        }
    }

    private static function postTransfer(PDO $pdo, string $tenant, string $now, string $debit, string $payment,
        string $recovery, string $prefix, string $grantId, ?string $debtId, Amount $take): void
    {
        $key = $prefix . ':' . RecordId::generate();
        $evidence = json_encode(['reason' => $debit === NativeSearchDebt::DEBIT ? 'native_search_debt_payment' : 'billing_debt_payment',
            'transferKey' => $key, 'grantId' => $grantId, 'debtTransactionId' => $debtId,
            'attribution' => $debtId === null ? 'legacy-pooled' : 'original-debt-v1'], JSON_THROW_ON_ERROR);
        $insert = $pdo->prepare('INSERT INTO credit_transaction
            (id, tenant_id, type, idempotency_key, input_hash, credits, grant_id, debt_transaction_id, occurred_at, posted_at, evidence)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([[$payment, Amount::fromString('0')->minus($take), $grantId], [$recovery, $take, null]] as [$type, $amount, $sourceGrant]) {
            $insert->execute([RecordId::generate(), $tenant, $type, $key, hash('sha256', "$tenant:$type:$key:$amount:$debtId"),
                (string) $amount, $sourceGrant, $debtId, $now, $now, $evidence]);
        }
    }
}
