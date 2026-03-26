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

namespace Espo\Modules\Global\Hooks\Tenant;

use Espo\Core\Utils\Config;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Creates a base default SidenavConfig when a Tenant is created.
 */
class CreateDefaultSidenavConfig
{
    public static int $order = 30;

    public function __construct(
        private EntityManager $entityManager,
        private Config $config
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        if (!$entity->isNew()) {
            return;
        }

        $teamIds = $this->getTenantTeamIds($entity);

        if (empty($teamIds)) {
            return;
        }

        $tabList = $this->config->get('tabList');

        if (!is_array($tabList)) {
            $tabList = [];
        }

        $this->entityManager->createEntity('SidenavConfig', [
            'name' => 'Base / Default',
            'order' => 10,
            'isDefault' => true,
            'isDisabled' => false,
            'tabList' => $tabList,
            'teamsIds' => $teamIds,
        ]);
    }

    /**
     * @return string[]
     */
    private function getTenantTeamIds(Entity $tenant): array
    {
        $teamIds = [];

        $baseTeamId = $tenant->get('baseUserTeamId');
        if ($baseTeamId) {
            $teamIds[] = $baseTeamId;
        }

        $otherTeamIds = $tenant->get('otherUserTeamsIds') ?? [];

        if (!is_array($otherTeamIds)) {
            $otherTeamIds = [];
        }

        if (empty($otherTeamIds) && $tenant->getId()) {
            $otherTeams = $this->entityManager
                ->getRDBRepository('Tenant')
                ->getRelation($tenant, 'otherUserTeams')
                ->find();

            foreach ($otherTeams as $otherTeam) {
                $otherTeamIds[] = $otherTeam->getId();
            }
        }

        foreach ($otherTeamIds as $teamId) {
            $teamIds[] = $teamId;
        }

        return array_values(array_unique($teamIds));
    }
}
