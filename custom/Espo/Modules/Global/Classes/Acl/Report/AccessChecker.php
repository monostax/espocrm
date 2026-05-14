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

namespace Espo\Modules\Global\Classes\Acl\Report;

use Espo\Core\Acl\ScopeData;
use Espo\Entities\User;
use Espo\Modules\Advanced\Classes\Acl\Report\AccessChecker as AdvancedReportAccessChecker;
use Espo\ORM\Entity;

/**
 * Globally shared Reports (isGloballyShared = true) are read-only for
 * non-admin users. Only administrators may edit or delete them.
 *
 * Extends the Advanced module's AccessChecker so that its target-entity
 * read check is preserved (a user must still have scope access to the
 * Report's target entity type to read the Report).
 *
 * @noinspection PhpUnused
 */
class AccessChecker extends AdvancedReportAccessChecker
{
    /**
     * @param Entity $entity
     */
    public function checkEntityEdit(User $user, Entity $entity, ScopeData $data): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($entity->get('isGloballyShared')) {
            return false;
        }

        return parent::checkEntityEdit($user, $entity, $data);
    }

    /**
     * @param Entity $entity
     */
    public function checkEntityDelete(User $user, Entity $entity, ScopeData $data): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($entity->get('isGloballyShared')) {
            return false;
        }

        return parent::checkEntityDelete($user, $entity, $data);
    }
}
