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

namespace Espo\Modules\Global\Hooks\Tenant;

use Espo\Modules\Global\Tools\Tenant\TenantAdminTeamProvisioner;
use Espo\ORM\Entity;

/**
 * Creates the "{tenantName} / Admin" Team (holding the shared `tenant-admin`
 * Role) for a new Tenant and registers it as one of `otherUserTeams`.
 *
 * Counterpart to CreateBaseTeam, which provisions the `tenant`-role team.
 *
 * This is afterSave, not beforeSave, because `otherUserTeams` is a hasMany
 * through a relation table and needs the Tenant to have an id. It runs at
 * $order = 6 so the team exists before CreateDefaultSidenavConfig ($order = 30)
 * reads baseUserTeam + otherUserTeams to build the tenant's default sidenav.
 *
 * It runs on EVERY save, not only on create, because `otherUserTeams` is a
 * linkMultiple field and Espo\Hooks\Common\FieldProcessing ($order = -11) syncs
 * it to exactly the submitted id list. A client PATCHing `otherUserTeamsIds`
 * without the admin team would otherwise silently un-provision it. Running
 * after that sync makes the guarantee self-healing; ensureForTenant() is
 * idempotent and short-circuits once the team is found by role.
 *
 * It does not contend with SyncAclTeamsFromBaseUserTeam ($order = 7), which
 * rewrites the separate `teams` / `entityTeam` ACL relation to exactly
 * [baseUserTeamId]. Admin-team members can still read their Tenant record
 * because SyncUserTeams also puts every tenant user in the base team.
 *
 * Pass `skipAdminTeamProvisioning => true` to opt out (fixtures, imports).
 */
class CreateAdminTeam
{
    public static int $order = 6;

    public function __construct(
        private TenantAdminTeamProvisioner $provisioner,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        if (!empty($options['skipAdminTeamProvisioning'])) {
            return;
        }

        $this->provisioner->ensureForTenant($entity);
    }
}
