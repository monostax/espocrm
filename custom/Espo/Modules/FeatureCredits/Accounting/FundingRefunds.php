<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Brick\Math\{BigDecimal, RoundingMode};
use Espo\Core\Exceptions\Conflict;
use PDO;

/** Cumulative verified refunds, preserving original issuance, expiry and in-flight holds. */
final class FundingRefunds
{
    public static function credits(string $issued, string $price, string $refunded): Amount
    {
        foreach ([$price, $refunded] as $value) {
            if (!preg_match('/^[0-9]{1,10}(?:\.[0-9]{1,2})?$/D', $value)) throw new Conflict('Invalid original refund amount.');
        }
        if (Amount::fromString($issued)->compareTo(Amount::fromString('0')) < 0) throw new Conflict('Invalid original credit allowance.');
        $price = BigDecimal::of($price); $refunded = BigDecimal::of($refunded);
        if (!$price->isPositive() || $refunded->isNegative() || $refunded->isGreaterThan($price)) throw new Conflict('Refund exceeds original price.');
        return Amount::fromString((string) BigDecimal::of((string) Amount::fromString($issued))
            ->multipliedBy($refunded)->dividedBy($price, 4, RoundingMode::HALF_UP));
    }

    /** A lower/out-of-order cumulative observation never restores revoked credits. */
    public static function reverseLocked(PDO $pdo, array $wallet, string $now, string $grantId,
        Amount $cumulative, array $verifiedEvidence, Funding $funding): array
    {
        if (!$pdo->inTransaction()) throw new \LogicException('Funding refund requires WalletLock.');
        $tenant = $wallet['tenant_id']; $zero = Amount::fromString('0');
        $q = $pdo->prepare('SELECT g.* FROM credit_grant g JOIN credit_transaction t ON t.id=g.grant_transaction_id
            AND t.tenant_id=g.tenant_id AND t.grant_id=g.id AND t.type=? AND t.credits=g.granted_credits AND t.deleted=FALSE
            WHERE g.id=? AND g.tenant_id=? FOR UPDATE');
        $q->execute(['grant', $grantId, $tenant]); $grant = $q->fetch(PDO::FETCH_ASSOC);
        if (!$grant || $grant['deleted'] || !in_array($grant['source_type'], ['purchase', 'subscription'], true) ||
            $cumulative->compareTo($zero) < 0 || $cumulative->compareTo(Amount::fromString($grant['granted_credits'])) > 0) {
            throw new Conflict('Invalid original funding refund.');
        }
        // Retain already-issued v1 full-purchase receipts without translating or rewriting history.
        if ($old = PurchaseReversals::receiptLocked($pdo, $tenant, $grantId)) return $old;
        $prior = self::latest($pdo, $tenant, $grantId);
        $previous = Amount::fromString($prior['cumulativeCredits'] ?? '0');
        if ($prior !== null && $cumulative->compareTo($previous) <= 0) return $prior;
        if ($grant['source_type'] === 'subscription' && $grant['reserved_credits'] !== '0.0000') {
            throw new Conflict('Subscription refund awaits original in-flight allocations.');
        }
        $delta = $cumulative->minus($previous);
        $balance = $funding->expireLocked($pdo, $tenant, Amount::fromString($wallet['balance']), $now);
        $q = $pdo->prepare('SELECT * FROM credit_grant WHERE id=? AND tenant_id=?');
        $q->execute([$grantId, $tenant]); $grant = $q->fetch(PDO::FETCH_ASSOC);
        WalletLock::assertProjection($grant['remaining_credits'], $grant['reserved_credits']);
        // Only unused original expiry counts. Expiration of a later usage restoration is
        // linked to that correction and cannot be counted again as original unused funding.
        $q = $pdo->prepare("SELECT COALESCE(SUM(-credits),0) FROM credit_transaction WHERE tenant_id=? AND grant_id=?
            AND type='expiration' AND reversal_of_id IS NULL AND deleted=FALSE");
        $q->execute([$tenant, $grantId]);
        $expired = Amount::fromString((string) $q->fetchColumn())->minus(Amount::fromString($prior['cumulativeExpiredCredits'] ?? '0'));
        if ($expired->compareTo($zero) < 0) throw new Conflict('Original expired refund attribution is inconsistent.');
        $expired = self::min($delta, $expired);
        $recover = self::min($delta->minus($expired), Amount::fromString($grant['remaining_credits'])->minus(Amount::fromString($grant['reserved_credits'])));
        $debt = $delta->minus($expired)->minus($recover);
        $id = RecordId::generate(); $debtId = (string) $debt === '0.0000' ? null : RecordId::generate();
        $receipt = ['transactionId' => $id, 'grantId' => $grantId, 'grantTransactionId' => $grant['grant_transaction_id'],
            'previousTransactionId' => $prior['transactionId'] ?? null, 'previousCredits' => (string) $previous,
            'cumulativeCredits' => (string) $cumulative, 'revokedCredits' => (string) $delta,
            'recoveredCredits' => (string) $recover, 'debtCredits' => (string) $debt,
            'expiredCredits' => (string) $expired,
            'cumulativeExpiredCredits' => (string) Amount::fromString($prior['cumulativeExpiredCredits'] ?? '0')->plus($expired),
            'debtTransactionId' => $debtId, 'expiresAt' => $grant['expires_at'], 'postedAt' => $now];
        $evidence = json_encode(['formula' => 'funding-refund-v1', 'verifiedSource' => $verifiedEvidence, 'receipt' => $receipt], JSON_THROW_ON_ERROR);
        $key = 'funding-refund:' . $grantId . ':' . $cumulative;
        $insert = $pdo->prepare('INSERT INTO credit_transaction
            (id,tenant_id,type,idempotency_key,input_hash,credits,grant_id,reversal_of_id,occurred_at,posted_at,evidence)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $insert->execute([$id, $tenant, PurchaseReversals::TYPE, $key, hash('sha256', $evidence), (string) $zero->minus($recover),
            $grantId, $grant['grant_transaction_id'], $now, $now, $evidence]);
        if ($debtId !== null) $insert->execute([$debtId, $tenant, WalletDebt::BILLING, $key, hash('sha256', $evidence),
            (string) $zero->minus($debt), null, $id, $now, $now, $evidence]);
        $pdo->prepare('UPDATE credit_grant SET remaining_credits=? WHERE id=?')->execute([
            (string) Amount::fromString($grant['remaining_credits'])->minus($recover), $grantId]);
        $balance = $balance->minus($delta)->plus($expired);
        WalletDebt::repayLocked($pdo, $tenant, $now);
        WalletDebt::assertWallet($pdo, $tenant, (string) $balance, $wallet['reserved_credits']);
        $pdo->prepare('UPDATE tenant_credit_balance SET balance=?,modified_at=? WHERE id=?')->execute([(string) $balance, $now, $wallet['id']]);
        return $receipt;
    }

    public static function latest(PDO $pdo, string $tenant, string $grant): ?array
    {
        $q = $pdo->prepare("SELECT * FROM credit_transaction WHERE tenant_id=? AND grant_id=? AND type='purchaseReversal'
            AND idempotency_key LIKE 'funding-refund:%'");
        $q->execute([$tenant, $grant]); $receipts = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!self::valid($pdo, $row)) throw new Conflict('Invalid original funding refund receipt.');
            $receipt = json_decode($row['evidence'], true, 32, JSON_THROW_ON_ERROR)['receipt'];
            $receipts[] = $receipt;
        }
        usort($receipts, static fn (array $a, array $b): int => Amount::fromString($a['cumulativeCredits'])->compareTo(Amount::fromString($b['cumulativeCredits'])));
        $prior = null;
        foreach ($receipts as $receipt) {
            if ($receipt['previousTransactionId'] !== ($prior['transactionId'] ?? null) ||
                $receipt['previousCredits'] !== ($prior['cumulativeCredits'] ?? '0.0000') ||
                $receipt['cumulativeExpiredCredits'] !== (string) Amount::fromString($prior['cumulativeExpiredCredits'] ?? '0')->plus(Amount::fromString($receipt['expiredCredits']))) {
                throw new Conflict('Broken cumulative funding refund chain.');
            }
            $prior = $receipt;
        }
        return $prior;
    }

    /** Shared audit/replay validation of the original grant, exact split and linked debt. */
    public static function valid(PDO $pdo, array $row): bool
    {
        try {
            $e = json_decode($row['evidence'], true, 32, JSON_THROW_ON_ERROR); $r = $e['receipt'];
            if ($row['deleted'] || $row['type'] !== PurchaseReversals::TYPE || ($e['formula'] ?? null) !== 'funding-refund-v1' ||
                $row['input_hash'] !== hash('sha256', $row['evidence']) || $row['id'] !== $r['transactionId'] ||
                $row['grant_id'] !== $r['grantId'] || $row['reversal_of_id'] !== $r['grantTransactionId'] ||
                $row['idempotency_key'] !== 'funding-refund:' . $r['grantId'] . ':' . $r['cumulativeCredits'] ||
                $row['posted_at'] !== $r['postedAt'] || $row['occurred_at'] !== $r['postedAt'] ||
                $row['usage_id'] !== null || $row['allocation_id'] !== null || $row['debt_transaction_id'] !== null) return false;
            $zero = Amount::fromString('0');
            foreach (['previousCredits','cumulativeCredits','revokedCredits','recoveredCredits','debtCredits','expiredCredits','cumulativeExpiredCredits'] as $field) {
                if (Amount::fromString($r[$field])->compareTo($zero) < 0) return false;
            }
            if ($row['credits'] !== (string) $zero->minus(Amount::fromString($r['recoveredCredits'])) ||
                $r['revokedCredits'] !== (string) Amount::fromString($r['cumulativeCredits'])->minus(Amount::fromString($r['previousCredits'])) ||
                $r['revokedCredits'] !== (string) Amount::fromString($r['recoveredCredits'])->plus(Amount::fromString($r['debtCredits']))->plus(Amount::fromString($r['expiredCredits']))) return false;
            $q = $pdo->prepare("SELECT g.* FROM credit_grant g JOIN credit_transaction t ON t.id=g.grant_transaction_id AND t.tenant_id=g.tenant_id
                AND t.type='grant' AND t.deleted=FALSE AND t.credits=g.granted_credits AND t.grant_id=g.id
                WHERE g.id=? AND g.tenant_id=? AND g.deleted=FALSE");
            $q->execute([$r['grantId'], $row['tenant_id']]); $grant = $q->fetch(PDO::FETCH_ASSOC);
            if (!$grant || !in_array($grant['source_type'], ['purchase','subscription'], true) ||
                $grant['grant_transaction_id'] !== $r['grantTransactionId'] || $grant['expires_at'] !== $r['expiresAt'] ||
                Amount::fromString($r['cumulativeCredits'])->compareTo(Amount::fromString($grant['granted_credits'])) > 0) return false;
            $q = $pdo->prepare("SELECT COALESCE(SUM(-credits),0) FROM credit_transaction WHERE tenant_id=? AND grant_id=?
                AND type='expiration' AND reversal_of_id IS NULL AND deleted=FALSE AND posted_at<=?");
            $q->execute([$row['tenant_id'], $r['grantId'], $r['postedAt']]);
            if (Amount::fromString($r['cumulativeExpiredCredits'])->compareTo(Amount::fromString((string) $q->fetchColumn())) > 0) return false;
            $q = $pdo->prepare("SELECT * FROM credit_transaction WHERE tenant_id=? AND type='billingDebt' AND reversal_of_id=?");
            $q->execute([$row['tenant_id'], $row['id']]); $debts = $q->fetchAll(PDO::FETCH_ASSOC);
            if ($r['debtCredits'] === '0.0000') return $r['debtTransactionId'] === null && $debts === [];
            return count($debts) === 1 && !$debts[0]['deleted'] && $debts[0]['id'] === $r['debtTransactionId'] &&
                $debts[0]['credits'] === (string) $zero->minus(Amount::fromString($r['debtCredits'])) &&
                $debts[0]['evidence'] === $row['evidence'] && $debts[0]['input_hash'] === $row['input_hash'];
        } catch (\Throwable) { return false; }
    }

    private static function min(Amount $a, Amount $b): Amount { return $a->compareTo($b) < 0 ? $a : $b; }
}
