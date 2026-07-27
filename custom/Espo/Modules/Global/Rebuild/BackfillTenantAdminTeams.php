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

namespace Espo\Modules\Global\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\Modules\Global\Tools\Tenant\TenantAdminTeamProvisioner;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Backfills the "{tenantName} / Admin" Team for Tenants created before
 * Hooks\Tenant\CreateAdminTeam existed.
 *
 * The hook only covers new Tenants, so this is what makes the guarantee hold
 * for the existing estate. Idempotent — safe on every rebuild.
 *
 * MUST be registered AFTER SeedRole in app/rebuild.json: the provisioner
 * refuses to create a role-less admin team, so the `tenant-admin` Role has to
 * exist first.
 */
class BackfillTenantAdminTeams implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantAdminTeamProvisioner $provisioner,
        private Log $log,
    ) {}

    public function process(): void
    {
        $this->log->info('Global Module: Backfilling Tenant admin teams...');

        $provisioned = 0;
        $alreadyPresent = 0;
        $failed = 0;

        $tenants = $this->entityManager
            ->getRDBRepository('Tenant')
            ->find();

        foreach ($tenants as $tenant) {
            try {
                // ensureForTenant() is idempotent; compare against the set of
                // otherUserTeams before/after to report accurately.
                $before = $this->otherUserTeamCount($tenant);

                $teamId = $this->provisioner->ensureForTenant($tenant);

                if ($teamId === null) {
                    $failed++;

                    continue;
                }

                if ($this->otherUserTeamCount($tenant) > $before) {
                    $provisioned++;
                } else {
                    $alreadyPresent++;
                }
            } catch (Throwable $e) {
                $failed++;

                $this->log->error(sprintf(
                    'Global Module: failed to provision admin team for Tenant %s: %s',
                    (string) $tenant->getId(),
                    $e->getMessage(),
                ));
            }
        }

        $this->log->info(
            "Global Module: Tenant admin team backfill complete. "
            . "Provisioned: {$provisioned}, Already present: {$alreadyPresent}, Failed: {$failed}"
        );
    }

    private function otherUserTeamCount(\Espo\ORM\Entity $tenant): int
    {
        return $this->entityManager
            ->getRDBRepository('Tenant')
            ->getRelation($tenant, 'otherUserTeams')
            ->count();
    }
}
