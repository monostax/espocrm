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

use Espo\Modules\Global\Classes\Utils\TenantRoleAuth;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Shared mechanics for provisioning a Tenant's Teams (base and admin).
 *
 * Both team provisioners need the same two things, and getting either wrong is
 * silent: resolving the SEEDED role id (which exists under the raw static id or
 * its md5 depending on the instance's record-id mode), and creating the Team
 * idempotently. Keeping them here stops the two call sites from drifting.
 */
class TenantTeamProvisioner
{
    public const PURPOSE_BASE = 'base';
    public const PURPOSE_ADMIN = 'admin';

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    /**
     * The row id of a seeded Role, or null when it has not been seeded yet.
     *
     * Existence is verified rather than assumed: linking a Team to a role id
     * that has no row produces a dangling `team_role` entry, i.e. a team that
     * silently grants nothing.
     */
    public function resolveSeededRoleId(string $staticId): ?string
    {
        foreach (TenantRoleAuth::roleIdsFor($staticId) as $roleId) {
            if ($this->entityManager->getEntityById('Role', $roleId)) {
                return $roleId;
            }
        }

        return null;
    }

    /**
     * Find-or-create the tenant's Team for $purpose and ensure it holds $roleId.
     *
     * The Team is identified by a unique provisioning key built from the tenant
     * id, never by name. Tenant names are not unique: matching by name let two
     * businesses with the same name share one team, and with it each other's
     * records. Repeated runs (and a retry after a failed hook) still reuse the
     * same Team. Its display name stays editable.
     */
    public function ensureTenantTeam(string $tenantId, string $purpose, string $name, string $roleId): string
    {
        $key = self::provisioningKey($tenantId, $purpose);
        $team = $this->findTeamByKey($key) ?? $this->createTeam($key, $name);

        $this->ensureRoleLinked($team, $roleId);

        return (string) $team->getId();
    }

    public static function provisioningKey(string $tenantId, string $purpose): string
    {
        return 'tenant-' . $purpose . ':' . $tenantId;
    }

    private function findTeamByKey(string $key): ?Entity
    {
        return $this->entityManager
            ->getRDBRepository('Team')
            ->where(['provisioningKey' => $key])
            ->findOne();
    }

    private function createTeam(string $key, string $name): Entity
    {
        // A soft-deleted Team keeps its key; release it so the unique index
        // admits the replacement.
        $this->entityManager->getQueryExecutor()->execute(
            $this->entityManager
                ->getQueryBuilder()
                ->update()
                ->in('Team')
                ->set(['provisioningKey' => null])
                ->where(['provisioningKey' => $key, 'deleted' => true])
                ->build()
        );

        try {
            return $this->entityManager->createEntity('Team', ['name' => $name, 'provisioningKey' => $key]);
        } catch (\Throwable $e) {
            // A concurrent provisioner inserted the same key first.
            return $this->findTeamByKey($key) ?? throw $e;
        }
    }

    private function ensureRoleLinked(Entity $team, string $roleId): void
    {
        $relation = $this->entityManager
            ->getRDBRepository('Team')
            ->getRelation($team, 'roles');

        foreach ($relation->find() as $role) {
            if ((string) $role->getId() === $roleId) {
                return;
            }
        }

        $relation->relateById($roleId, null, ['skipHooks' => true]);
    }
}
