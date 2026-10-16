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
            $query = $pdo->prepare("SELECT * FROM credit_transaction WHERE tenant_id=?
                AND type IN ('searchDebtPayment','searchDebtRecovery','billingDebtPayment','billingDebtRecovery') ORDER BY id");
            $query->execute([$tenantId]);
            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                if (DebtRepaymentOrigins::validEvidence($row)) continue;
                if (count($findings) === $findingLimit) { $truncated = true; break; }
                $findings[] = ['code' => 'transaction.debt_evidence', 'entityType' => 'CreditTransaction', 'id' => $row['id']];
            }
            $query->closeCursor();
            $query = $pdo->prepare("SELECT * FROM credit_transaction WHERE tenant_id=? AND
                (type IN ('searchDebtReversal','searchDebtReopen','searchDebtRestore') OR (type='expiration' AND debt_transaction_id IS NOT NULL)) ORDER BY id");
            $query->execute([$tenantId]);
            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                if (NativeDebtReversals::validPosting($row) &&
                    ($row['type'] !== NativeDebtReversals::TYPE || NativeDebtReversals::validGroup($pdo, $row))) continue;
                if (count($findings) === $findingLimit) { $truncated = true; break; }
                $findings[] = ['code' => 'transaction.native_correction_evidence', 'entityType' => 'CreditTransaction', 'id' => $row['id']];
            }
            $query->closeCursor();
            $query = $pdo->prepare("SELECT DISTINCT grant_id FROM credit_transaction WHERE tenant_id=? AND type='purchaseReversal'
                AND idempotency_key LIKE 'funding-refund:%' ORDER BY grant_id");
            $query->execute([$tenantId]);
            foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $grantId) {
                try { FundingRefunds::latest($pdo, $tenantId, $grantId); continue; }
                catch (\Espo\Core\Exceptions\Conflict) {}
                if (count($findings) === $findingLimit) { $truncated = true; break; }
                $findings[] = ['code' => 'transaction.funding_refund', 'entityType' => 'CreditGrant', 'id' => $grantId];
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
            'phone_verification_operation' => 'PhoneVerificationOperation',
            'phone_verification_request' => 'PhoneVerificationRequest',
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
            AND d.type IN ('nativeSearchOverrun', 'searchDebtRecovery', 'searchDebtReversal', 'searchDebtReopen', 'billingDebt', 'billingDebtRecovery'))";
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
            ((r.state = 'admitted' AND r.settled_credits IS NOT NULL AND (r.operation_type <> 'enrichment' OR NOT EXISTS
                (SELECT 1 FROM enrichment_purchase p WHERE p.usage_id = r.id AND p.tenant_id = r.tenant_id AND p.deleted = FALSE))) OR
             (r.state IN ('settled', 'released') AND (r.settled_credits IS NULL OR r.settled_credits < 0)) OR
             COALESCE(r.settled_credits, 0) <> (SELECT -COALESCE(SUM(c.credits), 0) FROM credit_transaction c
                WHERE c.tenant_id = r.tenant_id AND c.usage_id = r.id AND c.type IN ('debit', 'nativeSearchOverrun')) OR
             COALESCE(r.settled_credits, 0) <> (SELECT COALESCE(SUM(a.consumed_credits), 0) FROM credit_allocation a
                JOIN credit_reservation h ON h.id = a.reservation_id AND h.tenant_id = a.tenant_id
                WHERE a.tenant_id = r.tenant_id AND h.usage_id = r.id) -
                (SELECT COALESCE(SUM(d.credits), 0) FROM credit_transaction d WHERE d.tenant_id = r.tenant_id
                    AND d.usage_id = r.id AND d.type = 'nativeSearchOverrun'))"];
        yield ['usage.purchases', 'CreditUsage', "SELECT r.id FROM credit_usage r WHERE r.tenant_id = ? AND
            EXISTS (SELECT 1 FROM enrichment_purchase p WHERE p.usage_id = r.id) AND
            (r.operation_type <> 'enrichment' OR r.state NOT IN ('admitted', 'settled') OR
             r.settled_credits IS NULL OR r.settled_credits < 0 OR
             r.settled_credits <> (SELECT COALESCE(SUM(p.credits), 0) FROM enrichment_purchase p
                 WHERE p.usage_id = r.id AND p.tenant_id = r.tenant_id))"];
        yield ['purchase.binding', 'EnrichmentPurchase', "SELECT r.id FROM enrichment_purchase r WHERE r.tenant_id = ? AND
            (r.deleted = TRUE OR r.credits <= 0 OR (r.values_ciphertext = '' AND r.erased_at IS NULL) OR
             (r.values_ciphertext <> '' AND r.erased_at IS NOT NULL) OR r.acquired_at > r.purchased_at OR NOT EXISTS
                (SELECT 1 FROM enrichment_quote q JOIN credit_usage u ON u.id = q.usage_id AND u.tenant_id = q.tenant_id
                 WHERE q.id = r.quote_id AND q.tenant_id = r.tenant_id AND u.id = r.usage_id AND
                    q.deleted = FALSE AND u.deleted = FALSE AND q.confirmed_at IS NOT NULL AND q.admitted_at IS NOT NULL
                    AND u.operation_type = 'enrichment' AND u.state IN ('admitted', 'settled')))" ];
        yield ['purchase.cache', 'EnrichmentPurchase', "SELECT r.id FROM enrichment_purchase r WHERE r.tenant_id = ? AND
            ((r.candidate_cache_id IS NULL AND (r.candidate_group IS NOT NULL OR r.purchase_kind IS NOT NULL)) OR
             (r.candidate_cache_id IS NOT NULL AND (r.candidate_group IS NULL OR r.purchase_kind IS NULL OR
              r.purchase_kind NOT IN ('candidate','supplement') OR NOT EXISTS
                (SELECT 1 FROM enrichment_candidate_cache c WHERE c.id=r.candidate_cache_id AND c.tenant_id=r.tenant_id
                 AND c.provider=r.provider AND c.product=r.product AND c.acquired_at=r.acquired_at))))"];
        yield ['usage.verification', 'CreditUsage', "SELECT r.id FROM credit_usage r WHERE r.tenant_id = ? AND
            r.operation_type = 'verification' AND (r.state NOT IN ('admitted', 'settled') OR
             r.source_type <> 'Contact' OR r.source_id IS NULL OR NOT EXISTS
                (SELECT 1 FROM phone_verification_operation o WHERE o.usage_id = r.id AND o.tenant_id = r.tenant_id
                 AND o.deleted = FALSE AND o.contact_id = r.source_id
                 AND ((o.verification_kind = 'phone_identity' AND o.price_credits = 8.0000) OR
                      (o.verification_kind IN ('email_deliverability', 'whatsapp_registration') AND o.price_credits = 2.0000))
                 AND r.operation_key = CONCAT('verification:', o.id) AND r.execution_id = CONCAT('verification:', o.id)
                 AND ((r.state = 'admitted' AND o.state IN ('admitted', 'claimed', 'awaiting') AND r.settled_credits IS NULL) OR
                      (r.state = 'settled' AND o.state = 'completed' AND r.settled_credits = o.price_credits) OR
                      (r.state = 'settled' AND o.state = 'waived' AND r.settled_credits = 0.0000))))"];
        yield ['verification.binding', 'PhoneVerificationOperation', "SELECT r.id FROM phone_verification_operation r WHERE r.tenant_id = ? AND
            (r.deleted = TRUE OR NOT ((r.verification_kind = 'phone_identity' AND r.price_credits = 8.0000) OR
              (r.verification_kind IN ('email_deliverability', 'whatsapp_registration') AND r.price_credits = 2.0000)) OR
             r.state NOT IN ('admitted', 'claimed', 'awaiting', 'completed', 'waived') OR
             (r.state = 'awaiting' AND (r.verification_kind <> 'email_deliverability' OR r.supplier_task_ciphertext IS NULL)) OR
             (r.erased_at IS NOT NULL AND (r.identity_ciphertext IS NOT NULL OR r.evidence_ciphertext IS NOT NULL OR r.supplier_task_ciphertext IS NOT NULL)) OR
             (r.erased_at IS NULL AND r.identity_ciphertext IS NULL) OR
             (r.state IN ('admitted', 'claimed', 'awaiting') AND (r.completed_at IS NOT NULL OR r.outcome IS NOT NULL OR r.evidence_ciphertext IS NOT NULL)) OR
             (r.state = 'completed' AND (r.completed_at IS NULL OR r.claimed_at IS NULL OR r.claimed_at > r.completed_at OR
              NOT ((r.verification_kind = 'phone_identity' AND r.outcome IN ('verified', 'not_verified', 'insufficient_data')) OR
                   (r.verification_kind = 'email_deliverability' AND r.outcome IN ('deliverable', 'risky', 'undeliverable', 'unknown')) OR
                   (r.verification_kind = 'whatsapp_registration' AND r.outcome IN ('registered', 'not_registered'))) OR
              r.fresh_until IS NULL OR r.retention_expires_at IS NULL OR
              r.evidence_hash IS NULL OR (r.erased_at IS NULL AND r.evidence_ciphertext IS NULL))) OR
             (r.state = 'waived' AND (r.completed_at IS NULL OR r.outcome NOT IN ('failed', 'cancelled', 'unavailable') OR
              r.evidence_ciphertext IS NOT NULL OR r.evidence_hash IS NOT NULL OR r.fresh_until IS NOT NULL)) OR
             NOT EXISTS (SELECT 1 FROM credit_usage u JOIN credit_reservation h ON h.usage_id = u.id AND h.tenant_id = u.tenant_id
              WHERE u.id = r.usage_id AND h.id = r.reservation_id AND u.tenant_id = r.tenant_id AND u.deleted = FALSE AND h.deleted = FALSE
               AND u.operation_type = 'verification' AND u.source_type = 'Contact' AND u.source_id = r.contact_id))"];
        yield ['verification.request', 'PhoneVerificationRequest', "SELECT r.id FROM phone_verification_request r WHERE r.tenant_id = ? AND
            (r.deleted = TRUE OR r.resolution NOT IN ('admitted', 'joined', 'reused') OR NOT EXISTS
                (SELECT 1 FROM phone_verification_operation o WHERE o.id = r.operation_id AND o.tenant_id = r.tenant_id
                 AND o.contact_id = r.contact_id AND o.deleted = FALSE))"];
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
            AND r.type NOT IN ('nativeSearchOverrun', 'searchDebtRecovery', 'searchDebtReversal', 'searchDebtReopen', 'billingDebt', 'billingDebtRecovery') AND NOT EXISTS
            (SELECT 1 FROM credit_grant g WHERE g.id = r.grant_id AND g.tenant_id = r.tenant_id AND g.deleted = FALSE)"];
        yield ['transaction.shape', 'CreditTransaction', "SELECT r.id FROM credit_transaction r WHERE r.tenant_id = ? AND
            (r.type NOT IN ('grant', 'debit', 'expiration', 'reversal', 'nativeSearchOverrun', 'searchDebtPayment', 'searchDebtRecovery',
                'purchaseReversal', 'billingDebt', 'billingDebtPayment', 'billingDebtRecovery', 'searchDebtReversal', 'searchDebtReopen', 'searchDebtRestore') OR
             (r.type = 'grant' AND (r.credits <= 0 OR r.usage_id IS NOT NULL OR r.allocation_id IS NOT NULL OR NOT EXISTS
                (SELECT 1 FROM credit_grant g WHERE g.id = r.grant_id AND g.tenant_id = r.tenant_id AND g.grant_transaction_id = r.id))) OR
             (r.type IN ('debit', 'expiration') AND r.credits >= 0) OR
              (r.type = 'debit' AND r.allocation_id IS NULL) OR
              (r.type = 'reversal' AND (r.credits <= 0 OR r.allocation_id IS NULL OR r.actor_id IS NULL OR r.reversal_of_id IS NULL)) OR
               (r.type NOT IN ('reversal', 'expiration', 'purchaseReversal', 'billingDebt', 'searchDebtReversal', 'searchDebtReopen', 'searchDebtRestore') AND r.reversal_of_id IS NOT NULL) OR
              (r.type NOT IN ('searchDebtPayment', 'searchDebtRecovery', 'billingDebtPayment', 'billingDebtRecovery', 'searchDebtReversal', 'searchDebtReopen', 'searchDebtRestore', 'expiration') AND r.debt_transaction_id IS NOT NULL) OR
               (r.allocation_id IS NULL AND r.usage_id IS NOT NULL AND r.type NOT IN ('nativeSearchOverrun', 'searchDebtReversal', 'searchDebtReopen', 'searchDebtRestore')
                 AND NOT (r.type='expiration' AND r.debt_transaction_id IS NOT NULL AND r.reversal_of_id IS NOT NULL)) OR
              (r.type = 'nativeSearchOverrun' AND (r.credits >= 0 OR r.grant_id IS NOT NULL OR r.allocation_id IS NOT NULL OR NOT EXISTS
                 (SELECT 1 FROM credit_usage u WHERE u.id = r.usage_id AND u.tenant_id = r.tenant_id AND u.state = 'settled' AND u.deleted = FALSE))) OR
              (r.type IN ('searchDebtPayment', 'searchDebtRecovery', 'billingDebtPayment', 'billingDebtRecovery') AND (r.usage_id IS NOT NULL OR r.allocation_id IS NOT NULL OR
                 (r.type IN ('searchDebtPayment', 'billingDebtPayment') AND (r.credits >= 0 OR r.grant_id IS NULL)) OR
                 (r.type IN ('searchDebtRecovery', 'billingDebtRecovery') AND (r.credits <= 0 OR r.grant_id IS NOT NULL)) OR NOT EXISTS
                  (SELECT 1 FROM credit_transaction p WHERE p.tenant_id = r.tenant_id AND p.idempotency_key = r.idempotency_key
                      AND p.deleted = FALSE AND p.credits = -r.credits
                      AND (p.debt_transaction_id = r.debt_transaction_id OR (p.debt_transaction_id IS NULL AND r.debt_transaction_id IS NULL)) AND
                     ((r.type = 'searchDebtPayment' AND p.type = 'searchDebtRecovery') OR
                      (r.type = 'searchDebtRecovery' AND p.type = 'searchDebtPayment') OR
                      (r.type = 'billingDebtPayment' AND p.type = 'billingDebtRecovery') OR
                      (r.type = 'billingDebtRecovery' AND p.type = 'billingDebtPayment'))))) OR
             (r.allocation_id IS NOT NULL AND NOT EXISTS
                (SELECT 1 FROM credit_allocation a JOIN credit_reservation h ON h.id = a.reservation_id AND h.tenant_id = a.tenant_id
                 WHERE a.id = r.allocation_id AND a.tenant_id = r.tenant_id AND a.deleted = FALSE
                    AND a.grant_id = r.grant_id AND h.usage_id = r.usage_id AND
                     ((r.type = 'debit' AND a.debit_transaction_id = r.id) OR
                      (r.type = 'reversal' AND a.debit_transaction_id = r.reversal_of_id) OR
                      (r.type = 'expiration' AND (a.expiration_transaction_id = r.id OR r.reversal_of_id IS NOT NULL))))))"];
        yield ['transaction.reversal', 'CreditTransaction', "SELECT r.id FROM credit_transaction r WHERE r.tenant_id = ? AND
            r.type = 'reversal' AND (NOT EXISTS
                (SELECT 1 FROM credit_transaction d JOIN credit_usage u ON u.id = d.usage_id AND u.tenant_id = d.tenant_id
                 WHERE d.id = r.reversal_of_id AND d.tenant_id = r.tenant_id AND d.deleted = FALSE AND d.type = 'debit'
                 AND d.grant_id = r.grant_id AND d.allocation_id = r.allocation_id AND d.usage_id = r.usage_id
                 AND u.state = 'settled' AND u.deleted = FALSE AND -d.credits >=
                    (SELECT COALESCE(SUM(c.credits), 0) FROM credit_transaction c WHERE c.tenant_id = d.tenant_id
                     AND c.type = 'reversal' AND c.reversal_of_id = d.id)) OR
                (EXISTS (SELECT 1 FROM credit_grant g WHERE g.id = r.grant_id AND g.expires_at <= r.posted_at) AND
                 (SELECT COUNT(*) FROM credit_transaction e WHERE e.tenant_id = r.tenant_id AND e.type = 'expiration'
                  AND e.reversal_of_id = r.id AND e.deleted = FALSE AND e.credits = -r.credits
                  AND e.grant_id = r.grant_id AND e.allocation_id = r.allocation_id AND e.usage_id = r.usage_id
                  AND e.posted_at = r.posted_at) <> 1))"];
        yield ['transaction.reversal_expiry', 'CreditTransaction', "SELECT r.id FROM credit_transaction r WHERE r.tenant_id = ?
            AND r.type = 'expiration' AND r.reversal_of_id IS NOT NULL AND NOT EXISTS
                (SELECT 1 FROM credit_transaction c JOIN credit_grant g ON g.id = c.grant_id AND g.tenant_id = c.tenant_id
                  WHERE c.id = r.reversal_of_id AND c.tenant_id = r.tenant_id AND c.type IN ('reversal','searchDebtRestore') AND c.deleted = FALSE
                  AND c.credits = -r.credits AND c.grant_id = r.grant_id
                  AND (c.allocation_id = r.allocation_id OR (c.allocation_id IS NULL AND r.allocation_id IS NULL))
                 AND c.usage_id = r.usage_id AND c.posted_at = r.posted_at AND g.expires_at <= r.posted_at)"];
        foreach ([['nativeSearchOverrun', 'searchDebtRecovery'], ['billingDebt', 'billingDebtRecovery']] as [$debit, $recovery]) {
            $types = $debit === 'nativeSearchOverrun' ? "'$debit','$recovery','searchDebtReversal','searchDebtReopen'" : "'$debit','$recovery'";
            yield ['wallet.debt_overpaid', 'TenantCreditBalance', "SELECT r.id FROM tenant_credit_balance r WHERE r.tenant_id = ? AND
                (SELECT COALESCE(SUM(t.credits), 0) FROM credit_transaction t WHERE t.tenant_id=r.tenant_id AND t.type IN ($types)) > 0"];
        }
        foreach ([['nativeSearchOverrun', 'searchDebtPayment', 'searchDebtRecovery'], ['billingDebt', 'billingDebtPayment', 'billingDebtRecovery']] as [$debit, $payment, $recovery]) {
            yield ['transaction.debt_origin', 'CreditTransaction', "SELECT r.id FROM credit_transaction r WHERE r.tenant_id=?
                AND r.type IN ('$payment', '$recovery') AND r.debt_transaction_id IS NOT NULL AND NOT EXISTS
                (SELECT 1 FROM credit_transaction d WHERE d.id=r.debt_transaction_id AND d.tenant_id=r.tenant_id
                    AND d.type='$debit' AND d.deleted=FALSE AND d.credits < 0 AND d.posted_at <= r.posted_at
                    AND -d.credits >= (SELECT COALESCE(SUM(c.credits),0) FROM credit_transaction c
                        WHERE c.tenant_id=d.tenant_id AND c.type='$recovery' AND c.debt_transaction_id=d.id))"];
        }
        yield ['transaction.purchase_reversal', 'CreditTransaction', "SELECT r.id FROM credit_transaction r WHERE r.tenant_id = ? AND
            r.type = 'purchaseReversal' AND r.idempotency_key NOT LIKE 'funding-refund:%' AND (r.credits > 0 OR r.usage_id IS NOT NULL OR r.allocation_id IS NOT NULL OR NOT EXISTS
                (SELECT 1 FROM credit_grant g JOIN credit_transaction t ON t.id=g.grant_transaction_id AND t.tenant_id=g.tenant_id
                 WHERE g.id=r.grant_id AND g.tenant_id=r.tenant_id AND g.deleted=FALSE AND g.source_type='purchase'
                 AND g.expires_at IS NULL AND t.id=r.reversal_of_id AND t.type='grant' AND t.deleted=FALSE
                 AND t.credits=g.granted_credits AND r.credits +
                    (SELECT COALESCE(SUM(d.credits),0) FROM credit_transaction d WHERE d.tenant_id=r.tenant_id
                     AND d.type='billingDebt' AND d.reversal_of_id=r.id) = -g.granted_credits) OR
                (SELECT COUNT(*) FROM credit_transaction c WHERE c.tenant_id=r.tenant_id AND c.type='purchaseReversal'
                  AND c.grant_id=r.grant_id) <> 1)"];
        yield ['transaction.billing_debt', 'CreditTransaction', "SELECT r.id FROM credit_transaction r WHERE r.tenant_id = ? AND
            r.type = 'billingDebt' AND (r.credits >= 0 OR r.grant_id IS NOT NULL OR r.usage_id IS NOT NULL OR r.allocation_id IS NOT NULL OR NOT EXISTS
                (SELECT 1 FROM credit_transaction p WHERE p.id=r.reversal_of_id AND p.tenant_id=r.tenant_id AND p.deleted=FALSE
                  AND p.type='purchaseReversal' AND p.idempotency_key=r.idempotency_key AND p.posted_at=r.posted_at))"];
        yield ['transaction.native_correction', 'CreditTransaction', "SELECT r.id FROM credit_transaction r WHERE r.tenant_id=? AND
            r.type='searchDebtReversal' AND (r.credits <= 0 OR r.grant_id IS NOT NULL OR r.allocation_id IS NOT NULL OR r.actor_id IS NULL OR
            r.debt_transaction_id IS NULL OR r.debt_transaction_id <> r.reversal_of_id OR NOT EXISTS
            (SELECT 1 FROM credit_transaction d JOIN credit_usage u ON u.id=d.usage_id AND u.tenant_id=d.tenant_id
                WHERE d.id=r.reversal_of_id AND d.tenant_id=r.tenant_id AND d.type='nativeSearchOverrun' AND d.deleted=FALSE
                AND u.state='settled' AND u.deleted=FALSE AND r.usage_id=d.usage_id AND d.posted_at <= r.posted_at
                AND -d.credits >= (SELECT COALESCE(SUM(c.credits),0) FROM credit_transaction c WHERE c.tenant_id=d.tenant_id
                    AND c.type='searchDebtReversal' AND c.reversal_of_id=d.id)) OR
            r.credits < (SELECT COALESCE(SUM(c.credits),0) FROM credit_transaction c WHERE c.tenant_id=r.tenant_id AND c.input_hash=r.input_hash AND c.type='searchDebtRestore'))"];
        yield ['transaction.native_debt_net', 'CreditTransaction', "SELECT r.id FROM credit_transaction r WHERE r.tenant_id=? AND r.type='nativeSearchOverrun'
            AND r.credits + (SELECT COALESCE(SUM(c.credits),0) FROM credit_transaction c WHERE c.tenant_id=r.tenant_id
                AND c.debt_transaction_id=r.id AND c.type IN ('searchDebtRecovery','searchDebtReversal','searchDebtReopen')) > 0"];
        foreach ([['searchDebtReopen', 'searchDebtRecovery', 'searchDebtRestore', false], ['searchDebtRestore', 'searchDebtPayment', 'searchDebtReopen', true]] as [$type, $original, $opposite, $grant]) {
            $grantRule = $grant ? 'r.grant_id=d.grant_id' : 'r.grant_id IS NULL';
            $signRule = $grant ? 'r.credits <= 0' : 'r.credits >= 0';
            yield ['transaction.native_correction_leg', 'CreditTransaction', "SELECT r.id FROM credit_transaction r WHERE r.tenant_id=? AND
                r.type='$type' AND ($signRule OR r.allocation_id IS NOT NULL OR r.actor_id IS NULL OR r.debt_transaction_id IS NULL OR NOT EXISTS
                (SELECT 1 FROM credit_transaction d WHERE d.id=r.reversal_of_id AND d.tenant_id=r.tenant_id AND d.type='$original'
                    AND d.deleted=FALSE AND d.debt_transaction_id=r.debt_transaction_id AND $grantRule AND d.posted_at <= r.posted_at
                    AND ABS(d.credits) >= (SELECT COALESCE(SUM(ABS(c.credits)),0) FROM credit_transaction c
                        WHERE c.tenant_id=d.tenant_id AND c.type='$type' AND c.reversal_of_id=d.id)) OR NOT EXISTS
                (SELECT 1 FROM credit_transaction c WHERE c.tenant_id=r.tenant_id AND c.type='searchDebtReversal' AND c.input_hash=r.input_hash
                    AND c.deleted=FALSE AND c.usage_id=r.usage_id AND c.actor_id=r.actor_id AND c.posted_at=r.posted_at AND c.debt_transaction_id=r.debt_transaction_id) OR NOT EXISTS
                (SELECT 1 FROM credit_transaction c WHERE c.tenant_id=r.tenant_id AND c.type='$opposite' AND c.idempotency_key=r.idempotency_key
                    AND c.deleted=FALSE AND c.credits=-r.credits AND c.input_hash=r.input_hash AND c.debt_transaction_id=r.debt_transaction_id))"];
        }
        yield ['transaction.native_correction_expiry', 'CreditTransaction', "SELECT r.id FROM credit_transaction r WHERE r.tenant_id=? AND
            r.type='searchDebtRestore' AND EXISTS (SELECT 1 FROM credit_grant g WHERE g.id=r.grant_id AND g.tenant_id=r.tenant_id AND g.expires_at <= r.posted_at)
            AND (SELECT COUNT(*) FROM credit_transaction e WHERE e.tenant_id=r.tenant_id AND e.type='expiration' AND e.reversal_of_id=r.id
                AND e.deleted=FALSE AND e.credits=-r.credits AND e.grant_id=r.grant_id AND e.posted_at=r.posted_at AND e.debt_transaction_id=r.debt_transaction_id) <> 1"];
    }
}
