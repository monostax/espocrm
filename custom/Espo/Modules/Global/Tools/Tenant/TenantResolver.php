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
     * WARNING: this silently picks the first match and cannot detect teams that
     * span multiple tenants. Do NOT use it to stamp tenancy onto a record —
     * use {@see resolveUniqueFromTeamIds()} for that, which refuses to guess.
     * This variant is retained only for read paths that already constrain the
     * team list to ones the caller belongs to, where an arbitrary-but-valid
     * choice is acceptable.
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

    /**
     * The single tenant the given teams belong to, or null when they resolve to
     * none or to more than one.
     *
     * Ambiguity is a refusal rather than an arbitrary pick: stamping a guessed
     * tenantId onto a record silently misfiles data across the tenancy
     * boundary, and the wrong guess is indistinguishable from the right one
     * after the fact.
     *
     * @param list<string> $teamIds
     */
    public function resolveUniqueFromTeamIds(array $teamIds): ?string
    {
        $tenantIds = $this->resolveAllFromTeamIds($teamIds);

        return count($tenantIds) === 1 ? $tenantIds[0] : null;
    }

    /**
     * Every distinct tenant reachable from the given teams, via both
     * Tenant.baseUserTeam and Tenant.otherUserTeams. Two queries regardless of
     * how many teams are passed.
     *
     * @param list<string> $teamIds
     * @return list<string>
     */
    public function resolveAllFromTeamIds(array $teamIds): array
    {
        $clean = [];

        foreach ($teamIds as $teamId) {
            if (!is_string($teamId)) {
                continue;
            }

            $trimmed = trim($teamId);

            if ($trimmed !== '' && !in_array($trimmed, $clean, true)) {
                $clean[] = $trimmed;
            }
        }

        if ($clean === []) {
            return [];
        }

        $ids = [];

        $byBase = $this->entityManager
            ->getRDBRepository('Tenant')
            ->select(['id'])
            ->where(['baseUserTeamId' => $clean])
            ->find();

        foreach ($byBase as $tenant) {
            $id = (string) $tenant->getId();

            if ($id !== '' && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        $byOther = $this->entityManager
            ->getRDBRepository('Tenant')
            ->select(['id'])
            ->join('otherUserTeams', 'otherUserTeams')
            ->where(['otherUserTeamsMiddle.teamId' => $clean])
            ->find();

        foreach ($byOther as $tenant) {
            $id = (string) $tenant->getId();

            if ($id !== '' && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
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
