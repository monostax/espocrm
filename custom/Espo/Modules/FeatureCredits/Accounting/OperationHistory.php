<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Brick\Math\BigDecimal;
use Espo\Core\Exceptions\NotFound;
use Espo\ORM\EntityManager;
use LogicException;
use PDO;
use Throwable;

/** Operation-led reporting retains usage even when its reservation is corrupt or missing. */
final class OperationHistory
{
    public function __construct(private EntityManager $entityManager, private Clock $clock) {}

    public function inspect(OperationHistoryQuery $input): array
    {
        $pdo = $this->entityManager->getPDO();
        if ($pdo->inTransaction()) {
            throw new LogicException('Operation history must own its read-only transaction.');
        }
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'pgsql'], true) ||
            $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new LogicException('Operation history requires MySQL/PostgreSQL PDO with exception mode.');
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
                $where = ' AND (u.admitted_at < ? OR (u.admitted_at = ? AND u.id < ?))';
                array_push($params, $input->beforeAdmittedAt, $input->beforeAdmittedAt, $input->beforeId);
            }
            $query = $pdo->prepare('SELECT u.id, u.deleted, u.operation_type, u.billing_regime,
                u.state, u.admitted_at, u.settled_at, u.settled_credits,
                r.id AS reservation_id, r.deleted AS reservation_deleted, r.state AS reservation_state,
                r.settled_at AS reservation_settled_at, r.reserved_credits, r.accrued_credits_exact
                FROM credit_usage u LEFT JOIN credit_reservation r ON r.usage_id = u.id
                    AND r.tenant_id = u.tenant_id AND r.execution_id = u.execution_id
                WHERE u.tenant_id = ?' . $where .
                ' ORDER BY u.admitted_at DESC, u.id DESC LIMIT ' . ($input->limit + 1));
            $query->execute($params);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            $hasMore = count($rows) > $input->limit;
            $list = [];
            $zero = Amount::fromString('0');
            foreach ($rows as $index => $row) {
                if ($row['deleted'] || $row['reservation_id'] === null || $row['reservation_deleted'] ||
                    !in_array($row['operation_type'], ['ai', 'apollo'], true) ||
                    $row['billing_regime'] !== 'unified-prepaid-v1' ||
                    !preg_match('/^(0|[1-9][0-9]*)(\.[0-9]+)?$/D', $row['accrued_credits_exact'])) {
                    throw new LogicException('Invalid operation or reservation projection.');
                }
                $reserved = Amount::fromString($row['reserved_credits']);
                $accrued = BigDecimal::of($row['accrued_credits_exact']);
                $settled = $row['settled_credits'] === null ? null : Amount::fromString($row['settled_credits']);
                $closed = in_array($row['state'], ['settled', 'released'], true);
                if ($reserved->compareTo($zero) < 0 ||
                    (!$closed && ($row['state'] !== 'admitted' || $row['reservation_state'] !== 'held' ||
                        $settled !== null || $row['settled_at'] !== null || $row['reservation_settled_at'] !== null ||
                        $accrued->compareTo(NativeSearchDebt::accrualLimit($pdo, $input->tenantId, $row['reservation_id'], (string) $reserved)) > 0)) ||
                    ($closed && ($row['state'] !== $row['reservation_state'] || $reserved->compareTo($zero) !== 0 ||
                        $row['settled_at'] === null || $row['settled_at'] !== $row['reservation_settled_at'] ||
                        $settled === null || $settled->compareTo($zero) < 0 ||
                        $settled->compareTo(Amount::settlement($accrued)) !== 0)) ||
                    ($row['state'] === 'released' && !$accrued->isZero())) {
                    throw new LogicException('Invalid operation settlement projection.');
                }
                if ($index === $input->limit) {
                    break;
                }
                $list[] = ['id' => $row['id'], 'reservationId' => $row['reservation_id'],
                    'operationType' => $row['operation_type'], 'billingRegime' => $row['billing_regime'],
                    'state' => $row['state'], 'reservedCredits' => (string) $reserved,
                    'accruedCreditsExact' => (string) $accrued->strippedOfTrailingZeros(),
                    'settledCredits' => $settled === null ? null : (string) $settled,
                    'admittedAt' => $row['admitted_at'], 'settledAt' => $row['settled_at']];
            }
            $last = $list ? $list[count($list) - 1] : null;
            $next = $hasMore ? OperationHistoryQuery::cursor($input->tenantId, $last['admittedAt'], $last['id']) : null;
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
