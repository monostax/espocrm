<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Hooks\Journey;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureJourney\Entities\Journey;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/** @implements BeforeSave<Journey> */
class AssignTenantFromTeam implements BeforeSave
{
    public static int $order = 9;

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof Journey) {
            return;
        }

        if ($options->get('silent')) {
            return;
        }

        if ($entity->get('tenantId')) {
            return;
        }

        $teamIds = $this->resolveTeamIds($entity);

        if ($teamIds === []) {
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
                'AssignTenantFromTeam: Journey ' . ($entity->getId() ?? '(new)') .
                ' resolves to multiple tenants; leaving unset.'
            );

            return;
        }

        $entity->set('tenantId', array_key_first($tenantIds));
    }

    /** @return list<string> */
    private function resolveTeamIds(Journey $entity): array
    {
        try {
            $ids = $entity->getLinkMultipleIdList('teams') ?: [];
            if ($ids !== []) {
                return array_values(array_unique($ids));
            }
        } catch (\Throwable) {
        }

        $teamsIds = $entity->get('teamsIds');
        if (is_array($teamsIds) && $teamsIds !== []) {
            return array_values(array_unique($teamsIds));
        }

        return [];
    }
}
