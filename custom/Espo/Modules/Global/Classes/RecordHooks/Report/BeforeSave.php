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

use Espo\Core\Record\Hook\SaveHook;
use Espo\ORM\Entity;

/**
 * Enforces a critical multi-tenant invariant on Report:
 *
 *     isGloballyShared = true  =>  applyAcl = true
 *
 * Without applyAcl, the report's query bypasses the running user's
 * row-level ACL on the target entity. When the report is also globally
 * shared (visible to all tenants), this would expose every tenant's
 * data to every user. Forcing applyAcl when the global share flag is
 * on closes this misconfiguration path even for administrators.
 *
 * Silent enforcement: no error is raised — the value is simply coerced.
 *
 * @implements SaveHook<Entity>
 * @noinspection PhpUnused
 */
class BeforeSave implements SaveHook
{
    public function process(Entity $entity): void
    {
        if (!$entity->get('isGloballyShared')) {
            return;
        }

        if (!$entity->get('applyAcl')) {
            $entity->set('applyAcl', true);
        }
    }
}
