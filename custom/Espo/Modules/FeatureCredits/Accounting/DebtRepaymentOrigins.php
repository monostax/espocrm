<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Espo\Core\Exceptions\Conflict;
use PDO;

/** Original-debt attribution under WalletLock. Legacy pooled recoveries are never guessed/backfilled. */
final class DebtRepaymentOrigins
{
    /** @return ?array<array{id: string, remaining: Amount, postedAt: string}> null means historical attribution is incomplete. */
    public static function available(PDO $pdo, string $tenant, string $debit, string $payment, string $recovery, Amount $outstanding): ?array
    {
        $q = $pdo->prepare('SELECT id, credits, deleted, posted_at FROM credit_transaction WHERE tenant_id=? AND type=? ORDER BY posted_at, id');
        $q->execute([$tenant, $debit]); $origins = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $amount = Amount::fromString('0')->minus(Amount::fromString($row['credits']));
            if ($row['deleted'] || $amount->compareTo(Amount::fromString('0')) <= 0) throw new Conflict('Invalid original debt.');
            $origins[$row['id']] = ['id' => $row['id'], 'remaining' => $amount, 'postedAt' => $row['posted_at']];
        }
        $q = $pdo->prepare('SELECT r.*,
            p.credits AS payment_credits, p.debt_transaction_id AS payment_debt_id, p.deleted AS payment_deleted,
            p.grant_id AS payment_grant_id, p.input_hash AS payment_hash, p.evidence AS payment_evidence
            FROM credit_transaction r LEFT JOIN credit_transaction p ON p.tenant_id=r.tenant_id
                AND p.idempotency_key=r.idempotency_key AND p.type=?
            WHERE r.tenant_id=? AND r.type=?');
        $q->execute([$payment, $tenant, $recovery]); $legacy = false;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $amount = Amount::fromString($row['credits']);
            if ($row['deleted'] || $row['payment_deleted'] || $row['payment_credits'] === null ||
                !self::validEvidence($row) || $row['payment_evidence'] !== $row['evidence'] ||
                !self::validEvidence(array_replace($row, ['type' => $payment, 'grant_id' => $row['payment_grant_id'],
                    'credits' => $row['payment_credits'], 'input_hash' => $row['payment_hash'], 'debt_transaction_id' => $row['payment_debt_id']])) ||
                $row['payment_debt_id'] !== $row['debt_transaction_id'] ||
                Amount::fromString($row['payment_credits'])->plus($amount)->compareTo(Amount::fromString('0')) !== 0 ||
                $amount->compareTo(Amount::fromString('0')) <= 0) throw new Conflict('Invalid debt recovery.');
            $id = $row['debt_transaction_id'];
            if ($id === null) { $legacy = true; continue; }
            if (!isset($origins[$id])) throw new Conflict('Debt recovery has no matching original debt.');
            if ($row['posted_at'] < $origins[$id]['postedAt']) throw new Conflict('Debt recovery precedes original debt.');
            $origins[$id]['remaining'] = $origins[$id]['remaining']->minus($amount);
            if ($origins[$id]['remaining']->compareTo(Amount::fromString('0')) < 0) throw new Conflict('Original debt is over-repaid.');
        }
        if ($debit === NativeSearchDebt::DEBIT) {
            $q = $pdo->prepare('SELECT * FROM credit_transaction WHERE tenant_id=? AND type IN (?,?)');
            $q->execute([$tenant, NativeDebtReversals::TYPE, NativeDebtReversals::REOPEN]);
            $corrections = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = $row['debt_transaction_id'];
                if (!isset($origins[$id]) || !NativeDebtReversals::validPosting($row) ||
                    ($row['type'] === NativeDebtReversals::TYPE && !NativeDebtReversals::validGroup($pdo, $row))) throw new Conflict('Invalid original debt correction.');
                $effect = Amount::fromString($row['credits']);
                $corrections[$id] = ($corrections[$id] ?? Amount::fromString('0'))->plus($effect);
            }
            foreach ($corrections as $id => $effect) {
                if ($effect->compareTo(Amount::fromString('0')) < 0) throw new Conflict('Debt correction reopens uncorrected debt.');
                $origins[$id]['remaining'] = $origins[$id]['remaining']->minus($effect);
                if ($origins[$id]['remaining']->compareTo(Amount::fromString('0')) < 0) throw new Conflict('Original debt is over-corrected.');
            }
        }
        // Preserve the established pooled repayment contract for this tenant/category. Incomplete
        // historical provenance must not block ordinary funding or invent a correction entitlement.
        if ($legacy) return null;
        $total = Amount::fromString('0'); $result = [];
        foreach ($origins as $origin) {
            $total = $total->plus($origin['remaining']);
            if ((string) $origin['remaining'] !== '0.0000') $result[] = $origin;
        }
        if ($total->compareTo($outstanding) !== 0) throw new Conflict('Debt attribution does not reconcile.');
        return $result;
    }

    /** Read-only audit and mutation guard; recognize old evidence without rewriting it. */
    public static function validEvidence(array $row): bool
    {
        try { $evidence = json_decode($row['evidence'], true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return false; }
        if (!is_array($evidence) || ($evidence['transferKey'] ?? null) !== $row['idempotency_key'] ||
            !is_string($evidence['grantId'] ?? null) || $evidence['grantId'] === '' ||
            ($evidence['reason'] ?? null) !== (str_starts_with($row['type'], 'search') ? 'native_search_debt_payment' : 'billing_debt_payment') ||
            (str_ends_with($row['type'], 'Payment') && $evidence['grantId'] !== $row['grant_id'])) return false;
        $hash = $row['tenant_id'] . ':' . $row['type'] . ':' . $row['idempotency_key'] . ':' . $row['credits'];
        if (!array_key_exists('attribution', $evidence)) {
            if ($row['debt_transaction_id'] !== null || array_key_exists('debtTransactionId', $evidence)) return false;
        } else {
            if (!array_key_exists('debtTransactionId', $evidence) || $evidence['debtTransactionId'] !== $row['debt_transaction_id'] ||
                $evidence['attribution'] !== ($row['debt_transaction_id'] === null ? 'legacy-pooled' : 'original-debt-v1')) return false;
            $hash .= ':' . ($row['debt_transaction_id'] ?? '');
        }
        return hash_equals(hash('sha256', $hash), $row['input_hash']);
    }
}
