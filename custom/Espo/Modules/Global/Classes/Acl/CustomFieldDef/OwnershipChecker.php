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

namespace Espo\Modules\Global\Classes\Acl\CustomFieldDef;

use Espo\Core\Acl\OwnershipTeamChecker;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Entities\User;
use Espo\ORM\Entity;

/**
 * @implements OwnershipTeamChecker<Entity>
 */
class OwnershipChecker implements OwnershipTeamChecker
{
    public function checkOwn(User $user, Entity $entity): bool
    {
        return false;
    }

    public function checkTeam(User $user, Entity $entity): bool
    {
        $teamIds = $this->getTeamIds($entity);

        if ($teamIds === []) {
            return false;
        }

        $userTeamIds = $user->getTeamIdList();

        foreach ($teamIds as $teamId) {
            if (in_array($teamId, $userTeamIds, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function getTeamIds(Entity $entity): array
    {
        if ($entity instanceof CoreEntity) {
            try {
                $ids = $entity->getLinkMultipleIdList('teams');

                if (is_array($ids) && $ids !== []) {
                    return array_values($ids);
                }
            } catch (\Throwable) {
                // fall through
            }
        }

        $raw = $entity->get('teamsIds');

        return is_array($raw) ? array_values($raw) : [];
    }
}
