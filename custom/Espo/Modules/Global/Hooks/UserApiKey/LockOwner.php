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

namespace Espo\Modules\Global\Hooks\UserApiKey;

use Espo\Core\Exceptions\Forbidden;
use Espo\ORM\Entity;

/**
 * A key authenticates as its `user`. Edit access is checked against the
 * stored owner, so without this a user could create a key for themselves
 * (learning the secret) and then move it to anyone they can read, e.g. an
 * admin. An ORM hook, not a record hook: bulk update skips record hooks.
 * Nothing legitimately moves a key; revoke it and create a new one.
 */
class LockOwner
{
    public static int $order = 1;

    /**
     * @param array<string, mixed> $options
     * @throws Forbidden
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        if ($entity->isNew() || !$entity->isAttributeChanged('userId')) {
            return;
        }

        throw new Forbidden('An API key cannot be moved to another user. Revoke it and create a new one.');
    }
}
