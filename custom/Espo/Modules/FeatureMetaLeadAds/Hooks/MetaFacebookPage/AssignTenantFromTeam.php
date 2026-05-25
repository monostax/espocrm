<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaLeadAds\Hooks\MetaFacebookPage;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureMetaLeadAds\Entities\MetaFacebookPage;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Derives MetaFacebookPage.tenant from the page's selected teams.
 *
 * Mirrors Espo\Modules\FeatureMetaConversionsApi\Hooks\MetaCapiDataset\AssignTenantFromTeam
 * and Espo\Modules\Chatwoot\Hooks\ChatwootAccount\AssignTenantFromTeam so the
 * codebase has one consistent pattern for tenant resolution from teams.
 *
 * @implements BeforeSave<MetaFacebookPage>
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
        if (!$entity instanceof MetaFacebookPage) {
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
                'AssignTenantFromTeam: MetaFacebookPage ' . ($entity->getId() ?? '(new)') .
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
    private function resolveTeamIds(MetaFacebookPage $entity): array
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
