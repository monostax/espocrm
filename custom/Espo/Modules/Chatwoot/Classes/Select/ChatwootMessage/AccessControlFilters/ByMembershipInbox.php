<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax - Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Classes\Select\ChatwootMessage\AccessControlFilters;

use Espo\Core\Select\AccessControl\Filter;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Tools\Acl\InboxAccessResolver;
use Espo\ORM\Query\SelectBuilder as QueryBuilder;

class ByMembershipInbox implements Filter
{
    public function __construct(
        private User $user,
        private InboxAccessResolver $inboxAccessResolver,
    ) {}

    public function apply(QueryBuilder $queryBuilder): void
    {
        $allowedInboxIdList = $this->inboxAccessResolver->getAllowedInboxIdList($this->user);

        if ($allowedInboxIdList === null) {
            return;
        }

        if ($allowedInboxIdList === []) {
            $queryBuilder->where(['id' => null]);

            return;
        }

        $queryBuilder
            ->leftJoin('conversation', 'conversationAccess')
            ->where(['conversationAccess.inboxId' => $allowedInboxIdList]);
    }
}
