<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Espo\Core\Exceptions\NotFound;
use Espo\ORM\EntityManager;
use InvalidArgumentException;
use LogicException;
use PDO;
use Throwable;

/** Internal diagnostic for the implemented prepaid projections; never repairs financial evidence. */
final class ConsistencyAudit
{
    public function __construct(private EntityManager $entityManager, private Clock $clock) {}

    public function inspect(string $tenantId, int $findingLimit = 100): array
    {
        GrantInput::identity($tenantId);
        if ($findingLimit < 1 || $findingLimit > 500) {
            throw new InvalidArgumentException('Audit finding limit must be between 1 and 500.');
        }
        $pdo = $this->entityManager->getPDO();
        if ($pdo->inTransaction()) {
            throw new LogicException('Consistency audits must own their read-only transaction.');
        }
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'pgsql'], true) ||
            $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new LogicException('Consistency audits require MySQL/PostgreSQL PDO with exception mode.');
        }
        // One MVCC snapshot, including parent existence. No wallet creation, expiration sweep, or financial locks.
        // SET TRANSACTION affects this transaction only, not the connection's session defaults.
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
            $observedAt = $this->clock->now();
            $findings = [];
            $truncated = false;
            foreach ($this->checks() as [$code, $entityType, $sql]) {
                $remaining = $findingLimit - count($findings);
                $query = $pdo->prepare($sql . ' ORDER BY r.id LIMIT ' . ($remaining + 1));
                $query->execute([$tenantId]);
                while (($id = $query->fetchColumn()) !== false) {
                    if (count($findings) === $findingLimit) {
                        $truncated = true;
                        break;
                    }
                    // Only IDs of the inspected tenant's rows are emitted, never joined foreign-tenant records.
                    $findings[] = ['code' => $code, 'entityType' => $entityType, 'id' => $id];
                }
                $query->closeCursor();
            }
            $pdo->commit();
            return ['tenantId' => $tenantId, 'observedAt' => $observedAt, 'consistent' => $findings === [],
                'findings' => $findings, 'findingsTruncated' => $truncated];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Exact DECIMAL comparisons and sums stay in the database; no floats or four-place aggregate overflow. */
    private function checks(): iterable
    {
        $tables = [
            'tenant_credit_balance' => 'TenantCreditBalance', 'credit_grant' => 'CreditGrant',
            'credit_transaction' => 'CreditTransaction', 'credit_usage' => 'CreditUsage',
            'credit_reservation' => 'CreditReservation', 'credit_allocation' => 'CreditAllocation',
            'credit_request' => 'CreditRequest',
        ];
        foreach ($tables as $table => $entity) {
            yield ['record.deleted', $entity, "SELECT r.id FROM $table r WHERE r.tenant_id = ? AND r.deleted = TRUE"];
        }
        $exists = [];
        foreach (array_keys($tables) as $table) {
            if ($table !== 'tenant_credit_balance') {
                $exists[] = "EXISTS (SELECT 1 FROM $table c WHERE c.tenant_id = r.id)";
            }
        }
        yield ['wallet.missing', 'Tenant', 'SELECT r.id FROM tenant r WHERE r.id = ? AND
            NOT EXISTS (SELECT 1 FROM tenant_credit_balance w WHERE w.tenant_id = r.id) AND (' . implode(' OR ', $exists) . ')'];
        $debtLedger = "(SELECT COALESCE(SUM(d.credits), 0) FROM credit_transaction d WHERE d.tenant_id = r.tenant_id
            AND d.type IN ('nativeSearchOverrun', 'searchDebtRecovery'))";
        yield ['wallet.range', 'TenantCreditBalance', "SELECT r.id FROM tenant_credit_balance r WHERE r.tenant_id = ? AND
            (r.reserved_credits < 0 OR r.balance - $debtLedger < r.reserved_credits OR $debtLedger > 0)"];
        yield ['wallet.grants', 'TenantCreditBalance', "SELECT r.id FROM tenant_credit_balance r WHERE r.tenant_id = ? AND
            r.balance <> (SELECT COALESCE(SUM(g.remaining_credits), 0) FROM credit_grant g WHERE g.tenant_id = r.tenant_id) + $debtLedger"];
        foreach ([
            ['wallet.ledger', 'balance', 'credit_transaction', 'credits', ''],
            ['wallet.grant_holds', 'reserved_credits', 'credit_grant', 'reserved_credits', ''],
            ['wallet.reservation_holds', 'reserved_credits', 'credit_reservation', 'reserved_credits', ''],
            ['wallet.allocation_holds', 'reserved_credits', 'credit_allocation', 'reserved_credits', ' AND c.settled_at IS NULL'],
        ] as [$code, $field, $table, $sum, $where]) {
            yield [$code, 'TenantCreditBalance', "SELECT r.id FROM tenant_credit_balance r WHERE r.tenant_id = ? AND
                r.$field <> (SELECT COALESCE(SUM(c.$sum), 0) FROM $table c WHERE c.tenant_id = r.tenant_id$where)"];
        }
        yield ['grant.range', 'CreditGrant', 'SELECT r.id FROM credit_grant r WHERE r.tenant_id = ? AND
            (r.granted_credits <= 0 OR r.reserved_credits < 0 OR r.remaining_credits < r.reserved_credits OR r.remaining_credits > r.granted_credits)'];
        yield ['grant.ledger', 'CreditGrant', 'SELECT r.id FROM credit_grant r WHERE r.tenant_id = ? AND
            r.remaining_credits <> (SELECT COALESCE(SUM(c.credits), 0) FROM credit_transaction c WHERE c.tenant_id = r.tenant_id AND c.grant_id = r.id)'];
        yield ['grant.holds', 'CreditGrant', 'SELECT r.id FROM credit_grant r WHERE r.tenant_id = ? AND
            r.reserved_credits <> (SELECT COALESCE(SUM(c.reserved_credits), 0) FROM credit_allocation c
                WHERE c.tenant_id = r.tenant_id AND c.grant_id = r.id AND c.settled_at IS NULL)'];
        yield ['grant.source', 'CreditGrant', "SELECT r.id FROM credit_grant r WHERE r.tenant_id = ? AND NOT EXISTS
            (SELECT 1 FROM credit_transaction c WHERE c.id = r.grant_transaction_id AND c.tenant_id = r.tenant_id
                AND c.deleted = FALSE AND c.grant_id = r.id AND c.type = 'grant' AND c.credits = r.granted_credits)"];
        yield ['reservation.holds', 'CreditReservation', 'SELECT r.id FROM credit_reservation r WHERE r.tenant_id = ? AND
            r.reserved_credits <> (SELECT COALESCE(SUM(c.reserved_credits), 0) FROM credit_allocation c
                WHERE c.tenant_id = r.tenant_id AND c.reservation_id = r.id AND c.settled_at IS NULL)'];
        yield ['reservation.state', 'CreditReservation', "SELECT r.id FROM credit_reservation r WHERE r.tenant_id = ? AND
            (r.reserved_credits < 0 OR NOT EXISTS (SELECT 1 FROM credit_usage u WHERE u.id = r.usage_id
                AND u.tenant_id = r.tenant_id AND u.deleted = FALSE AND u.execution_id = r.execution_id AND
                ((r.state = 'held' AND u.state = 'admitted' AND r.settled_at IS NULL AND u.settled_at IS NULL) OR
                 (r.state IN ('released', 'settled') AND u.state = r.state AND r.reserved_credits = 0
                    AND r.settled_at IS NOT NULL AND u.settled_at = r.settled_at))))"];
        yield ['usage.reservation', 'CreditUsage', 'SELECT r.id FROM credit_usage r WHERE r.tenant_id = ? AND
            (SELECT COUNT(*) FROM credit_reservation c WHERE c.usage_id = r.id AND c.tenant_id = r.tenant_id AND c.deleted = FALSE) <> 1'];
        yield ['usage.consumption', 'CreditUsage', "SELECT r.id FROM credit_usage r WHERE r.tenant_id = ? AND
            ((r.state = 'admitted' AND r.settled_credits IS NOT NULL) OR
             (r.state IN ('settled', 'released') AND (r.settled_credits IS NULL OR r.settled_credits < 0)) OR
             COALESCE(r.settled_credits, 0) <> (SELECT -COALESCE(SUM(c.credits), 0) FROM credit_transaction c
                WHERE c.tenant_id = r.tenant_id AND c.usage_id = r.id AND c.type IN ('debit', 'nativeSearchOverrun')) OR
             COALESCE(r.settled_credits, 0) <> (SELECT COALESCE(SUM(a.consumed_credits), 0) FROM credit_allocation a
                JOIN credit_reservation h ON h.id = a.reservation_id AND h.tenant_id = a.tenant_id
                WHERE a.tenant_id = r.tenant_id AND h.usage_id = r.id) -
                (SELECT COALESCE(SUM(d.credits), 0) FROM credit_transaction d WHERE d.tenant_id = r.tenant_id
                    AND d.usage_id = r.id AND d.type = 'nativeSearchOverrun'))"];
        yield ['allocation.partition', 'CreditAllocation', 'SELECT r.id FROM credit_allocation r WHERE r.tenant_id = ? AND
            (r.reserved_credits < 0 OR r.consumed_credits < 0 OR r.released_credits < 0 OR r.expired_credits < 0 OR
             (r.settled_at IS NULL AND (r.consumed_credits <> 0 OR r.released_credits <> 0 OR r.expired_credits <> 0)) OR
             (r.settled_at IS NOT NULL AND r.reserved_credits <> r.consumed_credits + r.released_credits + r.expired_credits))'];
        yield ['allocation.parents', 'CreditAllocation', "SELECT r.id FROM credit_allocation r WHERE r.tenant_id = ? AND
            (NOT EXISTS (SELECT 1 FROM credit_grant g WHERE g.id = r.grant_id AND g.tenant_id = r.tenant_id AND g.deleted = FALSE) OR
             NOT EXISTS (SELECT 1 FROM credit_reservation h WHERE h.id = r.reservation_id AND h.tenant_id = r.tenant_id
                AND h.deleted = FALSE AND (r.settled_at IS NOT NULL OR h.state = 'held')) OR
             (r.request_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM credit_request q WHERE q.id = r.request_id
                AND q.tenant_id = r.tenant_id AND q.reservation_id = r.reservation_id AND q.deleted = FALSE)))"];
        foreach (['debit' => 'consumed_credits', 'expiration' => 'expired_credits'] as $type => $field) {
            yield ['allocation.' . $type, 'CreditAllocation', "SELECT r.id FROM credit_allocation r WHERE r.tenant_id = ? AND
                ((r.$field = 0 AND r.{$type}_transaction_id IS NOT NULL) OR (r.$field <> 0 AND NOT EXISTS
                    (SELECT 1 FROM credit_transaction c JOIN credit_reservation h ON h.id = r.reservation_id AND h.tenant_id = r.tenant_id
                     WHERE c.id = r.{$type}_transaction_id AND c.tenant_id = r.tenant_id AND c.deleted = FALSE
                        AND c.allocation_id = r.id AND c.grant_id = r.grant_id AND c.usage_id = h.usage_id
                        AND c.type = '$type' AND c.credits = -r.$field)))"];
        }
        yield ['request.parents', 'CreditRequest', 'SELECT r.id FROM credit_request r WHERE r.tenant_id = ? AND NOT EXISTS
            (SELECT 1 FROM credit_reservation h JOIN credit_usage u ON u.id = h.usage_id AND u.tenant_id = h.tenant_id
             WHERE h.id = r.reservation_id AND h.tenant_id = r.tenant_id AND h.usage_id = r.usage_id
                AND h.deleted = FALSE AND u.deleted = FALSE AND h.execution_id = u.execution_id)'];
        yield ['request.bound', 'CreditRequest', 'SELECT r.id FROM credit_request r WHERE r.tenant_id = ? AND
            (r.authorized_credits < 0 OR r.authorized_credits <> (SELECT COALESCE(SUM(c.reserved_credits), 0) FROM credit_allocation c
                WHERE c.tenant_id = r.tenant_id AND c.request_id = r.id))'];
        yield ['transaction.grant', 'CreditTransaction', "SELECT r.id FROM credit_transaction r WHERE r.tenant_id = ?
            AND r.type NOT IN ('nativeSearchOverrun', 'searchDebtRecovery') AND NOT EXISTS
            (SELECT 1 FROM credit_grant g WHERE g.id = r.grant_id AND g.tenant_id = r.tenant_id AND g.deleted = FALSE)"];
        yield ['transaction.shape', 'CreditTransaction', "SELECT r.id FROM credit_transaction r WHERE r.tenant_id = ? AND
            (r.type NOT IN ('grant', 'debit', 'expiration', 'nativeSearchOverrun', 'searchDebtPayment', 'searchDebtRecovery') OR
             (r.type = 'grant' AND (r.credits <= 0 OR r.usage_id IS NOT NULL OR r.allocation_id IS NOT NULL OR NOT EXISTS
                (SELECT 1 FROM credit_grant g WHERE g.id = r.grant_id AND g.tenant_id = r.tenant_id AND g.grant_transaction_id = r.id))) OR
             (r.type IN ('debit', 'expiration') AND r.credits >= 0) OR
             (r.type = 'debit' AND r.allocation_id IS NULL) OR
              (r.allocation_id IS NULL AND r.usage_id IS NOT NULL AND r.type <> 'nativeSearchOverrun') OR
              (r.type = 'nativeSearchOverrun' AND (r.credits >= 0 OR r.grant_id IS NOT NULL OR r.allocation_id IS NOT NULL OR NOT EXISTS
                 (SELECT 1 FROM credit_usage u WHERE u.id = r.usage_id AND u.tenant_id = r.tenant_id AND u.state = 'settled' AND u.deleted = FALSE))) OR
              (r.type IN ('searchDebtPayment', 'searchDebtRecovery') AND (r.usage_id IS NOT NULL OR r.allocation_id IS NOT NULL OR
                 (r.type = 'searchDebtPayment' AND (r.credits >= 0 OR r.grant_id IS NULL)) OR
                 (r.type = 'searchDebtRecovery' AND (r.credits <= 0 OR r.grant_id IS NOT NULL)) OR NOT EXISTS
                 (SELECT 1 FROM credit_transaction p WHERE p.tenant_id = r.tenant_id AND p.idempotency_key = r.idempotency_key
                     AND p.deleted = FALSE AND p.credits = -r.credits AND
                     ((r.type = 'searchDebtPayment' AND p.type = 'searchDebtRecovery') OR
                      (r.type = 'searchDebtRecovery' AND p.type = 'searchDebtPayment'))))) OR
             (r.allocation_id IS NOT NULL AND NOT EXISTS
                (SELECT 1 FROM credit_allocation a JOIN credit_reservation h ON h.id = a.reservation_id AND h.tenant_id = a.tenant_id
                 WHERE a.id = r.allocation_id AND a.tenant_id = r.tenant_id AND a.deleted = FALSE
                    AND a.grant_id = r.grant_id AND h.usage_id = r.usage_id AND
                    ((r.type = 'debit' AND a.debit_transaction_id = r.id) OR
                     (r.type = 'expiration' AND a.expiration_transaction_id = r.id)))))"];
    }
}
