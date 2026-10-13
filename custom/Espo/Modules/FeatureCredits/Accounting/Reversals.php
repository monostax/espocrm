<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Espo\Core\Exceptions\{Conflict, NotFound};
use PDO;

/** Append-only, allocation-linked debit corrections serialized with all wallet mutations. */
final class Reversals
{
    public function __construct(private WalletLock $walletLock, private Funding $funding) {}

    public function reverse(ReversalInput $input): array
    {
        return $this->walletLock->run($input->tenantId, function (PDO $pdo, array $wallet, string $now) use ($input): array {
            $q = $pdo->prepare("SELECT * FROM credit_transaction WHERE tenant_id = ? AND type = 'reversal' AND idempotency_key = ?");
            $q->execute([$input->tenantId, $input->idempotencyKey]);
            if ($prior = $q->fetch(PDO::FETCH_ASSOC)) {
                if ($prior['deleted'] || $prior['input_hash'] !== $input->hash) throw new Conflict('Conflicting reversal replay.');
                return json_decode($prior['evidence'], true, 512, JSON_THROW_ON_ERROR)['receipt'];
            }
            $q = $pdo->prepare('SELECT * FROM credit_transaction WHERE id = ? AND tenant_id = ?');
            $q->execute([$input->transactionId, $input->tenantId]);
            $debit = $q->fetch(PDO::FETCH_ASSOC);
            if (!$debit || $debit['deleted']) throw new NotFound('Unknown original credit transaction.');
            if ($debit['type'] !== 'debit' || Amount::fromString($debit['credits'])->compareTo(Amount::fromString('0')) >= 0) {
                throw new Conflict('Only posted grant-backed usage debits can be reversed.');
            }
            $q = $pdo->prepare("SELECT a.id FROM credit_allocation a
                JOIN credit_reservation r ON r.id = a.reservation_id AND r.tenant_id = a.tenant_id
                JOIN credit_usage u ON u.id = r.usage_id AND u.tenant_id = r.tenant_id
                WHERE a.id = ? AND a.tenant_id = ? AND a.grant_id = ? AND a.debit_transaction_id = ?
                AND a.consumed_credits = ? AND a.settled_at IS NOT NULL AND a.deleted = FALSE
                AND r.deleted = FALSE AND r.state = 'settled' AND u.deleted = FALSE AND u.state = 'settled' AND u.id = ?");
            $q->execute([$debit['allocation_id'], $input->tenantId, $debit['grant_id'], $debit['id'],
                (string) Amount::fromString('0')->minus(Amount::fromString($debit['credits'])), $debit['usage_id']]);
            if ($q->fetchColumn() === false) throw new Conflict('Original settled allocation is invalid.');
            $q = $pdo->prepare("SELECT COALESCE(SUM(credits), 0) FROM credit_transaction
                WHERE tenant_id = ? AND type = 'reversal' AND reversal_of_id = ?");
            $q->execute([$input->tenantId, $debit['id']]);
            $amount = Amount::fromString($input->credits);
            $reversed = Amount::fromString((string) $q->fetchColumn())->plus($amount);
            if ($reversed->compareTo(Amount::fromString('0')->minus(Amount::fromString($debit['credits']))) > 0) {
                throw new Conflict('Reversal exceeds the original unreversed debit.');
            }
            $balance = $this->funding->expireLocked($pdo, $input->tenantId, Amount::fromString($wallet['balance']), $now);
            $q = $pdo->prepare('SELECT * FROM credit_grant WHERE id = ? AND tenant_id = ? FOR UPDATE');
            $q->execute([$debit['grant_id'], $input->tenantId]);
            $grant = $q->fetch(PDO::FETCH_ASSOC);
            if (!$grant || $grant['deleted']) throw new Conflict('Original grant is unavailable.');
            WalletLock::assertProjection($grant['remaining_credits'], $grant['reserved_credits']);
            $remaining = Amount::fromString($grant['remaining_credits'])->plus($amount);
            if ($remaining->compareTo(Amount::fromString($grant['granted_credits'])) > 0) throw new Conflict('Restoration exceeds original grant.');
            $expired = $grant['expires_at'] !== null && $grant['expires_at'] <= $now;
            $id = RecordId::generate();
            $expirationId = $expired ? RecordId::generate() : null;
            $receipt = ['transactionId' => $id, 'reversalOfId' => $debit['id'], 'tenantId' => $input->tenantId,
                'usageId' => $debit['usage_id'], 'allocationId' => $debit['allocation_id'], 'grantId' => $grant['id'],
                'credits' => $input->credits, 'expiredCredits' => $expired ? $input->credits : '0.0000',
                'expirationTransactionId' => $expirationId, 'expiresAt' => $grant['expires_at'], 'postedAt' => $now];
            $evidence = json_encode(['formula' => 'usage-reversal-v1', 'reason' => $input->reason, 'receipt' => $receipt], JSON_THROW_ON_ERROR);
            $insert = $pdo->prepare('INSERT INTO credit_transaction
                (id, tenant_id, type, idempotency_key, input_hash, credits, usage_id, grant_id, allocation_id,
                 reversal_of_id, actor_id, occurred_at, posted_at, evidence) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $insert->execute([$id, $input->tenantId, 'reversal', $input->idempotencyKey, $input->hash, $input->credits,
                $debit['usage_id'], $grant['id'], $debit['allocation_id'], $debit['id'], $input->actorId, $now, $now, $evidence]);
            if ($expired) {
                $insert->execute([$expirationId, $input->tenantId, 'expiration', 'reversal-expiry:' . $id,
                    $input->hash, (string) Amount::fromString('0')->minus($amount), $debit['usage_id'], $grant['id'],
                    $debit['allocation_id'], $id, $input->actorId, $now, $now, $evidence]);
                $remaining = $remaining->minus($amount);
            } else {
                $balance = $balance->plus($amount);
            }
            $pdo->prepare('UPDATE credit_grant SET remaining_credits = ? WHERE id = ?')->execute([(string) $remaining, $grant['id']]);
            WalletDebt::repayLocked($pdo, $input->tenantId, $now);
            WalletDebt::assertWallet($pdo, $input->tenantId, (string) $balance, $wallet['reserved_credits']);
            $pdo->prepare('UPDATE tenant_credit_balance SET balance = ?, modified_at = ? WHERE id = ?')
                ->execute([(string) $balance, $now, $wallet['id']]);
            return $receipt;
        });
    }
}
