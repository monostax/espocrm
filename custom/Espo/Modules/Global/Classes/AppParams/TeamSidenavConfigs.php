<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\Global\Classes\AppParams;

use Espo\Entities\User;
use Espo\ORM\EntityManager;
use Espo\Tools\App\AppParam;

/**
 * AppParam that provides SidenavConfig records for the current user's teams.
 *
 * Returned as part of the /api/v1/App/user response under `teamSidenavConfigs`.
 * The frontend uses this to build the navbar config selector.
 */
class TeamSidenavConfigs implements AppParam
{
    public function __construct(
        private User $user,
        private EntityManager $entityManager,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get(): array
    {
        $teamIds = $this->user->getLinkMultipleIdList('teams');

        if (empty($teamIds)) {
            return [];
        }

        $configs = $this->entityManager
            ->getRDBRepository('SidenavConfig')
            ->distinct()
            ->join('teams')
            ->where([
                'teams.id' => $teamIds,
                'isDisabled' => false,
            ])
            ->order('order')
            ->order('name')
            ->find();

        $result = [];

        foreach ($configs as $config) {
            $result[] = [
                'id' => $config->getId(),
                'name' => $config->get('name'),
                'iconClass' => $config->get('iconClass'),
                'color' => $config->get('color'),
                'tabList' => $config->get('tabList') ?? [],
                'isDefault' => (bool) $config->get('isDefault'),
            ];
        }

        return $result;
    }
}
