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

use Espo\Core\Utils\Log;
use Espo\Modules\Global\Classes\Utils\TenantRoleAuth;
use Espo\Modules\Global\Tools\Tenant\TenantTeamProvisioner;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Creates the base Team (holding the shared `tenant` Role) for a new Tenant and
 * links it as Tenant.baseUserTeam.
 *
 * Counterpart to CreateAdminTeam, which provisions the `tenant-admin` team.
 *
 * WHY afterSave AND NOT beforeSave
 * --------------------------------
 * This previously created the Team in beforeSave. EspoCRM does not wrap a
 * record save in a transaction, so if the Tenant INSERT then failed (DB error,
 * constraint, deadlock) the Team was already committed and leaked as an orphan:
 * a team carrying the `tenant` role but belonging to no Tenant, which every
 * team→tenant resolver reports as "no tenant". Creating it after the Tenant row
 * is committed removes that window. `baseUserTeam` is not a required field, so
 * nothing validates it before the save.
 *
 * The FK is persisted with a targeted UPDATE rather than a nested saveEntity()
 * because RDBRepository::save() calls Entity::setAsNotNew(), which would make
 * every later `isNew()` hook (CreateDefaultSidenavConfig at $order = 30,
 * ProvisionCrmTrackingSource at 31) silently skip. The in-memory attribute is
 * set too, so SyncAclTeamsFromBaseUserTeam ($order = 7) sees it this request.
 *
 * It runs on every save, but costs nothing once baseUserTeam is set: the early
 * return happens before any query. That makes it self-healing if the link is
 * ever cleared, which would otherwise leave the Tenant unable to resolve a
 * tenantId from teams at all.
 */
class CreateBaseTeam
{
    public static int $order = 5;

    public function __construct(
        private EntityManager $entityManager,
        private TenantTeamProvisioner $teamProvisioner,
        private Log $log,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        if (!empty($options['skipBaseTeamProvisioning'])) {
            return;
        }

        if ($entity->get('baseUserTeamId')) {
            return;
        }

        $tenantName = trim((string) ($entity->get('name') ?? ''));

        if ($tenantName === '') {
            $this->log->warning(sprintf(
                'Global Module: Tenant %s has no name; cannot provision its base team.',
                (string) $entity->getId(),
            ));

            return;
        }

        $roleId = $this->teamProvisioner->resolveSeededRoleId(TenantRoleAuth::TENANT_STATIC_ID);

        if ($roleId === null) {
            // Previously the role id was computed from metadata and linked
            // blindly. On an instance where SeedRole had not run that produced a
            // dangling team_role row — a base team that silently granted nothing.
            $this->log->error(
                'Global Module: the `tenant` Role does not exist yet; skipping base-team '
                . 'provisioning for Tenant ' . (string) $entity->getId()
                . '. Re-run rebuild once SeedRole has created it.'
            );

            return;
        }

        $teamId = $this->teamProvisioner->findOrCreateTeamWithRole($tenantName, $roleId);

        $entity->set('baseUserTeamId', $teamId);

        $this->persistBaseUserTeamId((string) $entity->getId(), $teamId);

        $this->log->info(sprintf(
            'Global Module: provisioned base team %s for Tenant %s.',
            $teamId,
            (string) $entity->getId(),
        ));
    }

    private function persistBaseUserTeamId(string $tenantId, string $teamId): void
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->update()
            ->in('Tenant')
            ->set(['baseUserTeamId' => $teamId])
            ->where(['id' => $tenantId])
            ->build();

        $this->entityManager->getQueryExecutor()->execute($query);
    }
}
