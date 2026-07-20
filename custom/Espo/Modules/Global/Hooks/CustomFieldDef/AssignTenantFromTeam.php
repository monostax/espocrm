<?php

declare(strict_types=1);

/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Global\Hooks\CustomFieldDef;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Derives tenantId from the def's selected teams, falling back to the
 * linked group's tenant when teams do not resolve.
 *
 * @implements BeforeSave<Entity>
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
        if ($options->get('silent')) {
            return;
        }

        if ($entity->get('tenantId')) {
            return;
        }

        $teamIds = $this->resolveTeamIds($entity);

        if ($teamIds !== []) {
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

                return;
            }

            if (count($tenantIds) > 1) {
                $this->log->warning(
                    'AssignTenantFromTeam: CustomFieldDef ' . ($entity->getId() ?? '(new)') .
                    ' resolves to multiple tenants; leaving tenant unset.'
                );
            }
        }

        $groupId = $entity->get('groupId');

        if (!is_string($groupId) || $groupId === '') {
            return;
        }

        $group = $this->entityManager->getEntityById('CustomFieldGroup', $groupId);

        if ($group && $group->get('tenantId')) {
            $entity->set('tenantId', $group->get('tenantId'));
        }
    }

    /**
     * @return list<string>
     */
    private function resolveTeamIds(Entity $entity): array
    {
        if ($entity instanceof CoreEntity) {
            try {
                $ids = $entity->getLinkMultipleIdList('teams');

                if (is_array($ids) && $ids !== []) {
                    return array_values(array_unique($ids));
                }
            } catch (\Throwable) {
                // fall through
            }
        }

        $teamsIds = $entity->get('teamsIds');

        if (is_array($teamsIds) && $teamsIds !== []) {
            return array_values(array_unique($teamsIds));
        }

        return [];
    }
}
