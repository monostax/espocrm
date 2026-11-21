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
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * What a user may see of another user who also works in other workspaces.
 *
 * Monostax staff (and some customers) are members of several tenants. Espo
 * reads one shared team as one shared organisation, so a tenant user who
 * shared a single team with a staff member could read every team on that
 * member's profile (other customers' names), their whole stream feed (other
 * tenants' deals) and their calendar.
 *
 * A non-admin viewer "covers" another user when none of that user's teams
 * belongs to a tenant the viewer is not part of. Only then may the viewer see
 * the user's teams, feed and calendar. A team that belongs to no tenant names
 * no foreign workspace and is ignored, matching TeamTenantAccess.
 *
 * Teams are all-or-nothing on purpose: the client decides whether to show the
 * stream, activities and calendar of a user from the teams it can see, so an
 * uncovered user must show none.
 */
class TenantVisibility
{
    /** @var array<string, array<string, true>> viewer id => team id set */
    private array $worldCache = [];

    /** @var array<string, bool> team id => belongs to some tenant */
    private array $tenantTeamCache = [];

    public function __construct(
        private EntityManager $entityManager,
        private UserTenantResolver $userTenantResolver,
    ) {}

    /**
     * An uncached instance, for ACL classes that live as long as the process
     * (daemon and WebSocket workers outlast any team change).
     */
    public static function create(EntityManager $entityManager): self
    {
        return new self($entityManager, new UserTenantResolver($entityManager, new TenantResolver($entityManager)));
    }

    /**
     * Whether the viewer may see the target's teams, feed and calendar.
     */
    public function covers(User $viewer, User $target): bool
    {
        if (!$this->isRestricted($viewer, (string) $target->getId())) {
            return true;
        }

        return !$this->namesForeignTenant($viewer, $target->getTeamIdList());
    }

    public function coversUserId(User $viewer, string $targetId): bool
    {
        if (!$this->isRestricted($viewer, $targetId)) {
            return true;
        }

        $target = $this->entityManager->getRDBRepositoryByClass(User::class)->getById($targetId);

        return $target !== null && $this->covers($viewer, $target);
    }

    /**
     * Whether any of the teams belongs to a tenant the viewer is not part of.
     *
     * @param list<string> $teamIds
     */
    public function namesForeignTenant(User $viewer, array $teamIds): bool
    {
        if ($viewer->isAdmin()) {
            return false;
        }

        $ownTeamIds = $viewer->getTeamIdList();
        $outside = [];

        foreach ($teamIds as $teamId) {
            if (is_string($teamId) && $teamId !== '' && !in_array($teamId, $ownTeamIds, true)) {
                $outside[] = $teamId;
            }
        }

        if ($outside === []) {
            return false;
        }

        $world = $this->world($viewer);
        $outside = array_values(array_filter($outside, fn (string $teamId) => !isset($world[$teamId])));

        if ($outside === []) {
            return false;
        }

        foreach ($this->tenantTeams($outside) as $teamId => $isTenantTeam) {
            if ($isTenantTeam) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clear the team attributes of a User about to be returned to the viewer,
     * when the viewer does not cover them. Output only: never call this on an
     * entity that will be saved, or the hidden memberships would be removed.
     */
    public function hideTeamsFromOutput(User $viewer, Entity $user): void
    {
        if (!$this->isRestricted($viewer, (string) $user->getId())) {
            return;
        }

        $teamIds = [];

        if ($user->has('teamsIds') && is_array($user->get('teamsIds'))) {
            $teamIds = $user->get('teamsIds');
        } else if ($user->has('teamsNames') && $user->get('teamsNames') instanceof stdClass) {
            $teamIds = array_keys(get_object_vars($user->get('teamsNames')));
        }

        $defaultTeamId = $user->has('defaultTeamId') ? $user->get('defaultTeamId') : null;

        $checked = $teamIds;

        if (is_string($defaultTeamId) && $defaultTeamId !== '') {
            $checked[] = $defaultTeamId;
        }

        if ($checked === [] || !$this->namesForeignTenant($viewer, array_values($checked))) {
            return;
        }

        foreach (['teamsIds' => [], 'teamsNames' => new stdClass(), 'teamsColumns' => new stdClass()] as $attribute => $empty) {
            if ($user->has($attribute)) {
                $user->set($attribute, $empty);
            }
        }

        foreach (['defaultTeamId', 'defaultTeamName'] as $attribute) {
            if ($user->has($attribute)) {
                $user->set($attribute, null);
            }
        }
    }

    /**
     * Of the given teams of the target (e.g. from an audit note), those the
     * viewer must not see: all of them when any names a foreign tenant.
     *
     * @param list<string> $storedTeamIds
     * @return list<string>
     */
    public function teamsHiddenFrom(User $viewer, string $targetId, array $storedTeamIds): array
    {
        if (!$this->isRestricted($viewer, $targetId)) {
            return [];
        }

        return $this->namesForeignTenant($viewer, $storedTeamIds) ? array_values($storedTeamIds) : [];
    }

    private function isRestricted(User $viewer, string $targetId): bool
    {
        return !$viewer->isAdmin() && $viewer->getId() !== $targetId;
    }

    /**
     * The viewer's own teams plus every team of every tenant they belong to.
     *
     * @return array<string, true>
     */
    private function world(User $viewer): array
    {
        $viewerId = (string) $viewer->getId();

        if (isset($this->worldCache[$viewerId])) {
            return $this->worldCache[$viewerId];
        }

        $world = [];

        foreach ($viewer->getTeamIdList() as $teamId) {
            $world[$teamId] = true;
        }

        foreach ($this->teamIdsOfTenants($this->userTenantResolver->resolveTenantIds($viewer)) as $teamId) {
            $world[$teamId] = true;
        }

        return $this->worldCache[$viewerId] = $world;
    }

    /**
     * @param list<string> $tenantIds
     * @return list<string>
     */
    protected function teamIdsOfTenants(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }

        $ids = [];

        $tenants = $this->entityManager
            ->getRDBRepository('Tenant')
            ->select(['id', 'baseUserTeamId'])
            ->where(['id' => $tenantIds])
            ->find();

        foreach ($tenants as $tenant) {
            $baseTeamId = $tenant->get('baseUserTeamId');

            if (is_string($baseTeamId) && $baseTeamId !== '') {
                $ids[$baseTeamId] = true;
            }
        }

        $others = $this->entityManager
            ->getRDBRepository('Team')
            ->select(['id'])
            ->join('tenantsAsOtherUserTeam', 'tenantsAsOtherUserTeam')
            ->where(['tenantsAsOtherUserTeamMiddle.tenantId' => $tenantIds])
            ->find();

        foreach ($others as $team) {
            $ids[(string) $team->getId()] = true;
        }

        return array_map('strval', array_keys($ids));
    }

    /**
     * @param list<string> $teamIds
     * @return array<string, bool> team id => belongs to some tenant
     */
    private function tenantTeams(array $teamIds): array
    {
        $unknown = array_values(array_filter(
            array_unique($teamIds),
            fn (string $teamId) => !array_key_exists($teamId, $this->tenantTeamCache),
        ));

        if ($unknown !== []) {
            $owned = array_fill_keys($this->tenantOwnedTeamIds($unknown), true);

            foreach ($unknown as $teamId) {
                $this->tenantTeamCache[$teamId] = isset($owned[$teamId]);
            }
        }

        $result = [];

        foreach ($teamIds as $teamId) {
            $result[$teamId] = $this->tenantTeamCache[$teamId];
        }

        return $result;
    }

    /**
     * Which of the given teams some tenant owns, as base or other user team.
     *
     * @param list<string> $teamIds
     * @return list<string>
     */
    protected function tenantOwnedTeamIds(array $teamIds): array
    {
        $owned = [];

        $tenants = $this->entityManager
            ->getRDBRepository('Tenant')
            ->select(['id', 'baseUserTeamId'])
            ->where(['baseUserTeamId' => $teamIds])
            ->find();

        foreach ($tenants as $tenant) {
            $owned[(string) $tenant->get('baseUserTeamId')] = true;
        }

        $teams = $this->entityManager
            ->getRDBRepository('Team')
            ->select(['id'])
            ->join('tenantsAsOtherUserTeam', 'tenantsAsOtherUserTeam')
            ->where(['id' => $teamIds])
            ->find();

        foreach ($teams as $team) {
            $owned[(string) $team->getId()] = true;
        }

        return array_map('strval', array_keys($owned));
    }
}
