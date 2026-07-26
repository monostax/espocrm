<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Teams → Tenant.baseUserTeamId unambiguous-single-match.
 * Port of InternalEventRecorder::resolveTenantIdForEntity.
 */
class TenantResolver
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function resolveTenantIdForEntity(Entity $entity): ?string
    {
        if ($entity->hasAttribute('tenantId')) {
            $direct = $entity->get('tenantId');
            if ($direct) {
                return (string) $direct;
            }
        }

        $teamIds = [];

        try {
            $teamIds = $entity->getLinkMultipleIdList('teams') ?: [];
        } catch (Throwable) {
            $teamIds = [];
        }

        if ($teamIds === [] && $entity->hasId()) {
            try {
                $teams = $this->entityManager
                    ->getRDBRepository($entity->getEntityType())
                    ->getRelation($entity, 'teams')
                    ->find();

                foreach ($teams as $team) {
                    $teamIds[] = $team->getId();
                }
            } catch (Throwable) {
            }
        }

        if ($teamIds === []) {
            return null;
        }

        $tenants = $this->entityManager
            ->getRDBRepository('Tenant')
            ->where(['baseUserTeamId' => array_values(array_unique($teamIds))])
            ->find();

        $tenantIds = [];

        foreach ($tenants as $tenant) {
            $tenantIds[$tenant->getId()] = true;
        }

        if (count($tenantIds) === 1) {
            return array_key_first($tenantIds);
        }

        if (count($tenantIds) > 1) {
            $this->log->warning(sprintf(
                'TenantResolver: %s %s resolves to multiple tenants via teams %s; skipping.',
                $entity->getEntityType(),
                $entity->hasId() ? $entity->getId() : '(new)',
                implode(',', $teamIds),
            ));
        }

        return null;
    }
}
