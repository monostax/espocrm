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
 * Mirror the Tenant.baseUserTeam into the standard ACL `teams` linkMultiple
 * link (polymorphic `entity_team` table).
 *
 * EspoCRM's ACL "team" access filter (Espo\Core\Select\AccessControl\Filters\OnlyTeam)
 * requires the entity to expose a `teams` linkMultiple link to Team via the
 * `entityTeam` relation. The UI's role/ACL panel also keys off this link to
 * show the "team" access level.
 *
 * This hook keeps the polymorphic relation in sync with the single
 * `baseUserTeam` link so that:
 *   - Users belonging to the tenant's base team can read the Tenant.
 *   - Changing baseUserTeam updates ACL membership accordingly.
 */
class SyncAclTeamsFromBaseUserTeam
{
    public static int $order = 7;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        if (!empty($options['skipAclTeamsSync'])) {
            return;
        }

        $baseTeamId = $entity->get('baseUserTeamId');

        $teamsRelation = $this->entityManager
            ->getRDBRepository('Tenant')
            ->getRelation($entity, 'teams');

        $currentTeamIds = [];
        foreach ($teamsRelation->find() as $team) {
            $currentTeamIds[] = $team->getId();
        }

        $desiredTeamIds = $baseTeamId ? [$baseTeamId] : [];

        // Remove teams that should not be there.
        foreach ($currentTeamIds as $teamId) {
            if (!in_array($teamId, $desiredTeamIds, true)) {
                $teamsRelation->unrelateById($teamId, ['skipHooks' => true]);
            }
        }

        // Add the base team if missing.
        foreach ($desiredTeamIds as $teamId) {
            if (!in_array($teamId, $currentTeamIds, true)) {
                $teamsRelation->relateById($teamId, null, ['skipHooks' => true]);
            }
        }
    }
}
