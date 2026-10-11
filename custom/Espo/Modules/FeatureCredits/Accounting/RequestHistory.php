<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Brick\Math\BigDecimal;
use Espo\Core\Exceptions\NotFound;
use Espo\ORM\EntityManager;
use LogicException;
use PDO;
use Throwable;

/** Bounded AI request projections; prices are not operation-level posted charges. */
final class RequestHistory
{
    public function __construct(private EntityManager $entityManager, private Clock $clock) {}

    public function inspect(RequestHistoryQuery $input): array
    {
        $pdo = $this->entityManager->getPDO();
        if ($pdo->inTransaction()) {
            throw new LogicException('Request history must own its read-only transaction.');
        }
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'pgsql'], true) ||
            $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new LogicException('Request history requires MySQL/PostgreSQL PDO with exception mode.');
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
                $where = ' AND (q.authorized_at < ? OR (q.authorized_at = ? AND q.id < ?))';
                array_push($params, $input->beforeAuthorizedAt, $input->beforeAuthorizedAt, $input->beforeId);
            }
            // Retain invalid/deleted parents so corruption fails closed instead of hiding requests.
            $query = $pdo->prepare('SELECT q.id, q.usage_id, q.reservation_id, q.deleted,
                q.authorized_credits, q.priced_credits_exact, q.pricing_snapshot, q.outcome, q.metering_state,
                q.billing_state, q.waiver_reason, q.authorized_at, q.completed_at,
                u.id AS parent_id, u.deleted AS parent_deleted, u.state AS usage_state, u.operation_type,
                r.id AS hold_id, r.deleted AS hold_deleted, r.state AS reservation_state
                FROM credit_request q LEFT JOIN credit_usage u ON u.id = q.usage_id AND u.tenant_id = q.tenant_id
                LEFT JOIN credit_reservation r ON r.id = q.reservation_id AND r.tenant_id = q.tenant_id
                    AND r.usage_id = q.usage_id AND r.execution_id = u.execution_id
                WHERE q.tenant_id = ?' . $where .
                ' ORDER BY q.authorized_at DESC, q.id DESC LIMIT ' . ($input->limit + 1));
            $query->execute($params);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            $hasMore = count($rows) > $input->limit;
            $list = [];
            foreach ($rows as $index => $row) {
                $item = $this->projection($row);
                if ($index === $input->limit) {
                    break;
                }
                $list[] = $item;
            }
            $last = $list ? $list[count($list) - 1] : null;
            $next = $hasMore ? RequestHistoryQuery::cursor($input->tenantId, $last['authorizedAt'], $last['id']) : null;
            $pdo->commit();
            return ['tenantId' => $input->tenantId, 'observedAt' => $now, 'list' => $list, 'nextCursor' => $next];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function projection(array $row): array
    {
        $authorized = Amount::fromString($row['authorized_credits']);
        $price = null;
        if ($row['priced_credits_exact'] !== null) {
            if (!preg_match('/^(0|[1-9][0-9]*)(\.[0-9]+)?$/D', $row['priced_credits_exact'])) {
                throw new LogicException('Invalid request price.');
            }
            $price = BigDecimal::of($row['priced_credits_exact']);
        }
        $inFlight = $row['outcome'] === 'inFlight';
        $failure = $row['outcome'] === 'infrastructureFailure';
        $unknown = $row['metering_state'] === 'unknown';
        $measured = $row['metering_state'] === 'measured';
        $unrecoverable = $row['metering_state'] === 'unrecoverable';
        $pending = $row['billing_state'] === 'pending';
        $billable = $row['billing_state'] === 'billable';
        $waived = $row['billing_state'] === 'waived';
        $closed = $row['reservation_state'] === 'settled';
        if ($row['deleted'] || $row['parent_id'] === null || $row['parent_deleted'] ||
            $row['hold_id'] === null || $row['hold_deleted'] || $row['operation_type'] !== 'ai' ||
            ($closed ? ($row['usage_state'] !== 'settled' || $pending || $inFlight) :
                ($row['reservation_state'] !== 'held' || $row['usage_state'] !== 'admitted')) ||
            $authorized->compareTo(Amount::fromString('0')) < 0 ||
            !in_array($row['outcome'], ['inFlight', 'success', 'cancelled', 'superseded', 'infrastructureFailure'], true) ||
            (!$unknown && !$measured && !$unrecoverable) || (!$pending && !$billable && !$waived) ||
            ($inFlight !== ($row['completed_at'] === null)) ||
            ($measured !== ($price !== null)) ||
            ($inFlight && (!$pending || !$unknown)) ||
            ($pending && (!$unknown || $failure)) ||
            ($billable && (!$measured || $inFlight || $failure ||
                ($price->compareTo(BigDecimal::of((string) $authorized)) > 0 &&
                    !NativeSearchDebt::permitted(json_decode($row['pricing_snapshot'], true, 512, JSON_THROW_ON_ERROR))))) ||
            (!$waived && $row['waiver_reason'] !== null) ||
            ($waived && !($failure && !$unrecoverable && $row['waiver_reason'] === 'infrastructure_failure') &&
                !(in_array($row['outcome'], ['cancelled', 'superseded'], true) && $unrecoverable &&
                    $row['waiver_reason'] === 'unrecoverable_cancellation'))) {
            throw new LogicException('Invalid request projection or accounting parent.');
        }
        return ['id' => $row['id'], 'usageId' => $row['usage_id'], 'reservationId' => $row['reservation_id'],
            'operationState' => $row['usage_state'], 'outcome' => $row['outcome'],
            'meteringState' => $row['metering_state'], 'billingState' => $row['billing_state'],
            'waiverReason' => $row['waiver_reason'], 'authorizedCredits' => (string) $authorized,
            'pricedCreditsExact' => $price === null ? null : (string) $price->strippedOfTrailingZeros(),
            'authorizedAt' => $row['authorized_at'], 'completedAt' => $row['completed_at']];
    }
}
