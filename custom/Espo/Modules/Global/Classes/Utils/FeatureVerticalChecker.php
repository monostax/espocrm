<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\Global\Classes\Utils;

use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\ORM\EntityManager;

/**
 * Shared utility: resolves which feature verticals a user has access to.
 *
 * Walks user -> teams -> team.roles and maps role static IDs to vertical names
 * via metadata (`app.featureVerticals`).
 */
class FeatureVerticalChecker
{
    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
    ) {}

    /**
     * Get all feature verticals the user has access to.
     *
     * @return string[]
     */
    public function getVerticals(User $user): array
    {
        // Admins get all known verticals.
        if ($user->isAdmin()) {
            return $this->getAllVerticalNames();
        }

        $roleIdToVerticalMap = $this->buildRoleIdToVerticalMap();

        if (empty($roleIdToVerticalMap)) {
            return [];
        }

        $userRoleIds = $this->getUserRoleIds($user);
        $verticals = [];

        foreach ($userRoleIds as $roleId) {
            if (isset($roleIdToVerticalMap[$roleId])) {
                $verticals[] = $roleIdToVerticalMap[$roleId];
            }
        }

        return array_values(array_unique($verticals));
    }

    /**
     * Check if the user has a specific feature vertical.
     */
    public function hasVertical(User $user, string $vertical): bool
    {
        return in_array($vertical, $this->getVerticals($user), true);
    }

    /**
     * Get all known vertical names from metadata.
     *
     * @return string[]
     */
    private function getAllVerticalNames(): array
    {
        $verticals = $this->metadata->get(['app', 'featureVerticals']) ?? [];

        return array_keys($verticals);
    }

    /**
     * Build a map from role ID to vertical name.
     *
     * @return array<string, string>
     */
    private function buildRoleIdToVerticalMap(): array
    {
        $verticals = $this->metadata->get(['app', 'featureVerticals']) ?? [];
        $toHash = $this->metadata->get(['app', 'recordId', 'type']) === 'uuid4' ||
                  $this->metadata->get(['app', 'recordId', 'dbType']) === 'uuid';

        $map = [];

        foreach ($verticals as $verticalName => $config) {
            $roleStaticId = $config['roleStaticId'] ?? null;

            if ($roleStaticId === null) {
                continue;
            }

            $roleId = $this->prepareId($roleStaticId, $toHash);
            $map[$roleId] = $verticalName;
        }

        return $map;
    }

    /**
     * Get all role IDs assigned to the user via their teams.
     *
     * @return string[]
     */
    private function getUserRoleIds(User $user): array
    {
        $teamIds = $user->getLinkMultipleIdList('teams');

        if (empty($teamIds)) {
            return [];
        }

        // Get roles from teams
        $roleIds = [];

        $teams = $this->entityManager
            ->getRDBRepository('Team')
            ->where(['id' => $teamIds])
            ->find();

        foreach ($teams as $team) {
            $teamRoleIds = $team->getLinkMultipleIdList('roles');

            foreach ($teamRoleIds as $roleId) {
                $roleIds[] = $roleId;
            }
        }

        // Also get roles directly assigned to the user
        $userRoleIds = $user->getLinkMultipleIdList('roles');

        foreach ($userRoleIds as $roleId) {
            $roleIds[] = $roleId;
        }

        return array_values(array_unique($roleIds));
    }

    /**
     * Prepare ID for entity.
     * If UUID mode is enabled, returns MD5 hash of the ID.
     * Otherwise, returns the ID as-is.
     */
    private function prepareId(string $id, bool $toHash): string
    {
        if ($toHash) {
            return md5($id);
        }

        return $id;
    }
}
