<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\Core\Utils\Log;
use Espo\Modules\Global\Tools\Tenant\TenantResolver as GlobalTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * An entity's own `tenantId`, else the single tenant owning its teams.
 *
 * Entity -> team ids is the part that belongs here; the team -> tenant edge is
 * delegated to the canonical Global TenantResolver, which matches a tenant's
 * base user team AND its other user teams. Resolving only the base team left a
 * null tenant for records assigned to a secondary team, and every consumer
 * treats a null tenant as a missing key.
 */
class TenantResolver
{
    public function __construct(
        private EntityManager $entityManager,
        private GlobalTenantResolver $globalTenantResolver,
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

        // resolveAllFromTeamIds rather than resolveUniqueFromTeamIds: the unique
        // variant collapses "no match" and "ambiguous" into null, and the ambiguous
        // case is the one worth logging.
        $tenantIds = $this->globalTenantResolver
            ->resolveAllFromTeamIds(array_values(array_unique($teamIds)));

        if (count($tenantIds) === 1) {
            return $tenantIds[0];
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
