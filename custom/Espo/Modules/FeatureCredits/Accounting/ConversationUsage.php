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
 * What one Chatwoot conversation's AI used in this period, so its panel shows a
 * share of the period's credits instead of a price per interaction (decided
 * 2026-11-26). Presentation only, like UsageMeter, whose period it measures in.
 * Used = credits settled plus credits held now; released operations spent
 * nothing and are not interactions.
 */
final class ConversationUsage
{
    public function __construct(private EntityManager $entityManager) {}

    /** @return array{interactions: int, usedCredits: string} four-decimal string */
    public function inspect(string $tenantId, string $conversationId, ?string $since): array
    {
        GrantInput::identity($tenantId);
        GrantInput::identity($conversationId);
        if ($since !== null) GrantInput::timestamp($since);
        $pdo = $this->entityManager->getPDO();
        if ($pdo->inTransaction()) {
            throw new LogicException('Conversation usage must own its read-only transaction.');
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
            $params = [$tenantId, $conversationId, ExecutionRouting::UNIFIED];
            if ($since !== null) $params[] = $since;
            // The credit_usage tenantSource index answers this without the tenant's other usage.
            $query = $pdo->prepare("SELECT COUNT(*) AS interactions,
                COALESCE(SUM(CASE WHEN u.state = 'settled' THEN u.settled_credits ELSE 0 END), 0) AS settled,
                COALESCE(SUM(CASE WHEN u.state = 'admitted' AND r.state = 'held' THEN r.reserved_credits ELSE 0 END), 0) AS held
                FROM credit_usage u LEFT JOIN credit_reservation r ON r.usage_id = u.id
                    AND r.tenant_id = u.tenant_id AND r.execution_id = u.execution_id AND r.deleted = FALSE
                WHERE u.tenant_id = ? AND u.source_type = 'ChatwootConversation' AND u.source_id = ?
                    AND u.operation_type = 'ai' AND u.deleted = FALSE AND u.billing_regime = ?
                    AND u.state IN ('admitted', 'settled')" . ($since !== null ? ' AND u.admitted_at >= ?' : ''));
            $query->execute($params);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $used = BigDecimal::of((string) $row['settled'])->plus(BigDecimal::of((string) $row['held']));
        return [
            'interactions' => (int) $row['interactions'],
            'usedCredits' => (string) $used->toScale(4, RoundingMode::DOWN),
        ];
    }

    /**
     * The conversation's share of UsageMeter::period()'s periodCredits, a six-decimal string from 0 to 1.
     * It rounds up, so any use shows above zero.
     */
    public static function share(string $usedCredits, string $periodCredits): string
    {
        $used = BigDecimal::of($usedCredits);
        $whole = BigDecimal::of($periodCredits);
        if ($used->isNegativeOrZero()) return '0.000000';
        if ($whole->isLessThanOrEqualTo($used)) return '1.000000';
        return (string) $used->dividedBy($whole, 6, RoundingMode::UP);
    }
}
