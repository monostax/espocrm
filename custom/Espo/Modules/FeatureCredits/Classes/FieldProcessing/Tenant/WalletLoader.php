<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Classes\FieldProcessing\Tenant;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\FieldProcessing\Loader;
use Espo\Core\FieldProcessing\Loader\Params;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PDO;
use Throwable;

/**
 * Wallet columns of the Tenant list, for staff visibility: credits available
 * (balance minus credits held by calls in flight) and AI credits used in the
 * last 30 days. Both stay empty for a workspace with no credit cutover (not on
 * the wallet). Presentation only: never an admission or dispatch permission.
 *
 * Filled only when the list asks for these columns, one indexed query per row.
 * Not a joined select on the field: Tenant's default select would then join the
 * wallet on every tenant fetch, and lock its row under FOR UPDATE.
 */
class WalletLoader implements Loader
{
    public const AVAILABLE = 'aiCreditsAvailable';
    public const USED_30_DAYS = 'aiCreditsUsed30Days';

    public function __construct(
        private EntityManager $entityManager,
        private Clock $clock,
        private Log $log,
    ) {}

    public function process(Entity $entity, Params $params): void
    {
        if (!$params->hasInSelect(self::AVAILABLE) && !$params->hasInSelect(self::USED_30_DAYS)) {
            return;
        }

        $entity->set([self::AVAILABLE => null, self::USED_30_DAYS => null]);

        try {
            $row = $this->wallet((string) $entity->getId());
        } catch (Throwable $e) {
            $this->log->warning("Credits: wallet columns failed for tenant {$entity->getId()}: {$e->getMessage()}");

            return;
        }

        if ($row === null) {
            return;
        }

        $entity->set([
            self::AVAILABLE => (float) ($row['balance'] ?? 0) - (float) ($row['reserved_credits'] ?? 0),
            self::USED_30_DAYS => (float) $row['used'],
        ]);
    }

    /**
     * @return ?array{balance: ?string, reserved_credits: ?string, used: string} null when not on the wallet
     */
    private function wallet(string $tenantId): ?array
    {
        $since = (new DateTimeImmutable($this->clock->now(), new DateTimeZone('UTC')))
            ->sub(new DateInterval('P30D'))
            ->format('Y-m-d H:i:s');

        $query = $this->entityManager->getPDO()->prepare(
            "SELECT b.balance, b.reserved_credits,
                (SELECT COALESCE(SUM(u.settled_credits), 0) FROM credit_usage u
                 WHERE u.tenant_id = c.tenant_id AND u.state = 'settled' AND u.admitted_at >= ? AND u.deleted = FALSE) AS used
             FROM tenant_credit_cutover c
             LEFT JOIN tenant_credit_balance b ON b.tenant_id = c.tenant_id AND b.deleted = FALSE
             WHERE c.tenant_id = ? AND c.deleted = FALSE"
        );
        $query->execute([$since, $tenantId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
