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

namespace Espo\Modules\Global\Hooks\Tenant;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Synchronizes User team membership when Tenant.users relation changes.
 *
 * Rules:
 * - When a user is linked to a tenant, add tenant baseUserTeam to user teams.
 * - When a user is unlinked from a tenant, remove all teams related to that tenant
 *   (baseUserTeam + otherUserTeams) from user teams.
 */
class SyncUserTeams
{
    public static int $order = 20;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $relationParams
     */
    public function afterRelate(Entity $entity, array $options, array $relationParams): void
    {
        if (($relationParams['relationName'] ?? null) !== 'users') {
            return;
        }

        $userId = $relationParams['foreignId'] ?? null;

        if (!$userId) {
            return;
        }

        $baseTeamId = $entity->get('baseUserTeamId');

        if (!$baseTeamId) {
            return;
        }

        $user = $this->entityManager->getEntityById('User', $userId);

        if (!$user) {
            return;
        }

        $userTeamsRelation = $this->entityManager
            ->getRDBRepository('User')
            ->getRelation($user, 'teams');

        $userTeamIds = [];
        foreach ($userTeamsRelation->find() as $team) {
            $userTeamIds[] = $team->getId();
        }

        if (in_array($baseTeamId, $userTeamIds, true)) {
            return;
        }

        $userTeamsRelation->relateById($baseTeamId, null, ['skipHooks' => true]);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $relationParams
     */
    public function afterUnrelate(Entity $entity, array $options, array $relationParams): void
    {
        if (($relationParams['relationName'] ?? null) !== 'users') {
            return;
        }

        $userId = $relationParams['foreignId'] ?? null;

        if (!$userId) {
            return;
        }

        $user = $this->entityManager->getEntityById('User', $userId);

        if (!$user) {
            return;
        }

        $teamIdsToRemove = $this->getTenantTeamIds($entity);

        if (!$teamIdsToRemove) {
            return;
        }

        $userTeamsRelation = $this->entityManager
            ->getRDBRepository('User')
            ->getRelation($user, 'teams');

        $currentUserTeamIds = [];
        foreach ($userTeamsRelation->find() as $team) {
            $currentUserTeamIds[] = $team->getId();
        }

        $teamIdsToActuallyRemove = array_intersect($teamIdsToRemove, $currentUserTeamIds);

        foreach ($teamIdsToActuallyRemove as $teamId) {
            $userTeamsRelation->unrelateById($teamId, ['skipHooks' => true]);
        }
    }

    /**
     * @return string[]
     */
    private function getTenantTeamIds(Entity $tenant): array
    {
        $teamIds = [];

        $baseTeamId = $tenant->get('baseUserTeamId');
        if ($baseTeamId) {
            $teamIds[] = $baseTeamId;
        }

        $otherTeams = $this->entityManager
            ->getRDBRepository('Tenant')
            ->getRelation($tenant, 'otherUserTeams')
            ->find();

        foreach ($otherTeams as $team) {
            $teamIds[] = $team->getId();
        }

        return array_values(array_unique($teamIds));
    }
}
