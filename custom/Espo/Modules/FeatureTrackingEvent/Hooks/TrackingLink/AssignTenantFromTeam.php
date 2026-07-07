<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Hooks\TrackingLink;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingLink;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Derives TrackingLink.tenant from the link's teams.
 *
 * Twin of Hooks\TrackingSource\AssignTenantFromTeam (one consistent pattern
 * for tenant resolution from teams across the codebase). Runs after
 * CascadeTeamsFromSource (order 8) so a link without explicit teams still
 * resolves via its source's teams.
 *
 * @implements BeforeSave<TrackingLink>
 */
class AssignTenantFromTeam implements BeforeSave
{
    public static int $order = 9;

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof TrackingLink) {
            return;
        }

        if ($options->get('silent')) {
            return;
        }

        if ($entity->get('tenantId')) {
            return;
        }

        $teamIds = $this->resolveTeamIds($entity);

        if (empty($teamIds)) {
            return;
        }

        $tenants = $this->entityManager
            ->getRDBRepository('Tenant')
            ->where(['baseUserTeamId' => $teamIds])
            ->find();

        $tenantIds = [];

        foreach ($tenants as $tenant) {
            $tenantIds[$tenant->getId()] = true;
        }

        if (count($tenantIds) === 0) {
            return;
        }

        if (count($tenantIds) > 1) {
            $this->log->warning(
                'AssignTenantFromTeam: TrackingLink ' . ($entity->getId() ?? '(new)') .
                ' resolves to multiple tenants via teams ' . implode(',', $teamIds) .
                '; leaving tenant unset.'
            );

            return;
        }

        $entity->set('tenantId', array_key_first($tenantIds));
    }

    /**
     * @return list<string>
     */
    private function resolveTeamIds(TrackingLink $entity): array
    {
        $ids = [];

        try {
            $ids = $entity->getLinkMultipleIdList('teams') ?: [];
        } catch (\Throwable) {
            $ids = [];
        }

        if (!empty($ids)) {
            return array_values(array_unique($ids));
        }

        $teamsIds = $entity->get('teamsIds');

        if (is_array($teamsIds) && !empty($teamsIds)) {
            return array_values(array_unique($teamsIds));
        }

        if (!$entity->isNew() && $entity->getId()) {
            $existing = $this->entityManager->getEntityById($entity->getEntityType(), $entity->getId());

            if ($existing && method_exists($existing, 'getLinkMultipleIdList')) {
                try {
                    return array_values(array_unique($existing->getLinkMultipleIdList('teams') ?: []));
                } catch (\Throwable) {
                    return [];
                }
            }
        }

        return [];
    }
}
