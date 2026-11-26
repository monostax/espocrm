<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Espo\ORM\EntityManager;
use LogicException;
use PDO;
use Throwable;

/**
 * How much of this period's credits a workspace has used, as a share from 0 to 1,
 * so customers see a usage meter instead of a balance ticking down (decided
 * 2026-11-26). Presentation only: never an admission or dispatch permission.
 *
 * The period starts when the newest monthly (subscription) or trial/opening
 * (migration) grant arrived, else the newest purchase. Used = credits settled
 * since then plus credits held right now; the share is used ÷ (used +
 * available), so it is 0 after a renewal, a purchase lowers it, and it reaches
 * 1 when nothing is left to start an AI call with.
 */
final class UsageMeter
{
    private const ALLOWANCES = ['subscription', 'migration'];
    private const CLOSED_OBLIGATIONS = ['completed', 'cancelled', 'failed'];

    public function __construct(private EntityManager $entityManager, private Clock $clock) {}

    /**
     * @param array{availableCredits: string, reservedCredits: string} $balance BalanceStatus::inspect()
     * @return array{usedRatio: string, periodStartedAt: string, renewsAt: ?string}|null null without grants
     */
    public function inspect(string $tenantId, array $balance): ?array
    {
        $period = $this->period($tenantId, $balance);
        if ($period === null) return null;
        $capacity = BigDecimal::of($period['periodCredits']);
        $ratio = $capacity->isZero() ? BigDecimal::one() :
            BigDecimal::of($period['usedCredits'])->dividedBy($capacity, 4, RoundingMode::DOWN);
        if ($ratio->isGreaterThan(BigDecimal::one())) $ratio = BigDecimal::one();
        return [
            'usedRatio' => (string) $ratio->toScale(4),
            'periodStartedAt' => $period['startedAt'],
            'renewsAt' => $period['renewsAt'],
        ];
    }

    /**
     * The period's figures behind the meter, for callers that measure a part of it
     * (a conversation's share of the period) against the same whole.
     *
     * @param array{availableCredits: string, reservedCredits: string} $balance BalanceStatus::inspect()
     * @return array{startedAt: string, renewsAt: ?string, usedCredits: string, periodCredits: string}|null
     *     four-decimal strings; usedCredits = settled since startedAt + held now, periodCredits = that +
     *     available (floored at 0); null without grants
     */
    public function period(string $tenantId, array $balance): ?array
    {
        GrantInput::identity($tenantId);
        $pdo = $this->entityManager->getPDO();
        if ($pdo->inTransaction()) {
            throw new LogicException('Usage meter must own its read-only transaction.');
        }
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        }
        $pdo->beginTransaction();
        try {
            if ($driver === 'pgsql') {
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            $allowances = "'" . implode("','", self::ALLOWANCES) . "'";
            $query = $pdo->prepare("SELECT source_type, source_key, created_at FROM credit_grant
                WHERE tenant_id = ? AND deleted = FALSE
                ORDER BY CASE WHEN source_type IN ($allowances) THEN 0 ELSE 1 END, created_at DESC, id DESC LIMIT 1");
            $query->execute([$tenantId]);
            $grant = $query->fetch(PDO::FETCH_ASSOC);
            if ($grant === false) {
                $pdo->commit();
                return null;
            }
            $query = $pdo->prepare('SELECT COALESCE(SUM(settled_credits), 0) FROM credit_usage
                WHERE tenant_id = ? AND state = ? AND admitted_at >= ? AND deleted = FALSE AND billing_regime = ?');
            $query->execute([$tenantId, 'settled', $grant['created_at'], ExecutionRouting::UNIFIED]);
            $settled = BigDecimal::of((string) $query->fetchColumn());
            $renewsAt = $grant['source_type'] === 'subscription' ? $this->renewal($pdo, $tenantId, $grant['source_key']) : null;
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $zero = BigDecimal::zero();
        $used = $settled->plus(BigDecimal::of($balance['reservedCredits']));
        if ($used->isNegative()) $used = $zero;
        $left = BigDecimal::of($balance['availableCredits']);
        return [
            'startedAt' => $grant['created_at'],
            'renewsAt' => $renewsAt,
            'usedCredits' => (string) $used->toScale(4, RoundingMode::DOWN),
            'periodCredits' => (string) $used->plus($left->isNegative() ? $zero : $left)->toScale(4, RoundingMode::DOWN),
        ];
    }

    /** The end of a monthly grant's billing period while its subscription is still open. */
    private function renewal(PDO $pdo, string $tenantId, string $sourceKey): ?string
    {
        if (!str_starts_with($sourceKey, 'billing:')) return null;
        $query = $pdo->prepare('SELECT p.ends_at, o.state FROM credit_billing_period p
            JOIN credit_billing_obligation o ON o.id = p.obligation_id AND o.tenant_id = p.tenant_id AND o.deleted = FALSE
            WHERE p.id = ? AND p.tenant_id = ? AND p.deleted = FALSE');
        $query->execute([substr($sourceKey, strlen('billing:')), $tenantId]);
        $period = $query->fetch(PDO::FETCH_ASSOC);
        if ($period === false || in_array($period['state'], self::CLOSED_OBLIGATIONS, true) ||
            $period['ends_at'] <= $this->clock->now()) {
            return null;
        }
        return $period['ends_at'];
    }
}
