<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\NotFound;
use Espo\ORM\EntityManager;
use LogicException;
use PDO;
use Throwable;

/** Shared lock order: Tenant -> wallet -> grants -> reservations/allocations -> postings. */
final class WalletLock
{
    public function __construct(private EntityManager $entityManager, private Clock $clock) {}

    /** Internal service boundary. The callback and wallet creation commit or roll back together. */
    public function run(string $tenantId, callable $callback): mixed
    {
        GrantInput::identity($tenantId);
        $pdo = $this->entityManager->getPDO();
        if ($pdo->inTransaction()) {
            throw new LogicException('Accounting operations must own their transaction.');
        }
        if (!in_array($pdo->getAttribute(PDO::ATTR_DRIVER_NAME), ['mysql', 'pgsql'], true) ||
            $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new LogicException('Accounting requires MySQL/PostgreSQL PDO with exception mode.');
        }
        $pdo->beginTransaction();
        try {
            $query = $pdo->prepare('SELECT id FROM tenant WHERE id = ? AND deleted = FALSE FOR UPDATE');
            $query->execute([$tenantId]);
            if ($query->fetchColumn() !== $tenantId) {
                throw new NotFound('Unknown accounting tenant.');
            }
            // The parent lock also serializes the first wallet insert (no missing-row lock race).
            $query = $pdo->prepare('SELECT * FROM tenant_credit_balance WHERE tenant_id = ? FOR UPDATE');
            $query->execute([$tenantId]);
            $wallet = $query->fetch(PDO::FETCH_ASSOC);
            $now = $this->clock->now(); // Sample after waiting for the lock, not before.
            if (!$wallet) {
                $id = RecordId::generate();
                $pdo->prepare('INSERT INTO tenant_credit_balance
                    (id, tenant_id, balance, reserved_credits, created_at, modified_at)
                    VALUES (?, ?, ?, ?, ?, ?)')->execute([$id, $tenantId, '0.0000', '0.0000', $now, $now]);
                $wallet = ['id' => $id, 'tenant_id' => $tenantId, 'balance' => '0.0000', 'reserved_credits' => '0.0000'];
            }
            if ($wallet['tenant_id'] !== $tenantId || ($wallet['deleted'] ?? false)) {
                throw new Conflict('Invalid wallet ownership or deleted financial record.');
            }
            NativeSearchDebt::assertWallet($pdo, $tenantId, $wallet['balance'], $wallet['reserved_credits']);
            $result = $callback($pdo, $wallet, $now);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function assertProjection(string $remaining, string $reserved): void
    {
        $held = Amount::fromString($reserved);
        if ($held->compareTo(Amount::fromString('0')) < 0 ||
            Amount::fromString($remaining)->compareTo($held) < 0) {
            throw new Conflict('Invalid credit projection: require remaining >= reserved >= zero.');
        }
    }
}
