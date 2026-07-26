<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Hooks\Automation;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureAutomation\Entities\Automation as AutomationEntity;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/** @implements BeforeSave<AutomationEntity> */
class AssignTenantFromTeam implements BeforeSave
{
    public static int $order = 9;

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof AutomationEntity) {
            return;
        }

        if ($options->get('silent') || $entity->get('tenantId')) {
            return;
        }

        $teamIds = [];
        try {
            $teamIds = $entity->getLinkMultipleIdList('teams') ?: [];
        } catch (\Throwable) {
            $teamIds = is_array($entity->get('teamsIds')) ? $entity->get('teamsIds') : [];
        }

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

        if (count($tenantIds) === 1) {
            $entity->set('tenantId', array_key_first($tenantIds));
        } elseif (count($tenantIds) > 1) {
            $this->log->warning(
                'FeatureAutomation AssignTenantFromTeam: multiple tenants for Automation ' .
                ($entity->getId() ?? '(new)')
            );
        }
    }
}
