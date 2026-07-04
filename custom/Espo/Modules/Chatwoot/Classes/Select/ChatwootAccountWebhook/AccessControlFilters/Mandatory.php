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

namespace Espo\Modules\Chatwoot\Classes\Select\ChatwootAccountWebhook\AccessControlFilters;

use Espo\Core\Select\AccessControl\Filter;
use Espo\Entities\User;
use Espo\ORM\Query\SelectBuilder;

/**
 * Mandatory access-control filter for ChatwootAccountWebhook list queries.
 *
 * Applied unconditionally by Espo's select layer so that:
 *
 *   • Admins see all webhooks (no constraint added).
 *   • Non-admins never see webhooks whose URL contains ".cluster.local"
 *     (internal cluster endpoints such as the Hatchet AI Agent webhook).
 *
 * Pairs with {@see \Espo\Modules\Chatwoot\Classes\Acl\ChatwootAccountWebhook\AccessChecker}
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

        $queryBuilder->where(['url!*' => '%.cluster.local%']);
    }
}
