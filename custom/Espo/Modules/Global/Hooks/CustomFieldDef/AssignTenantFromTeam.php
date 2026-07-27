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
use Espo\Modules\Global\Services\TeamTenantAccess;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Derives tenantId from the def's selected teams, falling back to the linked
 * group's tenant when teams do not resolve.
 *
 * Resolution lives in TeamTenantAccess, which matches a tenant's base user team
 * AND its other user teams. Matching only the base team used to leave a null
 * tenant for legitimate secondary-team assignments, which made the field vanish
 * from meta / template / import responses: MetaProvider filters on
 * `tenantId = <tenant>`, and no SQL equality matches NULL.
 *
 * Ambiguity is deliberately non-strict here: unlike the other tenant-scoped
 * config entities, this one has a legitimate disambiguator in the linked group,
 * so teams spanning several tenants fall through to the group's tenant instead
 * of refusing the save.
 *
 * @implements BeforeSave<Entity>
 */
class AssignTenantFromTeam implements BeforeSave
{
    public static int $order = 9;

    public function __construct(
        private TeamTenantAccess $teamTenantAccess,
        private EntityManager $entityManager,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if ($options->get('silent')) {
            return;
        }

        if ($entity->get('tenantId')) {
            return;
        }

        $tenantId = $this->teamTenantAccess->deriveTenantId(
            $entity,
            'custom field',
            strictAmbiguity: false,
        );

        if ($tenantId !== null) {
            $entity->set('tenantId', $tenantId);

            return;
        }

        $groupTenantId = $this->resolveGroupTenantId($entity);

        if ($groupTenantId !== null) {
            $entity->set('tenantId', $groupTenantId);
        }
    }

    private function resolveGroupTenantId(Entity $entity): ?string
    {
        $groupId = $entity->get('groupId');

        if (!is_string($groupId) || $groupId === '') {
            return null;
        }

        $group = $this->entityManager->getEntityById('CustomFieldGroup', $groupId);

        if (!$group) {
            return null;
        }

        $tenantId = $group->get('tenantId');

        return is_string($tenantId) && $tenantId !== '' ? $tenantId : null;
    }
}
