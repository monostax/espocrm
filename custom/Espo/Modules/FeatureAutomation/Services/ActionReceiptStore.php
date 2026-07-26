<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Services;

use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Modules\FeatureAutomation\Entities\AutomationActionReceipt;
use Espo\Modules\FeatureJourney\Services\PeriodParser;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * CAS-style idempotency / debounce receipts (action + trigger fingerprints).
 */
class ActionReceiptStore
{
    public function __construct(
        private EntityManager $entityManager,
        private PeriodParser $periodParser,
    ) {}

    /**
     * Try to claim a key for the debounce window.
     * Returns true if caller may proceed; false if skipped (already claimed, not expired).
     */
    public function tryClaim(
        string $automationId,
        string $key,
        string $scope,
        ?string $debouncePeriod = null,
        ?string $tenantId = null,
        ?string $runItemId = null,
        ?string $actionType = null,
        bool $successOnlyPlaceholder = false,
    ): bool {
        $key = trim($key);
        if ($key === '' || $automationId === '') {
            return true;
        }

        $keyHash = hash('sha256', $scope . '|' . $key);
        $now = time();
        $nowSql = date('Y-m-d H:i:s', $now);

        $existing = $this->entityManager
            ->getRDBRepository(AutomationActionReceipt::ENTITY_TYPE)
            ->where([
                'automationId' => $automationId,
                'keyHash' => $keyHash,
            ])
            ->findOne();

        if ($existing) {
            $expiresAt = $existing->get('expiresAt') ? strtotime((string) $existing->get('expiresAt')) : 0;
            if ($expiresAt > $now) {
                return false;
            }
        }

        $expiresSql = null;
        if ($debouncePeriod !== null && trim($debouncePeriod) !== '') {
            try {
                $expiresSql = $this->periodParser->addToNow(trim($debouncePeriod), $now);
            } catch (Throwable) {
                $expiresSql = date('Y-m-d H:i:s', $now + 3600);
            }
        } else {
            // Default: 24h  at-most-once window for bare idempotency keys
            $expiresSql = date('Y-m-d H:i:s', $now + 86400);
        }

        if ($existing) {
            $update = $this->entityManager
                ->getQueryBuilder()
                ->update()
                ->in(AutomationActionReceipt::ENTITY_TYPE)
                ->set([
                    'lastAt' => $nowSql,
                    'expiresAt' => $expiresSql,
                    'runItemId' => $runItemId,
                    'actionType' => $actionType,
                    'tenantId' => $tenantId,
                    'scope' => $scope,
                ])
                ->where([
                    'id' => $existing->getId(),
                    'OR' => [
                        ['expiresAt' => null],
                        ['expiresAt<' => $nowSql],
                    ],
                ])
                ->build();

            $sth = $this->entityManager->getQueryExecutor()->execute($update);

            return $sth->rowCount() > 0;
        }

        $receipt = $this->entityManager->getNewEntity(AutomationActionReceipt::ENTITY_TYPE);
        $receipt->set([
            'name' => substr($key, 0, 100),
            'automationId' => $automationId,
            'keyHash' => $keyHash,
            'scope' => $scope,
            'tenantId' => $tenantId,
            'runItemId' => $runItemId,
            'actionType' => $actionType,
            'lastAt' => $nowSql,
            'expiresAt' => $expiresSql,
        ]);

        try {
            $this->entityManager->saveEntity($receipt, [SaveOption::SKIP_ALL => true]);

            return true;
        } catch (Throwable) {
            // Race: another worker inserted the same unique key
            return false;
        }
    }

    /**
     * Release a claim after a hard failure so the action can retry later.
     */
    public function release(string $automationId, string $key, string $scope): void
    {
        $key = trim($key);
        if ($key === '' || $automationId === '') {
            return;
        }

        $keyHash = hash('sha256', $scope . '|' . $key);
        $existing = $this->entityManager
            ->getRDBRepository(AutomationActionReceipt::ENTITY_TYPE)
            ->where([
                'automationId' => $automationId,
                'keyHash' => $keyHash,
            ])
            ->findOne();

        if (!$existing) {
            return;
        }

        $this->entityManager->removeEntity($existing, [SaveOption::SKIP_ALL => true]);
    }
}
