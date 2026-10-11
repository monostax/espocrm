<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Espo\Core\Exceptions\NotFound;
use Espo\ORM\EntityManager;
use LogicException;
use PDO;
use Throwable;

/** Internal funds projection. A status result is never an admission or dispatch permission. */
final class BalanceStatus
{
    public function __construct(private EntityManager $entityManager, private Clock $clock) {}

    public function inspect(string $tenantId): array
    {
        GrantInput::identity($tenantId);
        $pdo = $this->entityManager->getPDO();
        if ($pdo->inTransaction()) {
            throw new LogicException('Balance status must own its read-only transaction.');
        }
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'pgsql'], true) ||
            $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new LogicException('Balance status requires MySQL/PostgreSQL PDO with exception mode.');
        }
        if ($driver === 'mysql') {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        }
        $pdo->beginTransaction();
        try {
            if ($driver === 'pgsql') {
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            $query = $pdo->prepare('SELECT id FROM tenant WHERE id = ? AND deleted = FALSE');
            $query->execute([$tenantId]);
            if ($query->fetchColumn() === false) {
                throw new NotFound('Unknown accounting tenant.');
            }
            $now = $this->clock->now();
            $query = $pdo->prepare('SELECT balance, reserved_credits, deleted FROM tenant_credit_balance WHERE tenant_id = ?');
            $query->execute([$tenantId]);
            $wallet = $query->fetch(PDO::FETCH_ASSOC);
            $query = $pdo->prepare('SELECT COUNT(*) AS grant_count,
                COALESCE(SUM(remaining_credits), 0) AS balance,
                COALESCE(SUM(reserved_credits), 0) AS held,
                COALESCE(SUM(CASE WHEN expires_at <= ? THEN remaining_credits - reserved_credits ELSE 0 END), 0) AS expired,
                COALESCE(SUM(CASE WHEN deleted = TRUE OR reserved_credits < 0 OR remaining_credits < reserved_credits
                    OR remaining_credits > granted_credits THEN 1 ELSE 0 END), 0) AS invalid
                FROM credit_grant WHERE tenant_id = ?');
            $query->execute([$now, $tenantId]);
            $grants = $query->fetch(PDO::FETCH_ASSOC);
            $balance = Amount::fromString($wallet ? $wallet['balance'] : '0');
            $held = Amount::fromString($wallet ? $wallet['reserved_credits'] : '0');
            $expired = Amount::fromString((string) $grants['expired']);
            $zero = Amount::fromString('0');
            $debt = NativeSearchDebt::outstanding($pdo, $tenantId);
            if (($wallet && $wallet['deleted']) || (!$wallet && (int) $grants['grant_count'] !== 0) ||
                (int) $grants['invalid'] !== 0 || $held->compareTo($zero) < 0 ||
                $balance->plus($debt)->compareTo($held) < 0 ||
                $balance->plus($debt)->compareTo(Amount::fromString((string) $grants['balance'])) !== 0 ||
                $held->compareTo(Amount::fromString((string) $grants['held'])) !== 0) {
                throw new LogicException('Inconsistent wallet/grant balance projection.');
            }
            $available = $balance->minus($held)->minus($expired);
            $pdo->commit();
            return ['tenantId' => $tenantId, 'observedAt' => $now, 'walletExists' => $wallet !== false,
                'balance' => (string) $balance, 'reservedCredits' => (string) $held,
                'pendingExpirationCredits' => (string) $expired, 'availableCredits' => (string) $available];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
