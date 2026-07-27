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

use Espo\Entities\User;
use Espo\ORM\EntityManager;

/**
 * The single answer to "which tenants (workspaces) can this user act for".
 *
 * A user may belong to MORE THAN ONE tenant by design, so this returns a set.
 * That is also why User carries no `tenantId` column: users↔tenants is
 * many-to-many (the `tenantUser` table), and a scalar would be lossy. Note that
 * a column would be decorative anyway — nothing in Classes/Select/** filters on
 * tenantId; the enforced boundary is Team membership via Espo's OnlyTeam filter.
 *
 * MEMBERSHIP IS THE UNION OF TWO MECHANISMS
 * -----------------------------------------
 *   1. explicit  — the `tenantUser` relation (Tenant.users / User.tenants)
 *   2. derived   — Team membership → Tenant.baseUserTeam / Tenant.otherUserTeams
 *
 * Both confer real access, so both count. This previously consulted the
 * explicit links FIRST and fell back to teams only when that set was empty,
 * which let the explicit set shadow the derived one: a user explicitly linked
 * to Tenant A who was also a member of Tenant B's team resolved to [A] alone,
 * even though Espo's team ACL genuinely hands them B's records. The gate's model
 * of the user then disagreed with what the user could actually read.
 *
 * Hooks\Tenant\SyncUserTeams only syncs one direction (relating a user to a
 * Tenant adds them to its base team), so a user added straight to a Team has no
 * `tenantUser` row at all — which is exactly why the derived path must always be
 * consulted, not just used as a fallback.
 */
class UserTenantResolver
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantResolver $tenantResolver,
    ) {}

    /**
     * Every tenant the user can act for. Order is not significant.
     *
     * @return list<string>
     */
    public function resolveTenantIds(User $user): array
    {
        $found = [];

        foreach ($this->explicitTenantIds($user) as $tenantId) {
            $found[$tenantId] = true;
        }

        foreach ($this->tenantResolver->resolveAllFromTeamIds($user->getTeamIdList()) as $tenantId) {
            $found[$tenantId] = true;
        }

        return array_keys($found);
    }

    /**
     * Set form of {@see resolveTenantIds()}, for callers doing membership tests.
     *
     * @return array<string, true>
     */
    public function resolveTenantIdSet(User $user): array
    {
        return array_fill_keys($this->resolveTenantIds($user), true);
    }

    public function canActForTenant(User $user, string $tenantId): bool
    {
        if ($tenantId === '') {
            return false;
        }

        return in_array($tenantId, $this->resolveTenantIds($user), true);
    }

    /**
     * Tenants named by the explicit `tenantUser` relation.
     *
     * Deliberately NOT wrapped in a try/catch: a failing membership query must
     * not silently degrade an authorisation decision to the team-derived subset.
     *
     * @return list<string>
     */
    private function explicitTenantIds(User $user): array
    {
        $ids = [];

        $tenants = $this->entityManager
            ->getRDBRepository(User::ENTITY_TYPE)
            ->getRelation($user, 'tenants')
            ->find();

        foreach ($tenants as $tenant) {
            $id = (string) $tenant->getId();

            if ($id !== '' && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
