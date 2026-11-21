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
use Espo\Modules\Global\Classes\Utils\TenantRoleAuth;
use Espo\ORM\EntityManager;

/**
 * Which workspaces a user administers. The tenant-admin role comes through a
 * workspace's Admin team, so it is per workspace: an admin of A who is a plain
 * member of B administers A only. Same resolution as FeatureAiUsage's billing
 * access.
 */
class TenantAdminScope
{
    public function __construct(
        private EntityManager $entityManager,
        private UserTenantResolver $userTenantResolver,
        private TenantResolver $tenantResolver,
    ) {}

    /**
     * Uncached, for ACL classes that live as long as the process.
     */
    public static function create(EntityManager $entityManager): self
    {
        $tenantResolver = new TenantResolver($entityManager);

        return new self($entityManager, new UserTenantResolver($entityManager, $tenantResolver), $tenantResolver);
    }

    /**
     * @return ?list<string> Null means every workspace (instance admin).
     */
    public function adminTenantIds(User $user): ?array
    {
        if ($user->isAdmin()) {
            return null;
        }

        if ($user->isPortal() || $user->isApi()) {
            return [];
        }

        $adminRoleIds = TenantRoleAuth::tenantAdminRoleIds();

        if (array_intersect($adminRoleIds, $user->getLinkMultipleIdList('roles'))) {
            return $this->userTenantResolver->resolveTenantIds($user);
        }

        $teamIds = $user->getTeamIdList();

        if ($teamIds === []) {
            return [];
        }

        $adminTeamIds = [];

        $teams = $this->entityManager
            ->getRDBRepository('Team')
            ->select(['id'])
            ->where(['id' => $teamIds])
            ->join('roles')
            ->where(['roles.id' => $adminRoleIds])
            ->distinct()
            ->find();

        foreach ($teams as $team) {
            $adminTeamIds[] = (string) $team->getId();
        }

        if ($adminTeamIds === []) {
            return [];
        }

        return array_values(array_intersect(
            $this->userTenantResolver->resolveTenantIds($user),
            $this->tenantResolver->resolveAllFromTeamIds($adminTeamIds),
        ));
    }

    /**
     * Whether the actor administers every workspace the target belongs to.
     */
    public function administers(User $actor, User $target): bool
    {
        $adminTenantIds = $this->adminTenantIds($actor);

        if ($adminTenantIds === null) {
            return true;
        }

        if ($adminTenantIds === []) {
            return false;
        }

        $targetTenantIds = $this->userTenantResolver->resolveTenantIds($target);

        return $targetTenantIds !== [] && array_diff($targetTenantIds, $adminTenantIds) === [];
    }
}
