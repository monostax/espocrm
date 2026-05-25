<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Hooks\MetaCapiDataset;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDataset;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Derives MetaCapiDataset.tenant from the dataset's selected teams.
 *
 * Every Tenant points at a base Team via Tenant.baseUserTeam. When a
 * MetaCapiDataset is saved with at least one team picked, this hook
 * looks up the Tenant whose baseUserTeam matches one of the selected
 * teams and assigns it. Never overwrites an explicitly-set tenantId.
 *
 * Mirrors Espo\Modules\Chatwoot\Hooks\ChatwootAccount\AssignTenantFromTeam
 * so the codebase has one consistent pattern for tenant resolution.
 *
 * @implements BeforeSave<MetaCapiDataset>
 */
class AssignTenantFromTeam implements BeforeSave
{
    public static int $order = 9;

    public function __construct(
        private \Espo\ORM\EntityManager $entityManager,
        private Log $log,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof MetaCapiDataset) {
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
                'AssignTenantFromTeam: MetaCapiDataset ' . ($entity->getId() ?? '(new)') .
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
    private function resolveTeamIds(MetaCapiDataset $entity): array
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

        // Updates that didn't mutate the teams field — re-read from DB.
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
