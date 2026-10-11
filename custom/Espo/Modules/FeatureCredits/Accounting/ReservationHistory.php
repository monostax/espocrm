<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Brick\Math\BigDecimal;
use Espo\Core\Exceptions\NotFound;
use Espo\ORM\EntityManager;
use LogicException;
use PDO;
use Throwable;

/** Bounded current reservation projections, including closed holds. */
final class ReservationHistory
{
    public function __construct(private EntityManager $entityManager, private Clock $clock) {}

    public function inspect(ReservationHistoryQuery $input): array
    {
        $pdo = $this->entityManager->getPDO();
        if ($pdo->inTransaction()) {
            throw new LogicException('Reservation history must own its read-only transaction.');
        }
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'pgsql'], true) ||
            $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new LogicException('Reservation history requires MySQL/PostgreSQL PDO with exception mode.');
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
                $where = ' AND (r.created_at < ? OR (r.created_at = ? AND r.id < ?))';
                array_push($params, $input->beforeCreatedAt, $input->beforeCreatedAt, $input->beforeId);
            }
            // LEFT JOIN and retain deleted rows so invalid accounting parents cannot silently disappear.
            $query = $pdo->prepare('SELECT r.id, r.usage_id, r.state, r.reserved_credits,
                r.accrued_credits_exact, r.created_at, r.modified_at, r.settled_at, r.deleted,
                u.id AS parent_id, u.deleted AS parent_deleted, u.state AS usage_state,
                u.settled_at AS usage_settled_at, u.settled_credits, u.operation_type
                FROM credit_reservation r LEFT JOIN credit_usage u ON u.id = r.usage_id
                    AND u.tenant_id = r.tenant_id AND u.execution_id = r.execution_id
                WHERE r.tenant_id = ?' . $where .
                ' ORDER BY r.created_at DESC, r.id DESC LIMIT ' . ($input->limit + 1));
            $query->execute($params);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            $hasMore = count($rows) > $input->limit;
            $zero = Amount::fromString('0');
            $list = [];
            foreach ($rows as $index => $row) {
                $reserved = Amount::fromString($row['reserved_credits']);
                if (!preg_match('/^(0|[1-9][0-9]*)(\.[0-9]+)?$/D', $row['accrued_credits_exact'])) {
                    throw new LogicException('Invalid reservation accrual.');
                }
                $accrued = BigDecimal::of($row['accrued_credits_exact']);
                $settled = $row['settled_credits'] === null ? null : Amount::fromString($row['settled_credits']);
                $closed = in_array($row['state'], ['settled', 'released'], true);
                if ($row['deleted'] || $row['parent_id'] === null || $row['parent_deleted'] ||
                    !in_array($row['operation_type'], ['ai', 'apollo'], true) ||
                    $reserved->compareTo($zero) < 0 ||
                    (!$closed && ($row['state'] !== 'held' || $row['usage_state'] !== 'admitted' ||
                        $row['settled_at'] !== null || $row['usage_settled_at'] !== null || $settled !== null ||
                        $accrued->compareTo(NativeSearchDebt::accrualLimit($pdo, $input->tenantId, $row['id'], (string) $reserved)) > 0)) ||
                    ($closed && ($row['usage_state'] !== $row['state'] || $reserved->compareTo($zero) !== 0 ||
                        $row['settled_at'] === null || $row['settled_at'] !== $row['usage_settled_at'] ||
                        $settled === null || $settled->compareTo($zero) < 0 ||
                        $settled->compareTo(Amount::settlement($accrued)) !== 0)) ||
                    ($row['state'] === 'released' && (!$accrued->isZero() || $settled->compareTo($zero) !== 0))) {
                    throw new LogicException('Invalid reservation projection or usage parent.');
                }
                if ($index === $input->limit) {
                    break;
                }
                $list[] = ['id' => $row['id'], 'usageId' => $row['usage_id'],
                    'operationType' => $row['operation_type'], 'state' => $row['state'],
                    'reservedCredits' => (string) $reserved,
                    'accruedCreditsExact' => (string) $accrued->strippedOfTrailingZeros(),
                    'settledCredits' => $settled === null ? null : (string) $settled,
                    'createdAt' => $row['created_at'], 'modifiedAt' => $row['modified_at'],
                    'settledAt' => $row['settled_at']];
            }
            $last = $list ? $list[count($list) - 1] : null;
            $next = $hasMore ? ReservationHistoryQuery::cursor($input->tenantId, $last['createdAt'], $last['id']) : null;
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
