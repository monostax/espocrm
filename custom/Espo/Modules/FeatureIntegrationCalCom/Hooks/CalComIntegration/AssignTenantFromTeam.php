<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationCalCom\Hooks\CalComIntegration;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureIntegrationCalCom\Entities\CalComIntegration;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Derives CalComIntegration.tenant from the integration's selected teams.
 *
 * Every Tenant points at a base Team via Tenant.baseUserTeam. When an
 * integration is saved with at least one team picked, this hook looks up
 * the Tenant whose baseUserTeam matches one of the selected teams and
 * assigns it. Never overwrites an explicitly-set tenantId.
 *
 * Mirrors:
 *   Espo\Modules\FeatureMetaConversionsApi\Hooks\MetaCapiDataset\AssignTenantFromTeam
 *   Espo\Modules\FeatureMetaLeadAds\Hooks\MetaFacebookPage\AssignTenantFromTeam
 *
 * @implements BeforeSave<CalComIntegration>
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
        if (!$entity instanceof CalComIntegration) {
            return;
        }

        if ($options->get('silent')) {
            return;
        }

        // Respect explicit tenant assignments.
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
                'AssignTenantFromTeam: CalComIntegration ' . ($entity->getId() ?? '(new)') .
                ' resolves to multiple tenants via teams ' . implode(',', $teamIds) .
                '; leaving tenant unset.'
            );

            return;
        }

        $entity->set('tenantId', array_key_first($tenantIds));
    }

    /**
     * Read the in-memory team id list, falling back to whatever is already
     * persisted for updates that didn't touch the teams field.
     *
     * @return list<string>
     */
    private function resolveTeamIds(CalComIntegration $entity): array
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
