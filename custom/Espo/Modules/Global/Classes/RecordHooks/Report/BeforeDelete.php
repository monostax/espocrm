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

namespace Espo\Modules\Global\Classes\RecordHooks\Report;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\Hook\DeleteHook;
use Espo\Core\Record\DeleteParams;
use Espo\Entities\User;
use Espo\ORM\Entity;

/**
 * Prevents non-admin users from deleting globally shared Reports.
 *
 * @implements DeleteHook<Entity>
 * @noinspection PhpUnused
 */
class BeforeDelete implements DeleteHook
{
    public function __construct(
        private User $user,
    ) {}

    public function process(Entity $entity, DeleteParams $params): void
    {
        if ($this->user->isAdmin()) {
            return;
        }

        if ($entity->get('isGloballyShared')) {
            throw new Forbidden(
                "Globally shared reports can only be deleted by administrators."
            );
        }
    }
}
