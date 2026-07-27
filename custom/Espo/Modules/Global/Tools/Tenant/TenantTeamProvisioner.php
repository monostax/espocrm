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
     * Find-or-create a Team by exact name and ensure it holds $roleId.
     *
     * Team.name carries no unique constraint, so an exact-name match is reused
     * instead of stacking duplicates on repeated runs (and so a Team orphaned by
     * a failed Tenant insert is recycled rather than duplicated on retry).
     */
    public function findOrCreateTeamWithRole(string $name, string $roleId): string
    {
        $team = $this->entityManager
            ->getRDBRepository('Team')
            ->where(['name' => $name])
            ->findOne();

        if (!$team) {
            $team = $this->entityManager->createEntity('Team', ['name' => $name]);
        }

        $this->ensureRoleLinked($team, $roleId);

        return (string) $team->getId();
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
