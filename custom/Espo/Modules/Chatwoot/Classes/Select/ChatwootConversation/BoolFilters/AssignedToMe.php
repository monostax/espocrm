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
 * ChatwootConversation.assigneeId -> ChatwootAgent.chatwootAgentId -> ChatwootUser.assignedUserId -> User
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
        // Find all ChatwootAgent records where the linked ChatwootUser is assigned to the current user
        $agents = $this->entityManager
            ->getRDBRepository('ChatwootAgent')
            ->select(['chatwootAgentId'])
            ->leftJoin('chatwootUser')
            ->where([
                'chatwootUser.assignedUserId' => $this->user->getId(),
                'chatwootAgentId!=' => null,
            ])
            ->find();

        $chatwootAgentIdList = [];
        foreach ($agents as $agent) {
            $agentId = $agent->get('chatwootAgentId');
            if ($agentId !== null) {
                $chatwootAgentIdList[] = $agentId;
            }
        }

        if (empty($chatwootAgentIdList)) {
            // If the user has no linked agents, return no results
            $orGroupBuilder->add(
                Cond::equal(Cond::column('id'), null)
            );
            return;
        }

        $orGroupBuilder->add(
            Cond::in(
                Cond::column('assigneeId'),
                $chatwootAgentIdList
            )
        );
    }
}
