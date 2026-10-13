<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Espo\Core\Exceptions\Conflict;
use PDO;

/** Privileged append-only correction of a measured overrun, with original repayment-grant expiry. */
final class NativeDebtReversals
{
    public const TYPE = 'searchDebtReversal';
    public const REOPEN = 'searchDebtReopen';
    public const RESTORE = 'searchDebtRestore';

    public static function reverseLocked(PDO $pdo, array $wallet, string $now, array $debit, ReversalInput $input, Funding $funding): array
    {
        $tenant = $input->tenantId;
        $zero = Amount::fromString('0'); $amount = Amount::fromString($input->credits);
        if ($debit['type'] !== NativeSearchDebt::DEBIT || $debit['grant_id'] !== null || $debit['allocation_id'] !== null ||
            $debit['idempotency_key'] !== 'search-overrun:' . $debit['usage_id'] || Amount::fromString($debit['credits'])->compareTo($zero) >= 0) {
            throw new Conflict('Original native overrun is invalid.');
        }
        $q = $pdo->prepare("SELECT u.id FROM credit_usage u JOIN credit_reservation r ON r.usage_id=u.id AND r.tenant_id=u.tenant_id
            WHERE u.id=? AND u.tenant_id=? AND u.state='settled' AND r.state='settled' AND u.deleted=FALSE AND r.deleted=FALSE");
        $q->execute([$debit['usage_id'], $tenant]);
        if ($q->fetchColumn() === false) throw new Conflict('Original native operation is not settled.');
        $origins = DebtRepaymentOrigins::available($pdo, $tenant, NativeSearchDebt::DEBIT, NativeSearchDebt::PAYMENT,
            NativeSearchDebt::RECOVERY, NativeSearchDebt::outstanding($pdo, $tenant));
        if ($origins === null) throw new Conflict('Original native debt repayment attribution is incomplete.');
        $unpaid = $zero;
        foreach ($origins as $origin) if ($origin['id'] === $debit['id']) $unpaid = $origin['remaining'];
        $q = $pdo->prepare('SELECT COALESCE(SUM(credits),0) FROM credit_transaction WHERE tenant_id=? AND type=? AND reversal_of_id=?');
        $q->execute([$tenant, self::TYPE, $debit['id']]);
        $reversed = Amount::fromString((string) $q->fetchColumn());
        if ($reversed->plus($amount)->compareTo($zero->minus(Amount::fromString($debit['credits']))) > 0) throw new Conflict('Reversal exceeds the original unreversed overrun.');
        $unpaidPart = $unpaid->compareTo($amount) < 0 ? $unpaid : $amount;
        $toRestore = $amount->minus($unpaidPart); $restored = $toRestore; $expiredTotal = $zero;
        $balance = $funding->expireLocked($pdo, $tenant, Amount::fromString($wallet['balance']), $now);
        $id = RecordId::generate(); $parts = [];
        $q = $pdo->prepare("SELECT r.id AS recovery_id, r.credits, p.id AS payment_id, p.grant_id,
            (SELECT COALESCE(SUM(-c.credits),0) FROM credit_transaction c WHERE c.tenant_id=r.tenant_id
                AND c.type=? AND c.reversal_of_id=r.id) AS undone
            FROM credit_transaction r JOIN credit_transaction p ON p.tenant_id=r.tenant_id AND p.idempotency_key=r.idempotency_key
                AND p.type=? AND p.debt_transaction_id=r.debt_transaction_id
            WHERE r.tenant_id=? AND r.type=? AND r.debt_transaction_id=? ORDER BY r.posted_at,r.id");
        $q->execute([self::REOPEN, NativeSearchDebt::PAYMENT, $tenant, NativeSearchDebt::RECOVERY, $debit['id']]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $payment) {
            if ((string) $toRestore === '0.0000') break;
            $available = Amount::fromString($payment['credits'])->minus(Amount::fromString($payment['undone']));
            if ($available->compareTo($zero) < 0) throw new Conflict('Original repayment is over-restored.');
            if ((string) $available === '0.0000') continue;
            $take = $available->compareTo($toRestore) < 0 ? $available : $toRestore;
            $g = $pdo->prepare('SELECT * FROM credit_grant WHERE id=? AND tenant_id=? FOR UPDATE');
            $g->execute([$payment['grant_id'], $tenant]); $grant = $g->fetch(PDO::FETCH_ASSOC);
            if (!$grant || $grant['deleted']) throw new Conflict('Original repayment grant is unavailable.');
            WalletLock::assertProjection($grant['remaining_credits'], $grant['reserved_credits']);
            $remaining = Amount::fromString($grant['remaining_credits'])->plus($take);
            if ($remaining->compareTo(Amount::fromString($grant['granted_credits'])) > 0) throw new Conflict('Restoration exceeds original repayment grant.');
            $expired = $grant['expires_at'] !== null && $grant['expires_at'] <= $now;
            $parts[] = ['paymentTransactionId' => $payment['payment_id'], 'recoveryTransactionId' => $payment['recovery_id'],
                'grantId' => $grant['id'], 'credits' => (string) $take, 'expiresAt' => $grant['expires_at'],
                'expiredCredits' => $expired ? (string) $take : '0.0000',
                'reopenTransactionId' => RecordId::generate(), 'restoreTransactionId' => RecordId::generate(),
                'expirationTransactionId' => $expired ? RecordId::generate() : null];
            if ($expired) $expiredTotal = $expiredTotal->plus($take);
            else $pdo->prepare('UPDATE credit_grant SET remaining_credits=? WHERE id=?')->execute([(string) $remaining, $grant['id']]);
            $toRestore = $toRestore->minus($take);
        }
        if ((string) $toRestore !== '0.0000') throw new Conflict('Original repayment credits cannot be restored exactly.');
        $receipt = ['transactionId' => $id, 'reversalOfId' => $debit['id'], 'tenantId' => $tenant,
            'usageId' => $debit['usage_id'], 'actorId' => $input->actorId, 'credits' => $input->credits,
            'unpaidCredits' => (string) $unpaidPart, 'restoredCredits' => (string) $restored,
            'expiredCredits' => (string) $expiredTotal, 'restorations' => $parts, 'postedAt' => $now];
        $evidence = json_encode(['formula' => 'native-debt-reversal-v1', 'requestKey' => $input->idempotencyKey,
            'reason' => $input->reason, 'receipt' => $receipt, 'receiptHash' => self::hash($receipt)], JSON_THROW_ON_ERROR);
        $insert = $pdo->prepare('INSERT INTO credit_transaction
            (id,tenant_id,type,idempotency_key,input_hash,credits,usage_id,grant_id,debt_transaction_id,reversal_of_id,actor_id,occurred_at,posted_at,evidence)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $post = static function (string $rowId, string $type, string $key, string $credits, ?string $grant, string $original) use ($insert, $tenant, $input, $debit, $now, $evidence): void {
            $insert->execute([$rowId, $tenant, $type, $key, $input->hash, $credits, $debit['usage_id'], $grant,
                $debit['id'], $original, $input->actorId, $now, $now, $evidence]);
        };
        $post($id, self::TYPE, $input->idempotencyKey, $input->credits, null, $debit['id']);
        foreach ($parts as $index => $part) {
            $key = 'native-correction:' . $id . ':' . $index;
            $post($part['reopenTransactionId'], self::REOPEN, $key, (string) $zero->minus(Amount::fromString($part['credits'])), null, $part['recoveryTransactionId']);
            $post($part['restoreTransactionId'], self::RESTORE, $key, $part['credits'], $part['grantId'], $part['paymentTransactionId']);
            if ($part['expirationTransactionId'] !== null) $post($part['expirationTransactionId'], 'expiration',
                'native-correction-expiry:' . $part['restoreTransactionId'], (string) $zero->minus(Amount::fromString($part['credits'])), $part['grantId'], $part['restoreTransactionId']);
        }
        $balance = $balance->plus($amount)->minus($expiredTotal);
        WalletDebt::repayLocked($pdo, $tenant, $now);
        WalletDebt::assertWallet($pdo, $tenant, (string) $balance, $wallet['reserved_credits']);
        $pdo->prepare('UPDATE tenant_credit_balance SET balance=?,modified_at=? WHERE id=?')->execute([(string) $balance, $now, $wallet['id']]);
        return $receipt;
    }

    public static function receipt(PDO $pdo, array $row): array
    {
        if (!self::validGroup($pdo, $row)) throw new Conflict('Stored native debt correction is inconsistent.');
        return json_decode($row['evidence'], true, 64, JSON_THROW_ON_ERROR)['receipt'];
    }

    public static function validGroup(PDO $pdo, array $root): bool
    {
        if ($root['type'] !== self::TYPE || !self::validPosting($root)) return false;
        $receipt = json_decode($root['evidence'], true, 64, JSON_THROW_ON_ERROR)['receipt'];
        $expected = [$root['id']];
        foreach ($receipt['restorations'] as $part) {
            array_push($expected, $part['reopenTransactionId'], $part['restoreTransactionId']);
            if ($part['expirationTransactionId'] !== null) $expected[] = $part['expirationTransactionId'];
            $q = $pdo->prepare('SELECT expires_at FROM credit_grant WHERE id=? AND tenant_id=? AND deleted=FALSE');
            $q->execute([$part['grantId'], $root['tenant_id']]); $grant = $q->fetch(PDO::FETCH_ASSOC);
            if (!$grant || $grant['expires_at'] !== $part['expiresAt']) return false;
        }
        $q = $pdo->prepare("SELECT * FROM credit_transaction WHERE tenant_id=? AND input_hash=? AND
            (type IN ('searchDebtReversal','searchDebtReopen','searchDebtRestore') OR (type='expiration' AND debt_transaction_id IS NOT NULL))");
        $q->execute([$root['tenant_id'], $root['input_hash']]); $actual = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['evidence'] !== $root['evidence'] || !self::validPosting($row)) return false;
            $actual[] = $row['id'];
        }
        sort($expected); sort($actual);
        return $actual === $expected;
    }

    /** Check receipt/actor/input binding and each correction leg without rewriting original provider facts. */
    public static function validPosting(array $row): bool
    {
        try {
            $e = json_decode($row['evidence'], true, 64, JSON_THROW_ON_ERROR); $r = $e['receipt'] ?? null;
            if (!is_array($r) || array_diff(['transactionId','reversalOfId','tenantId','usageId','actorId','credits','unpaidCredits',
                'restoredCredits','expiredCredits','restorations','postedAt'], array_keys($r)) ||
                !is_string($e['requestKey'] ?? null) || !is_string($e['reason'] ?? null)) return false;
            if (($e['formula'] ?? null) !== 'native-debt-reversal-v1' || ($e['receiptHash'] ?? null) !== self::hash($r) ||
                $row['deleted'] || $row['allocation_id'] !== null || $row['tenant_id'] !== $r['tenantId'] ||
                $row['usage_id'] !== $r['usageId'] || $row['actor_id'] !== $r['actorId'] ||
                $row['debt_transaction_id'] !== $r['reversalOfId'] || $row['posted_at'] !== $r['postedAt'] || $row['occurred_at'] !== $r['postedAt']) return false;
            $input = new ReversalInput($r['tenantId'], $r['reversalOfId'], $e['requestKey'], $r['credits'], $e['reason'], $r['actorId']);
            if ($row['input_hash'] !== $input->hash || !is_array($r['restorations']) || !array_is_list($r['restorations'])) return false;
            $zero = Amount::fromString('0'); $restored = $zero; $expired = $zero;
            $expected = [$r['transactionId'] => [self::TYPE, $e['requestKey'], $input->credits, null, $r['reversalOfId']]];
            foreach ($r['restorations'] as $index => $part) {
                if (!is_array($part) || array_diff(['paymentTransactionId','recoveryTransactionId','grantId','credits','expiresAt',
                    'expiredCredits','reopenTransactionId','restoreTransactionId','expirationTransactionId'], array_keys($part))) return false;
                foreach (['paymentTransactionId','recoveryTransactionId','grantId','reopenTransactionId','restoreTransactionId'] as $field) GrantInput::identity($part[$field]);
                if ($part['expiresAt'] !== null) GrantInput::timestamp($part['expiresAt']);
                if ($part['expirationTransactionId'] !== null) GrantInput::identity($part['expirationTransactionId']);
                $amount = Amount::fromString($part['credits']);
                if ($amount->compareTo($zero) <= 0) return false;
                $restored = $restored->plus($amount);
                $isExpired = $part['expiresAt'] !== null && $part['expiresAt'] <= $r['postedAt'];
                if ($part['expiredCredits'] !== ($isExpired ? $part['credits'] : '0.0000') || ($part['expirationTransactionId'] !== null) !== $isExpired) return false;
                $expired = $expired->plus(Amount::fromString($part['expiredCredits']));
                $key = 'native-correction:' . $r['transactionId'] . ':' . $index;
                foreach ([[$part['reopenTransactionId'], self::REOPEN, $key, (string) $zero->minus($amount), null, $part['recoveryTransactionId']],
                    [$part['restoreTransactionId'], self::RESTORE, $key, $part['credits'], $part['grantId'], $part['paymentTransactionId']]] as $leg) {
                    $id = array_shift($leg); if (isset($expected[$id])) return false; $expected[$id] = $leg;
                }
                if ($isExpired) {
                    $id = $part['expirationTransactionId']; if (isset($expected[$id])) return false;
                    $expected[$id] = ['expiration', 'native-correction-expiry:' . $part['restoreTransactionId'], (string) $zero->minus($amount), $part['grantId'], $part['restoreTransactionId']];
                }
            }
            return $r['restoredCredits'] === (string) $restored && $r['expiredCredits'] === (string) $expired &&
                Amount::fromString($r['unpaidCredits'])->compareTo($zero) >= 0 &&
                (string) Amount::fromString($r['unpaidCredits'])->plus($restored) === $input->credits &&
                ($expected[$row['id']] ?? null) === [$row['type'], $row['idempotency_key'], $row['credits'], $row['grant_id'], $row['reversal_of_id']];
        } catch (\Throwable) { return false; }
    }

    private static function hash(array $value): string { return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)); }
}
