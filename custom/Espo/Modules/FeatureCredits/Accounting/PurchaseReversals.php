<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Espo\Core\Exceptions\Conflict;
use PDO;

/** Internal full-purchase revocation, called only after authoritative provider verification. */
final class PurchaseReversals
{
    public const TYPE = 'purchaseReversal';

    public static function receiptLocked(PDO $pdo, string $tenant, string $grantId): ?array
    {
        $q = $pdo->prepare('SELECT * FROM credit_transaction WHERE tenant_id=? AND type=? AND idempotency_key=?');
        $q->execute([$tenant, self::TYPE, 'purchase-reversal:' . $grantId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $evidence = json_decode($row['evidence'], true, 32, JSON_THROW_ON_ERROR);
        if ($row['deleted'] || $row['grant_id'] !== $grantId || $row['input_hash'] !== hash('sha256', $row['evidence']) ||
            ($evidence['receipt']['transactionId'] ?? null) !== $row['id']) throw new Conflict('Invalid purchase reversal receipt.');
        return $evidence['receipt'];
    }

    /** Caller owns WalletLock; payment observation and this mutation must commit together. */
    public static function reverseLocked(PDO $pdo, array $wallet, string $now, string $grantId, array $verifiedEvidence): array
    {
        if (!$pdo->inTransaction()) throw new \LogicException('Purchase reversal requires wallet transaction.');
        GrantInput::identity($grantId);
        $tenant = $wallet['tenant_id'];
        if ($receipt = self::receiptLocked($pdo, $tenant, $grantId)) return $receipt;
        $q = $pdo->prepare('SELECT g.* FROM credit_grant g JOIN credit_transaction t ON t.id=g.grant_transaction_id
            AND t.tenant_id=g.tenant_id AND t.grant_id=g.id AND t.type=? AND t.credits=g.granted_credits AND t.deleted=FALSE
            WHERE g.id=? AND g.tenant_id=? FOR UPDATE');
        $q->execute(['grant', $grantId, $tenant]); $grant = $q->fetch(PDO::FETCH_ASSOC);
        if (!$grant || $grant['deleted'] || $grant['source_type'] !== 'purchase' || $grant['expires_at'] !== null) {
            throw new Conflict('Original non-expiring purchase grant required.');
        }
        WalletLock::assertProjection($grant['remaining_credits'], $grant['reserved_credits']);
        $free = Amount::fromString($grant['remaining_credits'])->minus(Amount::fromString($grant['reserved_credits']));
        $total = Amount::fromString($grant['granted_credits']);
        $debt = $total->minus($free);
        if ($debt->compareTo(Amount::fromString('0')) < 0) throw new Conflict('Purchase grant exceeds original issuance.');
        $id = RecordId::generate(); $debtId = (string) $debt === '0.0000' ? null : RecordId::generate();
        $receipt = ['transactionId' => $id, 'grantId' => $grantId, 'grantTransactionId' => $grant['grant_transaction_id'],
            'revokedCredits' => (string) $total, 'recoveredCredits' => (string) $free, 'debtCredits' => (string) $debt,
            'debtTransactionId' => $debtId, 'postedAt' => $now];
        $evidence = json_encode(['formula' => 'purchase-reversal-v1', 'verifiedSource' => $verifiedEvidence, 'receipt' => $receipt], JSON_THROW_ON_ERROR);
        $insert = $pdo->prepare('INSERT INTO credit_transaction
            (id,tenant_id,type,idempotency_key,input_hash,credits,grant_id,reversal_of_id,occurred_at,posted_at,evidence)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $key = 'purchase-reversal:' . $grantId;
        // A zero-credit root still durably revokes a fully depleted purchase exactly once.
        $insert->execute([$id, $tenant, self::TYPE, $key, hash('sha256', $evidence),
            (string) Amount::fromString('0')->minus($free), $grantId, $grant['grant_transaction_id'], $now, $now, $evidence]);
        if ($debtId !== null) {
            $insert->execute([$debtId, $tenant, WalletDebt::BILLING, $key, hash('sha256', $evidence),
                (string) Amount::fromString('0')->minus($debt), null, $id, $now, $now, $evidence]);
        }
        $pdo->prepare('UPDATE credit_grant SET remaining_credits=reserved_credits WHERE id=?')->execute([$grantId]);
        $balance = Amount::fromString($wallet['balance'])->minus($total);
        WalletDebt::repayLocked($pdo, $tenant, $now);
        WalletDebt::assertWallet($pdo, $tenant, (string) $balance, $wallet['reserved_credits']);
        $pdo->prepare('UPDATE tenant_credit_balance SET balance=?, modified_at=? WHERE id=?')->execute([(string) $balance, $now, $wallet['id']]);
        return $receipt;
    }
}
