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

namespace Espo\Modules\Global\Classes\Acl\UserApiKey;

use Espo\Core\Acl\OwnershipOwnChecker;
use Espo\Entities\User;
use Espo\ORM\Entity;

/**
 * Ownership checker for UserApiKey records.
 *
 * A key is "own" if its `userId` matches the current user id. Used by
 * Espo's generic stream / sharing / link-level checks that consult the
 * ownership checker for the entity type.
 *
 * @implements OwnershipOwnChecker<Entity>
 */
class OwnershipChecker implements OwnershipOwnChecker
{
    public function checkOwn(User $user, Entity $entity): bool
    {
        return $user->getId() === $entity->get('userId');
    }
}
