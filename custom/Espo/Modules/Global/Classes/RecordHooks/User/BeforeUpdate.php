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

namespace Espo\Modules\Global\Classes\RecordHooks\User;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\Hook\SaveHook;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Entities\User;

/**
 * BeforeUpdate hook for User entity.
 *
 * When a regular (non-admin) user edits another user:
 * - Validates that all team changes stay within the editor's teams
 * - Prevents removing users from teams the editor doesn't belong to
 * - Prevents modifying roles
 * - Prevents privilege escalation
 *
 * @implements SaveHook<User>
 * @noinspection PhpUnused
 */
class BeforeUpdate implements SaveHook
{
    public function __construct(
        private User $user,
        private EntityManager $entityManager
    ) {}

    public function process(Entity $entity): void
    {
        // Skip validation for admins - they have full control
        if ($this->user->isAdmin()) {
            return;
        }

        // Users editing their own profile have limited restrictions
        if ($this->user->getId() === $entity->getId()) {
            $this->preventSelfPrivilegeEscalation($entity);
            $this->preventSelfTeamChanges($entity);
            return;
        }

        $this->validateTeamChanges($entity);
        $this->validateDefaultTeamChange($entity);
        $this->preventRoleChanges($entity);
        $this->preventPrivilegeEscalation($entity);
        $this->preventTypeChange($entity);
        $this->preventPasswordChange($entity);
    }

    /**
     * Prevent non-admins from changing other users' passwords.
     * Only admins can reset passwords for other users.
     *
     * @throws Forbidden
     */
    private function preventPasswordChange(User $entity): void
    {
        if ($entity->isAttributeChanged('password')) {
            throw new Forbidden("Cannot change other users' passwords. Contact an administrator.");
        }
    }

    /**
     * Validate that team changes stay within the editor's teams.
     * Prevents both adding teams the editor doesn't belong to AND
     * removing users from teams the editor doesn't belong to.
     *
     * @throws Forbidden
     */
    private function validateTeamChanges(User $entity): void
    {
        // Check if teams are being modified
        if (!$entity->isAttributeChanged('teamsIds')) {
            return;
        }

        $newTeamIds = $entity->getLinkMultipleIdList('teams') ?? [];
        $editorTeamIds = $this->user->getTeamIdList();

        // Get the original teams from database
        $originalTeamIds = $this->getOriginalTeamIds($entity->getId());

        // User must still have at least one team
        if (empty($newTeamIds)) {
            throw new Forbidden("User must be assigned to at least one team.");
        }

        // Check for teams being added - must be in editor's teams
        $addedTeams = array_diff($newTeamIds, $originalTeamIds);
        foreach ($addedTeams as $teamId) {
            if (!in_array($teamId, $editorTeamIds, true)) {
                throw new Forbidden("Cannot assign user to a team you don't belong to.");
            }
        }

        // Check for teams being removed - can only remove from editor's teams
        $removedTeams = array_diff($originalTeamIds, $newTeamIds);
        foreach ($removedTeams as $teamId) {
            if (!in_array($teamId, $editorTeamIds, true)) {
                throw new Forbidden("Cannot remove user from a team you don't belong to.");
            }
        }
    }

    /**
     * Validate that default team changes are within the editor's teams.
     *
     * @throws Forbidden
     */
    private function validateDefaultTeamChange(User $entity): void
    {
        if (!$entity->isAttributeChanged('defaultTeamId')) {
            return;
        }

        $defaultTeamId = $entity->get('defaultTeamId');
        if (!$defaultTeamId) {
            return;
        }

        $editorTeamIds = $this->user->getTeamIdList();

        if (!in_array($defaultTeamId, $editorTeamIds, true)) {
            throw new Forbidden("Cannot set default team to a team you don't belong to.");
        }
    }

    /**
     * Get original team IDs for a user from the database.
     *
     * @return string[]
     */
    private function getOriginalTeamIds(string $userId): array
    {
        $user = $this->entityManager->getEntityById(User::ENTITY_TYPE, $userId);

        if (!$user) {
            return [];
        }

        return $user->getLinkMultipleIdList('teams') ?? [];
    }

    /**
     * Prevent non-admins from changing roles.
     *
     * @throws Forbidden
     */
    private function preventRoleChanges(User $entity): void
    {
        if ($entity->isAttributeChanged('rolesIds')) {
            throw new Forbidden("Cannot modify user roles.");
        }
    }

    /**
     * Prevent privilege escalation by blocking admin/superAdmin flags.
     *
     * @throws Forbidden
     */
    private function preventPrivilegeEscalation(User $entity): void
    {
        // Cannot set admin flag
        if ($entity->isAttributeChanged('isAdmin') && $entity->isAdmin()) {
            throw new Forbidden("Cannot grant admin privileges.");
        }

        // Cannot set super admin flag
        if ($entity->isAttributeChanged('isSuperAdmin') && $entity->isSuperAdmin()) {
            throw new Forbidden("Cannot grant super admin privileges.");
        }
    }

    /**
     * Prevent changing user type.
     *
     * @throws Forbidden
     */
    private function preventTypeChange(User $entity): void
    {
        if ($entity->isAttributeChanged('type')) {
            throw new Forbidden("Cannot change user type.");
        }
    }

    /**
     * Prevent self privilege escalation when users edit their own profile.
     *
     * @throws Forbidden
     */
    private function preventSelfPrivilegeEscalation(User $entity): void
    {
        if ($entity->isAttributeChanged('isAdmin') && $entity->isAdmin()) {
            throw new Forbidden("Cannot grant yourself admin privileges.");
        }

        if ($entity->isAttributeChanged('isSuperAdmin') && $entity->isSuperAdmin()) {
            throw new Forbidden("Cannot grant yourself super admin privileges.");
        }

        if ($entity->isAttributeChanged('type')) {
            throw new Forbidden("Cannot change your own user type.");
        }

        if ($entity->isAttributeChanged('rolesIds')) {
            throw new Forbidden("Cannot modify your own roles.");
        }
    }

    /**
     * Prevent users from modifying their own team assignments.
     *
     * @throws Forbidden
     */
    private function preventSelfTeamChanges(User $entity): void
    {
        if ($entity->isAttributeChanged('teamsIds')) {
            throw new Forbidden("Cannot modify your own team assignments.");
        }

        if ($entity->isAttributeChanged('defaultTeamId')) {
            throw new Forbidden("Cannot modify your own default team.");
        }
    }
}

