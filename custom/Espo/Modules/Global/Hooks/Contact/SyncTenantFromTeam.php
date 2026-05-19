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

namespace Espo\Modules\Global\Hooks\Contact;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Auto-derive Contact.tenantId from the first team in teamsIds.
 *
 * Tenant is the multi-tenancy boundary; teams are RBAC permissions.
 * A Contact must always be owned by exactly one Tenant, but can be
 * shared across multiple Teams WITHIN that same tenant.
 *
 * Resolution order:
 *   1. If tenantId is explicitly set on the entity, keep it (but verify
 *      consistency with the first team).
 *   2. Otherwise resolve tenantId from the first team via:
 *        - Tenant.baseUserTeamId  = team.id   (primary membership)
 *        - tenantOtherUserTeam    middle table (additional membership)
 *
 * Runs at order 5 so ValidateUniqueCpf (order 9) sees a populated tenantId.
 */
class SyncTenantFromTeam
{
    public static int $order = 5;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        $currentTenantId = $this->normalizeNullableString($entity->get('tenantId'));

        if ($currentTenantId) {
            return;
        }

        $teamIds = $entity instanceof CoreEntity
            ? $entity->getLinkMultipleIdList('teams')
            : [];

        if ($teamIds === []) {
            // No team and no tenant — field is required, let the ORM
            // validator raise a normal "required" error.
            return;
        }

        $firstTeamId = $this->normalizeNullableString($teamIds[0] ?? null);

        if (!$firstTeamId) {
            return;
        }

        $tenantId = $this->resolveTenantIdFromTeam($firstTeamId);

        if (!$tenantId) {
            throw new BadRequest(
                'Cannot determine tenant for this Contact from its team. '
                . 'Ensure the team belongs to exactly one Tenant (as baseUserTeam or otherUserTeam).'
            );
        }

        $entity->set('tenantId', $tenantId);
    }

    /**
     * Resolve a Tenant id for the given team id. Tries baseUserTeam first,
     * then the tenantOtherUserTeam pivot. Returns null if not found.
     */
    private function resolveTenantIdFromTeam(string $teamId): ?string
    {
        $tenantByBase = $this->entityManager
            ->getRDBRepository('Tenant')
            ->select(['id'])
            ->where(['baseUserTeamId' => $teamId])
            ->findOne();

        if ($tenantByBase) {
            return $tenantByBase->getId();
        }

        // Join the otherUserTeams many-to-many to find the tenant.
        $tenantByOther = $this->entityManager
            ->getRDBRepository('Tenant')
            ->select(['id'])
            ->join('otherUserTeams', 'otherUserTeams')
            ->where(['otherUserTeamsMiddle.teamId' => $teamId])
            ->findOne();

        return $tenantByOther?->getId();
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
