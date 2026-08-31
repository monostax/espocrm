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

namespace Espo\Modules\Global\Tools\Acl;

use Espo\Core\Name\Field;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Shared team-membership resolution for the custom team-scoped ACL checkers
 * (Funnel, OpportunityStage).
 *
 * These scopes are always team-bounded regardless of the role's access level,
 * because Team membership is what confines a record to its Tenant. A role
 * granting `all` must therefore still not leak records across tenants — so the
 * checkers intersect user teams with entity teams on top of the normal role
 * check rather than instead of it.
 *
 * Mirrors Espo\Core\Acl\DefaultOwnershipChecker::checkTeam(), with a DB
 * fallback for the case where the `teamsIds` link-multiple attribute was not
 * loaded onto the entity (ACL checks can receive partially-loaded entities).
 */
class TeamsAccess
{
    public function __construct(
        private EntityManager $entityManager,
    ) {}

    /**
     * Whether the user shares at least one Team with the entity.
     */
    public function userSharesTeam(User $user, Entity $entity): bool
    {
        $entityTeamIds = $this->entityTeamIds($entity);

        if ($entityTeamIds === []) {
            return false;
        }

        foreach ($user->getTeamIdList() as $userTeamId) {
            if (in_array($userTeamId, $entityTeamIds, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the entity's Team ids.
     *
     * @return string[]
     */
    public function entityTeamIds(Entity $entity): array
    {
        if (
            $entity instanceof CoreEntity &&
            $entity->hasRelation(Field::TEAMS) &&
            $entity->has(Field::TEAMS . 'Ids')
        ) {
            return $this->normalize($entity->getLinkMultipleIdList(Field::TEAMS));
        }

        return $this->loadFromEntityTeam($entity);
    }

    /**
     * Read the polymorphic entity_team rows directly. Used when `teamsIds` is
     * not present on the entity, so that access is decided on real data rather
     * than silently denied because of a partially-loaded entity.
     *
     * @return string[]
     */
    private function loadFromEntityTeam(Entity $entity): array
    {
        $id = $entity->getId();

        if (trim($id) === '') {
            return [];
        }

        $rows = $this->entityManager
            ->getRDBRepository(Team::RELATIONSHIP_ENTITY_TEAM)
            ->where([
                'entityType' => $entity->getEntityType(),
                'entityId' => $id,
                'deleted' => false,
            ])
            ->find();

        $ids = [];

        foreach ($rows as $row) {
            $ids[] = $row->get('teamId');
        }

        return $this->normalize($ids);
    }

    /**
     * @param mixed[] $ids
     * @return string[]
     */
    private function normalize(array $ids): array
    {
        $result = [];

        foreach ($ids as $id) {
            if (!is_string($id)) {
                continue;
            }

            $id = trim($id);

            if ($id !== '' && !in_array($id, $result, true)) {
                $result[] = $id;
            }
        }

        return $result;
    }
}
