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

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\ORM\Entity;

/**
 * Auto-derive entity.tenantId from team membership (teams linkMultiple
 * and/or preferred team ids such as Funnel.teamId).
 *
 * Tenant is the multi-tenancy boundary; teams are RBAC permissions.
 */
class TenantFromTeamsSync
{
    public function __construct(
        private TenantResolver $tenantResolver,
    ) {}

    /**
     * Fill tenantId when empty. No-op when already set.
     *
     * @param list<string> $preferredTeamIds Tried first (order preserved), then entity teams.
     */
    public function applyBeforeSave(Entity $entity, string $entityLabel, array $preferredTeamIds = []): void
    {
        $currentTenantId = $this->normalizeNullableString($entity->get('tenantId'));

        if ($currentTenantId) {
            return;
        }

        $teamIds = [];

        foreach ($preferredTeamIds as $teamId) {
            $normalized = $this->normalizeNullableString($teamId);

            if ($normalized !== null) {
                $teamIds[] = $normalized;
            }
        }

        if ($entity instanceof CoreEntity && $entity->hasLinkMultipleField('teams')) {
            foreach ($entity->getLinkMultipleIdList('teams') as $teamId) {
                $normalized = $this->normalizeNullableString($teamId);

                if ($normalized !== null && !in_array($normalized, $teamIds, true)) {
                    $teamIds[] = $normalized;
                }
            }
        }

        if ($teamIds === []) {
            // No team and no tenant — let required validators raise normally.
            return;
        }

        $tenantId = $this->tenantResolver->resolveFromTeamIds($teamIds);

        if (!$tenantId) {
            throw new BadRequest(
                "Cannot determine tenant for this {$entityLabel} from its team. "
                . 'Ensure the team belongs to exactly one Tenant (as baseUserTeam or otherUserTeam).'
            );
        }

        $entity->set('tenantId', $tenantId);
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
