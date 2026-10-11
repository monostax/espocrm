<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Espo\Core\Exceptions\NotFound;
use Espo\ORM\EntityManager;
use LogicException;
use PDO;
use Throwable;

/** Bounded grant projections, including exhausted lots; never admission permission. */
final class GrantBalances
{
    public function __construct(private EntityManager $entityManager, private Clock $clock) {}

    public function inspect(GrantBalancesQuery $input): array
    {
        $pdo = $this->entityManager->getPDO();
        if ($pdo->inTransaction()) {
            throw new LogicException('Grant balances must own their read-only transaction.');
        }
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'pgsql'], true) ||
            $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new LogicException('Grant balances require MySQL/PostgreSQL PDO with exception mode.');
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
            $query->execute([$input->tenantId]);
            if ($query->fetchColumn() === false) {
                throw new NotFound('Unknown accounting tenant.');
            }
            $now = $this->clock->now();
            $params = [$input->tenantId];
            $where = '';
            if ($input->beforeId !== null) {
                $where = ' AND (created_at < ? OR (created_at = ? AND id < ?))';
                array_push($params, $input->beforeCreatedAt, $input->beforeCreatedAt, $input->beforeId);
            }
            // Include deleted rows to fail closed rather than silently hide invalid accounting lots.
            $query = $pdo->prepare('SELECT id, source_type, granted_credits, remaining_credits,
                reserved_credits, expires_at, created_at, deleted FROM credit_grant WHERE tenant_id = ?' . $where .
                ' ORDER BY created_at DESC, id DESC LIMIT ' . ($input->limit + 1));
            $query->execute($params);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            $hasMore = count($rows) > $input->limit;
            $zero = Amount::fromString('0');
            $list = [];
            foreach ($rows as $index => $row) {
                $granted = Amount::fromString($row['granted_credits']);
                $remaining = Amount::fromString($row['remaining_credits']);
                $reserved = Amount::fromString($row['reserved_credits']);
                if ($row['deleted'] || !in_array($row['source_type'], ['purchase', 'subscription', 'migration'], true) ||
                    $granted->compareTo($zero) <= 0 || $reserved->compareTo($zero) < 0 ||
                    $remaining->compareTo($reserved) < 0 || $granted->compareTo($remaining) < 0) {
                    throw new LogicException('Invalid grant balance projection.');
                }
                if ($index === $input->limit) {
                    break;
                }
                $unreserved = $remaining->minus($reserved);
                $expired = $row['expires_at'] !== null && $row['expires_at'] <= $now;
                $list[] = ['id' => $row['id'], 'sourceType' => $row['source_type'],
                    'grantedCredits' => (string) $granted, 'remainingCredits' => (string) $remaining,
                    'reservedCredits' => (string) $reserved,
                    'pendingExpirationCredits' => (string) ($expired ? $unreserved : $zero),
                    'availableCredits' => (string) ($expired ? $zero : $unreserved),
                    'expiresAt' => $row['expires_at'], 'createdAt' => $row['created_at']];
            }
            $last = $list ? $list[count($list) - 1] : null;
            $next = $hasMore ? GrantBalancesQuery::cursor($input->tenantId, $last['createdAt'], $last['id']) : null;
            $pdo->commit();
            return ['tenantId' => $input->tenantId, 'observedAt' => $now, 'list' => $list, 'nextCursor' => $next];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
