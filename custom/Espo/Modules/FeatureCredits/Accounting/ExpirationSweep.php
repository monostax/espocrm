<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use PDO;
use Throwable;

/** Bounded discovery; all financial writes remain owned by Funding and WalletLock. */
final class ExpirationSweep
{
    public function __construct(
        private EntityManager $entityManager,
        private Clock $clock,
        private Funding $funding,
        private Log $log,
    ) {}

    public function run(): array
    {
        $pdo = $this->entityManager->getPDO();
        $observedAt = $this->clock->now();
        $cursor = '';
        $result = ['observedAt' => $observedAt, 'processed' => 0, 'failed' => 0];
        do {
            // Keyset pages advance even past a failing tenant. Discovery never holds
            // financial locks; expire() rechecks eligibility under the tenant lock.
            $query = $pdo->prepare('SELECT DISTINCT g.tenant_id FROM credit_grant g
                INNER JOIN tenant t ON t.id = g.tenant_id AND t.deleted = FALSE
                WHERE g.deleted = FALSE AND g.expires_at <= ?
                  AND g.remaining_credits > g.reserved_credits AND g.tenant_id > ?
                ORDER BY g.tenant_id LIMIT 100');
            $query->execute([$observedAt, $cursor]);
            $tenants = $query->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tenants as $tenantId) {
                $cursor = $tenantId;
                try {
                    $this->funding->expire($tenantId);
                    $result['processed']++;
                } catch (Throwable $e) {
                    $result['failed']++;
                    $this->log->error('Credit expiration failed for tenant=' . $tenantId . ': ' . $e->getMessage());
                }
            }
        } while (count($tenants) === 100);
        return $result;
    }
}
