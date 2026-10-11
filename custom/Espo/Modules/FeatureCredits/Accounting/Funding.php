<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Espo\Core\Exceptions\Conflict;
use InvalidArgumentException;
use PDO;

/** Internal accounting only: no HTTP action, payment verification, or user-authorized mutation API. */
final class Funding
{
    public function __construct(private WalletLock $walletLock) {}

    /** Returns immutable original posting data, including on replay after expiration/consumption. */
    public function grant(GrantInput $input): array
    {
        return $this->walletLock->run($input->tenantId, function (PDO $pdo, array $wallet, string $now) use ($input): array {
            $query = $pdo->prepare('SELECT * FROM credit_grant
                WHERE tenant_id = ? AND source_type = ? AND source_key = ? FOR UPDATE');
            $query->execute([$input->tenantId, $input->sourceType, $input->sourceKey]);
            $existing = $query->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ($existing['deleted'] || $existing['input_hash'] !== $input->hash) {
                    throw new Conflict('Funding identity already has different input.');
                }
                return $this->receipt($existing);
            }
            if ($input->occurredAt > $now) {
                throw new InvalidArgumentException('Funding cannot occur in the future.');
            }
            if ($input->actorId !== null) {
                $query = $pdo->prepare('SELECT id FROM ' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? '`user`' : '"user"') .
                    ' WHERE id = ? AND deleted = FALSE');
                $query->execute([$input->actorId]);
                if ($query->fetchColumn() !== $input->actorId) {
                    throw new InvalidArgumentException('Unknown funding actor.');
                }
            }
            // Lock/sweep existing grants before inserting any new financial records.
            // Obsolete funds must not cause a transient wallet overflow on renewal.
            $balance = $this->expireLocked($pdo, $input->tenantId, Amount::fromString($wallet['balance']), $now);
            $grantId = RecordId::generate();
            $transactionId = RecordId::generate();
            $this->post($pdo, $transactionId, $input->tenantId, 'grant', $grantId, $input->hash,
                $input->credits, $grantId, $input->actorId, $input->occurredAt, $now, $input->snapshotJson);
            $pdo->prepare('INSERT INTO credit_grant
                (id, tenant_id, source_type, source_key, input_hash, grant_transaction_id,
                 granted_credits, remaining_credits, reserved_credits, expires_at, created_at, source_snapshot)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                    $grantId, $input->tenantId, $input->sourceType, $input->sourceKey, $input->hash,
                    $transactionId, $input->credits, $input->credits, '0.0000', $input->expiresAt, $now, $input->snapshotJson,
                ]);
            // Delayed renewal/migration delivery is posted then expired in this same transaction.
            if ($input->expiresAt !== null && $input->expiresAt <= $now) {
                $this->expireGrant($pdo, $input->tenantId, $grantId, $input->credits, $input->expiresAt, $now);
            } else {
                $balance = $balance->plus(Amount::fromString($input->credits));
            }
            $this->saveBalance($pdo, $wallet, $balance, $now);
            return $this->receipt([
                'id' => $grantId, 'grant_transaction_id' => $transactionId,
                'granted_credits' => $input->credits, 'expires_at' => $input->expiresAt,
            ]);
        });
    }

    /** Sweep only unreserved expired funds. Repeated sweeps have no additional financial effect. */
    public function expire(string $tenantId): array
    {
        return $this->walletLock->run($tenantId, function (PDO $pdo, array $wallet, string $now) use ($tenantId): array {
            $balance = $this->expireLocked($pdo, $tenantId, Amount::fromString($wallet['balance']), $now);
            $this->saveBalance($pdo, $wallet, $balance, $now);
            return ['balance' => (string) $balance, 'reservedCredits' => $wallet['reserved_credits']];
        });
    }

    /** Internal collaborator only: caller must own WalletLock's transaction and save the returned balance. */
    public function expireLocked(PDO $pdo, string $tenantId, Amount $balance, string $now): Amount
    {
        $query = $pdo->prepare('SELECT * FROM credit_grant
            WHERE tenant_id = ? AND expires_at <= ? ORDER BY expires_at, id FOR UPDATE');
        $query->execute([$tenantId, $now]);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $grant) {
            if ($grant['deleted']) {
                throw new Conflict('Deleted financial grant.');
            }
            WalletLock::assertProjection($grant['remaining_credits'], $grant['reserved_credits']);
            $free = Amount::fromString($grant['remaining_credits'])->minus(Amount::fromString($grant['reserved_credits']));
            if ($free->compareTo(Amount::fromString('0')) === 0) {
                continue;
            }
            $this->expireGrant($pdo, $tenantId, $grant['id'], (string) $free, $grant['expires_at'], $now);
            $balance = $balance->minus($free);
        }
        return $balance;
    }

    private function expireGrant(PDO $pdo, string $tenantId, string $grantId, string $credits, string $expiresAt, string $now): void
    {
        // One sweep posting per grant. Late releases must post allocation-scoped expirations,
        // never restore expired funds or reuse this key (settlement is a separate service).
        $key = 'sweep:' . $grantId;
        $hash = hash('sha256', json_encode(['expiration-v1', $tenantId, $grantId, $credits, $expiresAt], JSON_THROW_ON_ERROR));
        $this->post($pdo, RecordId::generate(), $tenantId, 'expiration', $key, $hash,
            (string) Amount::fromString('0')->minus(Amount::fromString($credits)), $grantId,
            null, $expiresAt, $now, json_encode(['reason' => 'grant_expired'], JSON_THROW_ON_ERROR));
        $pdo->prepare('UPDATE credit_grant SET remaining_credits = reserved_credits WHERE id = ? AND tenant_id = ?')
            ->execute([$grantId, $tenantId]);
    }

    private function saveBalance(PDO $pdo, array $wallet, Amount $balance, string $now): void
    {
        NativeSearchDebt::repayLocked($pdo, $wallet['tenant_id'], $now);
        NativeSearchDebt::assertWallet($pdo, $wallet['tenant_id'], (string) $balance, $wallet['reserved_credits']);
        $pdo->prepare('UPDATE tenant_credit_balance SET balance = ?, modified_at = ? WHERE id = ? AND tenant_id = ?')
            ->execute([(string) $balance, $now, $wallet['id'], $wallet['tenant_id']]);
    }

    private function post(
        PDO $pdo, string $id, string $tenantId, string $type, string $key, string $hash,
        string $credits, string $grantId, ?string $actorId, string $occurredAt, string $postedAt, string $evidence,
    ): void {
        $pdo->prepare('INSERT INTO credit_transaction
            (id, tenant_id, type, idempotency_key, input_hash, credits, grant_id, actor_id, occurred_at, posted_at, evidence)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $id, $tenantId, $type, $key, $hash, $credits, $grantId, $actorId, $occurredAt, $postedAt, $evidence,
            ]);
    }

    private function receipt(array $grant): array
    {
        return [
            'grantId' => $grant['id'], 'transactionId' => $grant['grant_transaction_id'],
            'grantedCredits' => $grant['granted_credits'], 'expiresAt' => $grant['expires_at'],
        ];
    }
}
