<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Global\Hooks\Funnel;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Ensures only one Funnel per Tenant is marked as default.
 * When a Funnel is set as default, all other funnels in the same tenant are set to not default.
 *
 * Previously scoped by the singular `team` ownership link. That field is gone —
 * a Funnel is now shared across many `teams` — so Tenant is the boundary that
 * makes "the default funnel" unambiguous. Matches the tenant-scoped default
 * used for MetaCapiDataset (see Services/DatasetResolver::getDefaultForTenant).
 *
 * Runs at order 12, after Funnel/SyncTenantFromTeam (order 5) has derived
 * tenantId, which this hook reads, and after Funnel/ValidateTeamsTenant
 * (order 10) — this hook clears isDefault on sibling funnels, so it must not
 * run before a save that is about to be refused.
 *
 * @implements BeforeSave<Entity>
 */
class EnsureSingleDefault implements BeforeSave
{
    public static int $order = 12;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        // Only process if isDefault is being set to true
        if (!$entity->get('isDefault')) {
            return;
        }

        // Only process if this is a new entity or isDefault has changed
        if (!$entity->isNew() && !$entity->isAttributeChanged('isDefault')) {
            return;
        }

        $tenantId = $entity->get('tenantId');

        if (!$tenantId) {
            return;
        }

        // Find all other funnels in the same tenant that are marked as default
        $otherDefaults = $this->entityManager
            ->getRDBRepository('Funnel')
            ->where([
                'tenantId' => $tenantId,
                'isDefault' => true,
                'id!=' => $entity->getId(),
            ])
            ->find();

        // Set them to not default
        foreach ($otherDefaults as $otherFunnel) {
            $otherFunnel->set('isDefault', false);
            $this->entityManager->saveEntity($otherFunnel, ['skipHooks' => true]);
        }
    }
}
