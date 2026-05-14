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

namespace Espo\Modules\Global\Classes\Select\UserApiKey\AccessControlFilters;

use Espo\Core\Select\AccessControl\Filter;
use Espo\Entities\User;
use Espo\ORM\Query\SelectBuilder;

/**
 * Mandatory access-control filter for UserApiKey list queries.
 *
 * Applied unconditionally by Espo's select layer so that:
 *
 *   • Admins see all keys (no constraint added).
 *   • Portal users see nothing (`id = null` produces an empty result).
 *   • Everyone else is scoped to their own keys (`userId = currentUser.id`).
 *
 * Pairs with {@see \Espo\Modules\Global\Classes\Acl\UserApiKey\AccessChecker}
 * which enforces the same boundary on single-entity CRUD.
 */
class Mandatory implements Filter
{
    public function __construct(private User $user) {}

    public function apply(SelectBuilder $queryBuilder): void
    {
        if ($this->user->isAdmin()) {
            return;
        }

        if ($this->user->isPortal()) {
            $queryBuilder->where(['id' => null]);

            return;
        }

        $queryBuilder->where(['userId' => $this->user->getId()]);
    }
}
