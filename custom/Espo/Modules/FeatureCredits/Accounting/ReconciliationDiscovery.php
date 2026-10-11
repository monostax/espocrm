<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\NotFound;
use Espo\ORM\EntityManager;
use InvalidArgumentException;
use JsonException;
use LogicException;
use PDO;

/** Read-only candidates for a trusted recovery adapter; discovery is not a lookup or a work claim. */
final class ReconciliationDiscovery
{
    public function __construct(private EntityManager $entityManager, private Clock $clock) {}

    public function scan(string $tenantId, int $limit = 100, ?string $afterRequestId = null): array
    {
        GrantInput::identity($tenantId);
        if ($afterRequestId !== null) {
            GrantInput::identity($afterRequestId);
        }
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Recovery scan limit must be between 1 and 500.');
        }
        $pdo = $this->entityManager->getPDO();
        if ($pdo->inTransaction()) {
            throw new LogicException('Recovery discovery must run outside financial transactions.');
        }
        $tenant = $pdo->prepare('SELECT id FROM tenant WHERE id = ? AND deleted = FALSE');
        $tenant->execute([$tenantId]);
        if ($tenant->fetchColumn() === false) {
            throw new NotFound('Unknown credit tenant.');
        }
        $now = $this->clock->now();
        // Bound inspected rows, not just due results. JSON timing stays portable across both dialects.
        $query = $pdo->prepare("SELECT r.id, r.usage_id, r.reservation_id, r.provider, r.model,
                r.provider_request_id, r.outcome, r.completed_at, r.reconciliation_record, u.execution_id
            FROM credit_request r
            JOIN tenant t ON t.id = r.tenant_id AND t.deleted = FALSE
            JOIN credit_usage u ON u.id = r.usage_id AND u.tenant_id = r.tenant_id
            JOIN credit_reservation h ON h.id = r.reservation_id AND h.usage_id = u.id
                AND h.tenant_id = r.tenant_id AND h.execution_id = u.execution_id
            WHERE r.tenant_id = ? AND r.deleted = FALSE AND u.deleted = FALSE AND h.deleted = FALSE
                AND u.operation_type = 'ai' AND u.billing_regime = 'unified-prepaid-v1'
                AND u.state = 'admitted' AND h.state = 'held'
                AND r.outcome IN ('cancelled', 'superseded')
                AND r.metering_state = 'unknown' AND r.billing_state = 'pending'
                AND r.completed_at IS NOT NULL AND r.completed_at <= ? AND r.outcome_record IS NOT NULL
                AND r.priced_credits_exact IS NULL AND r.waiver_reason IS NULL" .
            ($afterRequestId === null ? '' : ' AND r.id > ?') . ' ORDER BY r.id LIMIT ' . ($limit + 1));
        $query->execute($afterRequestId === null ? [$tenantId, $now] : [$tenantId, $now, $afterRequestId]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $items = [];
        foreach ($rows as $row) {
            $record = $this->schedule($row['reconciliation_record']);
            $dueAt = $record['nextAttemptAt'] ?? $row['completed_at'];
            if ($dueAt > $now) {
                continue;
            }
            $items[] = [
                'tenantId' => $tenantId, 'usageId' => $row['usage_id'], 'reservationId' => $row['reservation_id'],
                'executionId' => $row['execution_id'], 'requestId' => $row['id'],
                'provider' => $row['provider'], 'model' => $row['model'],
                'providerRequestId' => $row['provider_request_id'], 'outcome' => $row['outcome'],
                'completedAt' => $row['completed_at'], 'dueAt' => $dueAt,
                'policy' => $record['policy'] ?? null, 'deadlineAt' => $record['deadlineAt'] ?? null,
                'attemptCount' => count($record['attempts'] ?? []),
            ];
        }
        return ['observedAt' => $now, 'items' => $items, 'scannedCount' => count($rows),
            'nextAfterRequestId' => $hasMore ? $rows[array_key_last($rows)]['id'] : null];
    }

    private function schedule(?string $json): ?array
    {
        if ($json === null) {
            return null;
        }
        try {
            $record = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($record) || !is_array($record['policy'] ?? null) ||
                !is_array($record['attempts'] ?? null) || !array_is_list($record['attempts']) ||
                count($record['attempts']) === 0 || isset($record['waivedAt'])) {
                throw new InvalidArgumentException();
            }
            $policy = $record['policy'];
            foreach (['version', 'operator'] as $field) {
                if (!is_string($policy[$field] ?? null)) {
                    throw new InvalidArgumentException();
                }
            }
            foreach (['deadlineSeconds', 'retrySeconds', 'minimumAttempts'] as $field) {
                if (!is_int($policy[$field] ?? null)) {
                    throw new InvalidArgumentException();
                }
            }
            new ReconciliationPolicy($policy['version'], $policy['operator'], $policy['deadlineSeconds'],
                $policy['retrySeconds'], $policy['minimumAttempts']);
            foreach (['nextAttemptAt', 'deadlineAt'] as $field) {
                $value = $record[$field] ?? null;
                if (!is_string($value)) {
                    throw new InvalidArgumentException();
                }
                $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
                if (!$date || $date->format('Y-m-d H:i:s') !== $value) {
                    throw new InvalidArgumentException();
                }
            }
            return $record;
        } catch (InvalidArgumentException | JsonException $e) {
            throw new Conflict('Invalid persisted reconciliation schedule.', previous: $e);
        }
    }
}
