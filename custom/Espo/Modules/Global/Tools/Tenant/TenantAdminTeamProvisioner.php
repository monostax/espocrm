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

use Espo\Core\Utils\Log;
use Espo\Modules\Global\Classes\Utils\TenantRoleAuth;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Guarantees every Tenant has an admin Team ("{tenantName} / Admin") carrying
 * the instance-wide `tenant-admin` Role.
 *
 * WHY THE ROLE IS SHARED, NOT PER-TENANT
 * --------------------------------------
 * `tenant` and `tenant-admin` are singleton Roles with static ids (or their
 * md5 in UUID id mode), seeded by Rebuild\SeedRole. Every tenant-admin gate in
 * the codebase resolves the role by that fixed id —
 * TenantRoleAuth::tenantAdminRoleIds(), CatalogAuth::TENANT_ADMIN_STATIC_ID,
 * AppParams\IsTenantAdmin. Minting a *per-tenant* Role row would therefore
 * produce an id none of those gates recognise, silently making every holder a
 * non-admin, and would fork the ACL blob N ways so future permission changes
 * drift per tenant. Tenancy is carried by the Team, not the Role.
 *
 * WHY THE TEAM IS REGISTERED AS `otherUserTeams`
 * ----------------------------------------------
 * Every team→tenant resolver in the codebase (TenantResolver,
 * Services\TeamTenantAccess) matches `Tenant.baseUserTeam` OR
 * `Tenant.otherUserTeams`. Registering the admin team there makes records
 * scoped to it resolve to the right tenant for free. A dedicated
 * `Tenant.adminUserTeam` link would instead resolve to NO tenant everywhere —
 * reintroducing the class of bug where a record scoped to a tenant's secondary
 * team silently stored `tenantId = NULL`.
 *
 * It also does not escalate anyone: Hooks\Tenant\SyncUserTeams adds a newly
 * related tenant user to `baseUserTeamId` only, so admin-team membership stays
 * an explicit, deliberate assignment. Un-relating a user from the Tenant still
 * removes them from every tenant team, admin team included.
 */
class TenantAdminTeamProvisioner
{
    public const NAME_SUFFIX = ' / Admin';

    /** Team.name is varchar(100), same cap as Tenant.name. */
    private const TEAM_NAME_MAX_LENGTH = 100;

    public function __construct(
        private EntityManager $entityManager,
        private TenantTeamProvisioner $teamProvisioner,
        private Log $log,
    ) {}

    /**
     * The tenant's admin Team id, identified as an `otherUserTeams` member
     * holding the tenant-admin role. Null when not provisioned (or when the
     * role has not been seeded).
     *
     * Detection is by ROLE, not by name, so renaming a tenant cannot orphan the
     * existing admin team and cause a second one to be provisioned.
     */
    public function findAdminTeamId(Entity $tenant): ?string
    {
        $roleId = $this->teamProvisioner->resolveSeededRoleId(TenantRoleAuth::TENANT_ADMIN_STATIC_ID);

        if ($roleId === null) {
            return null;
        }

        return $this->findLinkedAdminTeamId($tenant, $roleId);
    }

    /**
     * Idempotent. Returns the admin Team id, or null when it could not be
     * provisioned (missing tenant name, or the `tenant-admin` Role has not been
     * seeded yet — Rebuild\SeedRole runs later in the rebuild sequence).
     */
    public function ensureForTenant(Entity $tenant): ?string
    {
        $tenantName = trim((string) ($tenant->get('name') ?? ''));

        if ($tenantName === '') {
            $this->log->warning(sprintf(
                'Global Module: Tenant %s has no name; cannot provision its admin team.',
                (string) $tenant->getId(),
            ));

            return null;
        }

        $roleId = $this->teamProvisioner->resolveSeededRoleId(TenantRoleAuth::TENANT_ADMIN_STATIC_ID);

        if ($roleId === null) {
            // Do NOT create a role-less team: it would look provisioned while
            // granting nothing, and the backfill would then skip it forever.
            $this->log->error(
                'Global Module: the `tenant-admin` Role does not exist yet; skipping admin-team '
                . 'provisioning for Tenant ' . (string) $tenant->getId()
                . '. Re-run rebuild once SeedRole has created it.'
            );

            return null;
        }

        // Detect by ROLE, not by name: renaming a tenant must not cause a
        // second admin team to be provisioned alongside the first.
        $existingId = $this->findLinkedAdminTeamId($tenant, $roleId);

        if ($existingId !== null) {
            return $existingId;
        }

        $teamId = $this->teamProvisioner->findOrCreateTeamWithRole(
            $this->adminTeamName($tenantName),
            $roleId,
        );

        $this->entityManager
            ->getRDBRepository('Tenant')
            ->getRelation($tenant, 'otherUserTeams')
            ->relateById($teamId, null, ['skipHooks' => true]);

        $this->log->info(sprintf(
            'Global Module: provisioned admin team %s for Tenant %s.',
            $teamId,
            (string) $tenant->getId(),
        ));

        return $teamId;
    }

    /**
     * Tenant.name and Team.name are both varchar(100), so a maximally long
     * tenant name plus the suffix would overflow Team.name and make the INSERT
     * fail (Postgres) or truncate (MySQL). Clamp the base instead.
     */
    public function adminTeamName(string $tenantName): string
    {
        $budget = self::TEAM_NAME_MAX_LENGTH - mb_strlen(self::NAME_SUFFIX);

        if (mb_strlen($tenantName) > $budget) {
            $tenantName = rtrim(mb_substr($tenantName, 0, $budget));
        }

        return $tenantName . self::NAME_SUFFIX;
    }

    /**
     * The tenant's existing admin team, identified as an `otherUserTeams`
     * member holding the tenant-admin role.
     */
    private function findLinkedAdminTeamId(Entity $tenant, string $roleId): ?string
    {
        $otherTeamIds = [];

        $otherTeams = $this->entityManager
            ->getRDBRepository('Tenant')
            ->getRelation($tenant, 'otherUserTeams')
            ->find();

        foreach ($otherTeams as $team) {
            $id = (string) $team->getId();

            if ($id !== '') {
                $otherTeamIds[] = $id;
            }
        }

        if ($otherTeamIds === []) {
            return null;
        }

        $team = $this->entityManager
            ->getRDBRepository('Team')
            ->where(['id' => $otherTeamIds])
            ->join('roles')
            ->where(['roles.id' => $roleId])
            ->findOne();

        return $team ? (string) $team->getId() : null;
    }
}
