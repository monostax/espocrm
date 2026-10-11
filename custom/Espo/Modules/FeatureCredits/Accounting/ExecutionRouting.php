<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Espo\Core\Exceptions\Conflict;
use LogicException;
use PDO;

/** Shared locked routing primitives. Callers must already hold the Tenant row lock.
 * A route is inserted in the same transaction as the actual admission, never by a status read.
 */
final class ExecutionRouting
{
    public const LEGACY = 'legacy-engagement-v1';
    public const UNIFIED = 'unified-prepaid-v1';

    private static function transaction(PDO $pdo): void
    {
        if (!$pdo->inTransaction()) throw new LogicException('Execution routing requires the tenant admission transaction.');
    }

    public static function tenantLocked(PDO $pdo, string $tenantId, string $now): array
    {
        self::transaction($pdo);
        $query = $pdo->prepare('SELECT * FROM tenant_credit_cutover WHERE tenant_id = ?');
        $query->execute([$tenantId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if ($row['deleted']) throw new Conflict('Deleted tenant cutover cannot restore legacy billing.');
            GrantInput::timestamp($row['cutover_at']);
        }
        $cutover = $row ? $row['cutover_at'] : null;
        return ['billingRegime' => $cutover !== null && $cutover <= $now ? self::UNIFIED : self::LEGACY, 'cutoverAt' => $cutover];
    }

    public static function findLocked(PDO $pdo, string $tenantId, string $runId, string $workflowRunId): ?array
    {
        self::transaction($pdo);
        $query = $pdo->prepare('SELECT * FROM credit_execution_route WHERE run_id = ?');
        $query->execute([$runId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if ($row['deleted'] || $row['tenant_id'] !== $tenantId || $row['workflow_run_id'] !== $workflowRunId ||
            !in_array($row['billing_regime'], [self::LEGACY, self::UNIFIED], true) ||
            ($row['billing_regime'] === self::LEGACY) !== ($row['usage_id'] === null)) {
            throw new Conflict('Invalid or conflicting persisted execution route.');
        }
        return $row;
    }

    /** Terminal transport commands must own an already-admitted route, including on replay. */
    public static function requireUnifiedLocked(PDO $pdo, string $tenantId, ExecutionIdentity $identity, array $usage): void
    {
        $route = self::findLocked($pdo, $tenantId, $identity->runId, $identity->workflowRunId);
        if (!$route || $route['billing_regime'] !== self::UNIFIED ||
            $route['execution_id'] !== $identity->executionId || $route['usage_id'] !== $usage['id'] ||
            $usage['execution_id'] !== $identity->executionId || $usage['tenant_id'] !== $tenantId ||
            $usage['operation_key'] !== $identity->operationKey() || $usage['operation_type'] !== 'ai' ||
            $usage['billing_regime'] !== self::UNIFIED) {
            throw new Conflict('Terminal command does not own an admitted unified execution.');
        }
        $query = $pdo->prepare('SELECT id FROM ai_usage_reservation WHERE id = ?');
        $query->execute([$identity->runId]);
        if ($query->fetchColumn() !== false) throw new Conflict('Execution also has a legacy admission.');
    }

    public static function recordLocked(PDO $pdo, string $tenantId, ExecutionIdentity $identity,
        string $regime, string $admittedAt, ?string $cutoverAt, ?string $usageId): void
    {
        self::transaction($pdo);
        if (!in_array($regime, [self::LEGACY, self::UNIFIED], true) ||
            ($regime === self::LEGACY) !== ($usageId === null)) {
            throw new LogicException('Invalid admission route.');
        }
        $pdo->prepare('INSERT INTO credit_execution_route
            (id, tenant_id, run_id, workflow_run_id, execution_id, billing_regime, admitted_at, cutover_at, usage_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                RecordId::generate(), $tenantId, $identity->runId, $identity->workflowRunId,
                $identity->executionId, $regime, $admittedAt, $cutoverAt, $usageId,
            ]);
    }

    /** Caller holds the tenant lock. Matching authorization replays stay readable,
     * but no new permission or durable request claim may follow a completion seal. */
    public static function requireDispatchOpenLocked(PDO $pdo, string $usageId): void
    {
        self::transaction($pdo);
        $query = $pdo->prepare('SELECT deleted, completion_requested_at FROM credit_execution_route WHERE usage_id = ?');
        $query->execute([$usageId]);
        $route = $query->fetch(PDO::FETCH_ASSOC);
        if ($route && ($route['deleted'] || $route['completion_requested_at'] !== null)) {
            throw new Conflict('Execution dispatch is sealed.');
        }
    }

    /** Matching committed unified admissions retain their regime across all later configuration changes. */
    public static function unifiedLocked(PDO $pdo, string $tenantId, ExecutionIdentity $identity, string $now): array
    {
        $route = self::findLocked($pdo, $tenantId, $identity->runId, $identity->workflowRunId);
        $query = $pdo->prepare('SELECT id FROM ai_usage_reservation WHERE id = ?');
        $query->execute([$identity->runId]);
        if ($query->fetchColumn() !== false || ($route &&
            ($route['billing_regime'] !== self::UNIFIED || $route['execution_id'] !== $identity->executionId))) {
            throw new Conflict('Execution belongs to another admission or billing regime.');
        }
        if ($route) return ['route' => $route, 'cutoverAt' => $route['cutover_at']];
        $selection = self::tenantLocked($pdo, $tenantId, $now);
        if ($selection['billingRegime'] !== self::UNIFIED) throw new Conflict('Tenant has not reached unified credit cutover.');
        return ['route' => null, 'cutoverAt' => $selection['cutoverAt']];
    }
}
