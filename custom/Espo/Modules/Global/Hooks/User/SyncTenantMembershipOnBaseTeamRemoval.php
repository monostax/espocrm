<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Global\Hooks\User;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Keeps Tenant.users consistent when a user gains/loses a tenant base team from User UI.
 *
 * - If an added team is the baseUserTeam of any Tenant, the user is related to that Tenant.
 * - If a removed team is the baseUserTeam of any Tenant currently linked to the user,
 *   the user is unrelated from that Tenant and all Tenant.otherUserTeams are removed
 *   from the user teams relation.
 */
class SyncTenantMembershipOnBaseTeamRemoval
{
    public static int $order = 20;
    private const INTERNAL_SYNC_OPTION = 'tenantMembershipSyncInternal';

    public function __construct(
        private EntityManager $entityManager
    ) {}

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $relationParams
     */
    public function afterRelate(Entity $entity, array $options, array $relationParams): void
    {
        if (!empty($options[self::INTERNAL_SYNC_OPTION])) {
            return;
        }

        if (($relationParams['relationName'] ?? null) !== 'teams') {
            return;
        }

        $addedTeamId = $relationParams['foreignId'] ?? null;

        if (!$addedTeamId) {
            return;
        }

        $userId = $entity->getId();

        if (!$userId) {
            return;
        }

        $tenants = $this->entityManager
            ->getRDBRepository('Tenant')
            ->where([
                'baseUserTeamId' => $addedTeamId,
            ])
            ->find();

        foreach ($tenants as $tenant) {
            $tenantUsersRelation = $this->entityManager
                ->getRDBRepository('Tenant')
                ->getRelation($tenant, 'users');

            if (!$tenantUsersRelation->isRelated($entity)) {
                $tenantUsersRelation->relateById($userId, null, [self::INTERNAL_SYNC_OPTION => true]);
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $relationParams
     */
    public function afterUnrelate(Entity $entity, array $options, array $relationParams): void
    {
        if (!empty($options[self::INTERNAL_SYNC_OPTION])) {
            return;
        }

        if (($relationParams['relationName'] ?? null) !== 'teams') {
            return;
        }

        $removedTeamId = $relationParams['foreignId'] ?? null;

        if (!$removedTeamId) {
            return;
        }

        $userId = $entity->getId();

        if (!$userId) {
            return;
        }

        $tenants = $this->entityManager
            ->getRDBRepository('Tenant')
            ->distinct()
            ->join('users')
            ->where([
                'users.id' => $userId,
                'baseUserTeamId' => $removedTeamId,
            ])
            ->find();

        $userTeamsRelation = $this->entityManager
            ->getRDBRepository('User')
            ->getRelation($entity, 'teams');

        $currentUserTeamIds = [];
        foreach ($userTeamsRelation->find() as $team) {
            $currentUserTeamIds[] = $team->getId();
        }

        foreach ($tenants as $tenant) {
            $otherTeamIds = $this->getTenantOtherUserTeamIds($tenant);

            foreach ($otherTeamIds as $teamId) {
                if (in_array($teamId, $currentUserTeamIds, true)) {
                    $userTeamsRelation->unrelateById($teamId, [self::INTERNAL_SYNC_OPTION => true]);
                }
            }

            $this->entityManager
                ->getRDBRepository('Tenant')
                ->getRelation($tenant, 'users')
                ->unrelateById($userId, [self::INTERNAL_SYNC_OPTION => true]);
        }
    }

    /**
     * @return string[]
     */
    private function getTenantOtherUserTeamIds(Entity $tenant): array
    {
        $ids = [];

        $teams = $this->entityManager
            ->getRDBRepository('Tenant')
            ->getRelation($tenant, 'otherUserTeams')
            ->find();

        foreach ($teams as $team) {
            $ids[] = $team->getId();
        }

        return array_values(array_unique($ids));
    }
}
