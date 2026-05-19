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

use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\AclManager;
use Espo\Entities\User;
use Espo\Modules\Advanced\Classes\Acl\Report\AccessChecker as AdvancedReportAccessChecker;
use Espo\Modules\Advanced\Entities\Report;
use Espo\ORM\Entity;

/**
 * Globally shared Reports (isGloballyShared = true) are read-only for
 * non-admin users. Only administrators may edit or delete them.
 *
 * On the read side, globally shared Reports bypass the default row-level
 * ACL (team / own / assigned), so every user who has scope access to the
 * Report's target entity type can read them. Row-level trimming of the
 * Report's *results* still happens inside the Report Service via
 * `withStrictAccessControl()` against the target entity.
 *
 * Extends the Advanced module's AccessChecker so that its target-entity
 * read check is preserved (a user must still have scope access to the
 * Report's target entity type to read the Report).
 *
 * @noinspection PhpUnused
 */
class AccessChecker extends AdvancedReportAccessChecker
{
    private AclManager $aclManagerLocal;

    public function __construct(
        DefaultAccessChecker $defaultAccessChecker,
        AclManager $aclManager
    ) {
        parent::__construct($defaultAccessChecker, $aclManager);
        $this->aclManagerLocal = $aclManager;
    }

    /**
     * @param Report $entity
     */
    public function checkEntityRead(User $user, Entity $entity, ScopeData $data): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($entity->get('isGloballyShared')) {
            // Preserve the parent's target-entity scope gate so a user
            // who has no read access to the report's underlying entity
            // type still cannot read the report definition.
            if (
                $entity->getTargetEntityType() &&
                !$this->aclManagerLocal->checkScope($user, $entity->getTargetEntityType())
            ) {
                return false;
            }

            return true;
        }

        return parent::checkEntityRead($user, $entity, $data);
    }

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
