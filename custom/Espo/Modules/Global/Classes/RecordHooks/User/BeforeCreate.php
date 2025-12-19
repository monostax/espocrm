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
use Espo\Core\Utils\Metadata;
use Espo\ORM\Entity;
use Espo\Entities\User;

/**
 * BeforeCreate hook for User entity.
 *
 * When a regular (non-admin) user creates another user:
 * - Validates that all selected teams are within the creator's teams
 * - Forces the user type to 'regular'
 * - Auto-assigns the 'tenant' role
 * - Prevents setting admin/superAdmin flags
 *
 * @implements SaveHook<User>
 * @noinspection PhpUnused
 */
class BeforeCreate implements SaveHook
{
    private const TENANT_ROLE_STATIC_ID = 'tenant';

    public function __construct(
        private User $user,
        private Metadata $metadata
    ) {}

    public function process(Entity $entity): void
    {
        // Skip validation for admins - they have full control
        if ($this->user->isAdmin()) {
            return;
        }

        $this->validateTeams($entity);
        $this->enforceUserType($entity);
        $this->assignDefaultRole($entity);
        $this->preventPrivilegeEscalation($entity);
    }

    /**
     * Validate that all teams assigned to the new user are teams the creator belongs to.
     *
     * @throws Forbidden
     */
    private function validateTeams(User $entity): void
    {
        $newUserTeamIds = $entity->getLinkMultipleIdList('teams') ?? [];
        $creatorTeamIds = $this->user->getTeamIdList();

        // New user must have at least one team
        if (empty($newUserTeamIds)) {
            throw new Forbidden("New user must be assigned to at least one team.");
        }

        // All teams must be teams the creator belongs to
        foreach ($newUserTeamIds as $teamId) {
            if (!in_array($teamId, $creatorTeamIds, true)) {
                throw new Forbidden("Cannot assign user to a team you don't belong to.");
            }
        }

        // Validate default team if set
        $defaultTeamId = $entity->get('defaultTeamId');
        if ($defaultTeamId && !in_array($defaultTeamId, $creatorTeamIds, true)) {
            throw new Forbidden("Cannot set default team to a team you don't belong to.");
        }
    }

    /**
     * Force user type to 'regular' for non-admin creators.
     */
    private function enforceUserType(User $entity): void
    {
        $entity->set('type', User::TYPE_REGULAR);
    }

    /**
     * Auto-assign the tenant role to newly created users.
     */
    private function assignDefaultRole(User $entity): void
    {
        $roleId = $this->getTenantRoleId();

        // Set the role (overwriting any roles that might have been set)
        $entity->setLinkMultipleIdList('roles', [$roleId]);
    }

    /**
     * Prevent privilege escalation by blocking admin/superAdmin flags.
     *
     * @throws Forbidden
     */
    private function preventPrivilegeEscalation(User $entity): void
    {
        // Cannot create admin users
        if ($entity->isAdmin()) {
            throw new Forbidden("Cannot create admin users.");
        }

        // Cannot create super admin users
        if ($entity->isSuperAdmin()) {
            throw new Forbidden("Cannot create super admin users.");
        }

        // Cannot create API users
        if ($entity->isApi()) {
            throw new Forbidden("Cannot create API users.");
        }

        // Cannot create portal users (they have different management flow)
        if ($entity->isPortal()) {
            throw new Forbidden("Cannot create portal users.");
        }
    }

    /**
     * Get the tenant role ID, handling UUID mode.
     */
    private function getTenantRoleId(): string
    {
        $toHash = $this->metadata->get(['app', 'recordId', 'type']) === 'uuid4' ||
                  $this->metadata->get(['app', 'recordId', 'dbType']) === 'uuid';

        if ($toHash) {
            return md5(self::TENANT_ROLE_STATIC_ID);
        }

        return self::TENANT_ROLE_STATIC_ID;
    }
}

