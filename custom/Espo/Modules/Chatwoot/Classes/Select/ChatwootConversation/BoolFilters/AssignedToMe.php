<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Classes\Select\ChatwootConversation\BoolFilters;

use Espo\Core\Select\Bool\Filter;
use Espo\Entities\User;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\SelectBuilder as QueryBuilder;
use Espo\ORM\Query\Part\Where\OrGroupBuilder;
use Espo\ORM\Query\Part\Condition as Cond;

/**
 * Bool filter to show only conversations assigned to the current user.
 *
 * The filter traverses the relationship chain:
 * ChatwootConversation.assigneeId -> ChatwootUser.chatwootUserId -> ChatwootUser.assignedUserId -> User
 *
 * @noinspection PhpUnused
 */
class AssignedToMe implements Filter
{
    public function __construct(
        private User $user,
        private EntityManager $entityManager
    ) {}

    public function apply(QueryBuilder $queryBuilder, OrGroupBuilder $orGroupBuilder): void
    {
        // Find all ChatwootUser records assigned to the current user and get their platform user IDs
        $chatwootUsers = $this->entityManager
            ->getRDBRepository('ChatwootUser')
            ->select(['chatwootUserId'])
            ->where([
                'assignedUserId' => $this->user->getId(),
                'chatwootUserId!=' => null,
            ])
            ->find();

        $platformUserIdList = [];
        foreach ($chatwootUsers as $chatwootUser) {
            $platformUserId = $chatwootUser->get('chatwootUserId');
            if ($platformUserId !== null) {
                $platformUserIdList[] = (int) $platformUserId;
            }
        }

        if (empty($platformUserIdList)) {
            // If the user has no linked ChatwootUsers, return no results
            $orGroupBuilder->add(
                Cond::equal(Cond::column('id'), null)
            );
            return;
        }

        $orGroupBuilder->add(
            Cond::in(
                Cond::column('assigneeId'),
                $platformUserIdList
            )
        );
    }
}
