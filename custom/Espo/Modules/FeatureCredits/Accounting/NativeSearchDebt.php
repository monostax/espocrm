<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Espo\Core\Exceptions\Conflict;
use PDO;

/** Native-search debt is part of the existing ledger, not an expiring grant.
 * Repayment transfers eligible grant value to debt in two balanced ledger legs.
 * Call mutations only while owning WalletLock; never consume another request's hold. */
final class NativeSearchDebt
{
    public const PROFILE = 'gemini38-flash-low-native-overrun-v1';
    public const DEBIT = 'nativeSearchOverrun';
    public const PAYMENT = 'searchDebtPayment';
    public const RECOVERY = 'searchDebtRecovery';

    public static function permitted(array $snapshot): bool
    {
        return ($snapshot['boundProfile'] ?? null) === self::PROFILE &&
            ($snapshot['provider'] ?? null) === 'google' && ($snapshot['model'] ?? null) === 'gemini-3.8-flash';
    }

    public static function outstanding(PDO $pdo, string $tenantId): Amount
    {
        $q = $pdo->prepare('SELECT COALESCE(SUM(credits), 0) FROM credit_transaction WHERE tenant_id = ? AND type IN (?, ?)');
        $q->execute([$tenantId, self::DEBIT, self::RECOVERY]);
        $debt = Amount::fromString('0')->minus(Amount::fromString((string) $q->fetchColumn()));
        if ($debt->compareTo(Amount::fromString('0')) < 0) throw new Conflict('Native search debt is over-repaid.');
        return $debt;
    }

    public static function assertWallet(PDO $pdo, string $tenantId, string $balance, string $held): void
    {
        WalletLock::assertProjection((string) Amount::fromString($balance)->plus(self::outstanding($pdo, $tenantId)), $held);
    }

    /** A measured overrun must settle before further admissions/authorizations.
     * This prevents undelivered settlement from making debt look spendable. */
    public static function requireNoPendingOverrun(PDO $pdo, string $tenantId): void
    {
        $q = $pdo->prepare("SELECT r.priced_credits_exact, r.authorized_credits FROM credit_request r
            JOIN credit_usage u ON u.id = r.usage_id AND u.tenant_id = r.tenant_id
            WHERE r.tenant_id = ? AND r.billing_state = 'billable' AND u.state = 'admitted'");
        $q->execute([$tenantId]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (\Brick\Math\BigDecimal::of($row['priced_credits_exact'])->isGreaterThan($row['authorized_credits'])) {
                throw new Conflict('Measured native search overrun requires operation settlement before new inference.');
            }
        }
    }

    public static function accrualLimit(PDO $pdo, string $tenantId, string $reservationId, string $held): \Brick\Math\BigDecimal
    {
        $limit = \Brick\Math\BigDecimal::of($held);
        $q = $pdo->prepare("SELECT pricing_snapshot, priced_credits_exact, authorized_credits FROM credit_request
            WHERE tenant_id = ? AND reservation_id = ? AND billing_state = 'billable' AND deleted = FALSE");
        $q->execute([$tenantId, $reservationId]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $extra = \Brick\Math\BigDecimal::of($row['priced_credits_exact'])->minus($row['authorized_credits']);
            if ($extra->isPositive() && self::permitted(json_decode($row['pricing_snapshot'], true, 512, JSON_THROW_ON_ERROR))) {
                $limit = $limit->plus($extra);
            }
        }
        return $limit;
    }

    public static function postOverrun(PDO $pdo, string $tenantId, string $usageId, Amount $amount, string $now): void
    {
        if ($amount->compareTo(Amount::fromString('0')) <= 0) throw new Conflict('Positive search overrun required.');
        self::post($pdo, $tenantId, self::DEBIT, 'search-overrun:' . $usageId,
            Amount::fromString('0')->minus($amount), null, $usageId, $now,
            ['reason' => 'measured_native_search_overrun', 'usageId' => $usageId]);
    }

    /** Net wallet change is zero: funding/settlement already changed the balance.
     * Each payment has an equal opposite recovery leg with the same stable key. */
    public static function repayLocked(PDO $pdo, string $tenantId, string $now): void
    {
        $debt = self::outstanding($pdo, $tenantId);
        if ((string) $debt === '0.0000') return;
        $q = $pdo->prepare('SELECT * FROM credit_grant WHERE tenant_id = ? AND (expires_at IS NULL OR expires_at > ?)
            ORDER BY CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END, expires_at, id FOR UPDATE');
        $q->execute([$tenantId, $now]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $grant) {
            if ($grant['deleted']) throw new Conflict('Deleted financial grant.');
            WalletLock::assertProjection($grant['remaining_credits'], $grant['reserved_credits']);
            $free = Amount::fromString($grant['remaining_credits'])->minus(Amount::fromString($grant['reserved_credits']));
            $take = $free->compareTo($debt) < 0 ? $free : $debt;
            if ((string) $take === '0.0000') continue;
            $key = 'search-payment:' . RecordId::generate();
            $evidence = ['reason' => 'native_search_debt_payment', 'transferKey' => $key, 'grantId' => $grant['id']];
            self::post($pdo, $tenantId, self::PAYMENT, $key, Amount::fromString('0')->minus($take), $grant['id'], null, $now, $evidence);
            self::post($pdo, $tenantId, self::RECOVERY, $key, $take, null, null, $now, $evidence);
            $pdo->prepare('UPDATE credit_grant SET remaining_credits = ? WHERE id = ?')->execute([
                (string) Amount::fromString($grant['remaining_credits'])->minus($take), $grant['id'],
            ]);
            $debt = $debt->minus($take);
            if ((string) $debt === '0.0000') break;
        }
    }

    private static function post(PDO $pdo, string $tenantId, string $type, string $key, Amount $amount,
        ?string $grantId, ?string $usageId, string $now, array $evidence): void
    {
        $pdo->prepare('INSERT INTO credit_transaction
            (id, tenant_id, type, idempotency_key, input_hash, credits, grant_id, usage_id, occurred_at, posted_at, evidence)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                RecordId::generate(), $tenantId, $type, $key, hash('sha256', "$tenantId:$type:$key:$amount"),
                (string) $amount, $grantId, $usageId, $now, $now, json_encode($evidence, JSON_THROW_ON_ERROR),
            ]);
    }
}
