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

namespace Espo\Modules\Global\Tools\Tenant;

use Espo\ORM\EntityManager;

/**
 * Resolves Tenant id from Team membership.
 *
 * Lookup order per team (mirrors Contact SyncTenantFromTeam):
 *   1. Tenant.baseUserTeamId = team.id
 *   2. Tenant.otherUserTeams middle (tenantOtherUserTeam)
 */
class TenantResolver
{
    public function __construct(
        private EntityManager $entityManager,
    ) {}

    /**
     * First matching tenant across the given teams (order preserved).
     *
     * @param list<string> $teamIds
     */
    public function resolveFromTeamIds(array $teamIds): ?string
    {
        foreach ($teamIds as $teamId) {
            if (!is_string($teamId) || $teamId === '') {
                continue;
            }

            $tenantId = $this->resolveFromTeamId($teamId);

            if ($tenantId) {
                return $tenantId;
            }
        }

        return null;
    }

    public function resolveFromTeamId(string $teamId): ?string
    {
        $teamId = trim($teamId);

        if ($teamId === '') {
            return null;
        }

        $tenantByBase = $this->entityManager
            ->getRDBRepository('Tenant')
            ->select(['id'])
            ->where(['baseUserTeamId' => $teamId])
            ->findOne();

        if ($tenantByBase) {
            return $tenantByBase->getId();
        }

        $tenantByOther = $this->entityManager
            ->getRDBRepository('Tenant')
            ->select(['id'])
            ->join('otherUserTeams', 'otherUserTeams')
            ->where(['otherUserTeamsMiddle.teamId' => $teamId])
            ->findOne();

        return $tenantByOther?->getId();
    }
}
