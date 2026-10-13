<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAiUsage\Services;

use Brick\Math\BigDecimal;
use Espo\Core\Exceptions\NotFound;
use Espo\ORM\EntityManager;
use LogicException;
use PDO;
use Throwable;

/** Read-only financial projection. Call only after the tenant financial-access gate. */
class Credits
{
    private const UNIFIED = 'unified-prepaid-v1';
    private const LEGACY = 'legacy-engagement-v1';

    public function __construct(private EntityManager $entityManager) {}

    public function report(string $tenantId, Period $period, array $runs): array
    {
        $pdo = $this->entityManager->getPDO();
        if ($pdo->inTransaction()) throw new LogicException('Credit reporting must own its snapshot.');
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'pgsql'], true)) throw new LogicException('Unsupported credit reporting database.');
        if ($driver === 'mysql') $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $pdo->beginTransaction();
        try {
            if ($driver === 'pgsql') $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $q = $pdo->prepare('SELECT id FROM tenant WHERE id=? AND deleted=FALSE');
            $q->execute([$tenantId]);
            if ($q->fetchColumn() === false) throw new NotFound();
            $q = $pdo->prepare('SELECT cutover_at, deleted FROM tenant_credit_cutover WHERE tenant_id=?');
            $q->execute([$tenantId]);
            $cutover = $q->fetch(PDO::FETCH_ASSOC);
            if ($cutover && $cutover['deleted']) throw new LogicException('Deleted billing cutover.');

            $routes = [];
            foreach (array_chunk(array_column($runs, 'id'), 500) as $ids) {
                $marks = implode(',', array_fill(0, count($ids), '?'));
                $q = $pdo->prepare("SELECT e.run_id, e.tenant_id, e.deleted, e.billing_regime, e.usage_id,
                    u.id AS parent_id, u.deleted AS parent_deleted, u.state, u.settled_credits,
                    u.billing_regime AS parent_regime, u.operation_type
                    FROM credit_execution_route e LEFT JOIN credit_usage u ON u.id=e.usage_id
                        AND u.tenant_id=e.tenant_id AND u.execution_id=e.execution_id
                    WHERE e.run_id IN ($marks)");
                $q->execute($ids);
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if ($row['tenant_id'] !== $tenantId || $row['deleted'] || !in_array($row['billing_regime'], [self::UNIFIED, self::LEGACY], true)) {
                        throw new LogicException('Invalid saved execution regime.');
                    }
                    if ($row['billing_regime'] === self::UNIFIED &&
                        (!$row['parent_id'] || $row['parent_deleted'] || $row['parent_regime'] !== self::UNIFIED ||
                        $row['operation_type'] !== 'ai' || !in_array($row['state'], ['admitted', 'settled', 'released'], true))) {
                        throw new LogicException('Invalid unified reporting parent.');
                    }
                    $routes[$row['run_id']] = $row;
                }
            }
            $totals = [];
            $usageIds = array_values(array_filter(array_column($routes, 'usage_id')));
            foreach (array_chunk($usageIds, 500) as $ids) {
                $marks = implode(',', array_fill(0, count($ids), '?'));
                $q = $pdo->prepare("SELECT usage_id, type, credits, deleted FROM credit_transaction
                    WHERE tenant_id=? AND usage_id IN ($marks)
                    AND type IN ('debit','nativeSearchOverrun','reversal','searchDebtReversal')");
                $q->execute([$tenantId, ...$ids]);
                while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                    $totals[$row['usage_id']] ??= self::emptyTotals();
                    self::add($totals[$row['usage_id']], $row);
                }
            }
            foreach ($runs as &$run) {
                $route = $routes[$run['id']] ?? null;
                // Missing post-cutover evidence is not permission to reconstruct a legacy charge.
                $run['billingRegime'] = $route['billing_regime'] ??
                    ($cutover && $run['runAt'] >= $cutover['cutover_at'] ? 'unattributed' : self::LEGACY);
                $run['creditBilling'] = null;
                if ($run['billingRegime'] !== self::UNIFIED) continue;
                $posted = $totals[$route['usage_id']] ?? self::emptyTotals();
                $pending = $route['state'] === 'admitted';
                if (($pending && ($route['settled_credits'] !== null || $posted['chargedCredits'] !== '0.0000')) ||
                    (!$pending && ($route['settled_credits'] === null ||
                        BigDecimal::of($route['settled_credits'])->compareTo($posted['chargedCredits']) !== 0)) ||
                    ($route['state'] === 'released' && $posted['chargedCredits'] !== '0.0000')) {
                    throw new LogicException('Posted charges disagree with operation settlement.');
                }
                $run['creditBilling'] = $posted + ['state' => $pending ? 'pending' : $route['state'],
                    'settledCredits' => $pending ? null : $posted['chargedCredits']];
            }
            unset($run);

            // Posting-time totals include financial operations with missing/deleted/delayed telemetry.
            $summary = self::emptyTotals();
            $q = $pdo->prepare("SELECT t.type,t.credits,t.deleted,u.id AS parent_id,u.deleted AS parent_deleted,u.billing_regime
                FROM credit_transaction t LEFT JOIN credit_usage u ON u.id=t.usage_id AND u.tenant_id=t.tenant_id
                WHERE t.tenant_id=? AND (u.operation_type='ai' OR u.id IS NULL) AND t.posted_at>=? AND t.posted_at<?
                AND t.type IN ('debit','nativeSearchOverrun','reversal','searchDebtReversal')");
            $q->execute([$tenantId, $period->utcStart(), $period->utcCutoff()]);
            while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                if (!$row['parent_id'] || $row['parent_deleted'] || $row['billing_regime'] !== self::UNIFIED) throw new LogicException('Invalid posted AI parent.');
                self::add($summary, $row);
            }
            $pdo->commit();
            return ['runs' => $runs, 'summary' => $summary + ['basis' => 'postedAt', 'billingRegime' => self::UNIFIED]];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private static function emptyTotals(): array
    {
        return ['chargedCredits' => '0.0000', 'reversedCredits' => '0.0000', 'netCredits' => '0.0000'];
    }

    private static function add(array &$totals, array $row): void
    {
        $value = BigDecimal::of($row['credits']);
        $charge = in_array($row['type'], ['debit', 'nativeSearchOverrun'], true);
        if ($row['deleted'] || ($charge ? $value->isPositive() : $value->isNegative())) throw new LogicException('Invalid credit posting.');
        $key = $charge ? 'chargedCredits' : 'reversedCredits';
        $totals[$key] = (string) BigDecimal::of($totals[$key])->plus($value->abs())->toScale(4);
        $totals['netCredits'] = (string) BigDecimal::of($totals['chargedCredits'])->minus($totals['reversedCredits'])->toScale(4);
    }
}
