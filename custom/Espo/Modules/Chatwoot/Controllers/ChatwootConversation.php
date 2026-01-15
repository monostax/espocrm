<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SelectBuilderFactory;

class ChatwootConversation extends \Espo\Core\Templates\Controllers\Base
{
    /**
     * GET ChatwootConversation/action/statusCounts
     * Returns the count of conversations for each status filter.
     * Returns dual counts: 'mine' (assigned to current user) and 'others'.
     * Uses ACL-aware queries to respect user permissions.
     */
    public function getActionStatusCounts(Request $request): object
    {
        if (!$this->acl->check('ChatwootConversation', 'read')) {
            throw new Forbidden();
        }

        /** @var SelectBuilderFactory $selectBuilderFactory */
        $selectBuilderFactory = $this->injectableFactory->create(SelectBuilderFactory::class);
        
        $currentUserId = $this->user->getId();
        $statuses = ['open', 'pending', 'resolved', 'snoozed'];
        $counts = new \stdClass();

        foreach ($statuses as $status) {
            // Build ACL-aware query for "mine" count
            $mineQuery = $selectBuilderFactory
                ->create()
                ->forUser($this->user)
                ->from('ChatwootConversation')
                ->withAccessControlFilter()
                ->buildQueryBuilder()
                ->where([
                    'status' => $status,
                    'assignedUserId' => $currentUserId
                ])
                ->build();

            $mine = $this->entityManager
                ->getRDBRepository('ChatwootConversation')
                ->clone($mineQuery)
                ->count();

            // Build ACL-aware query for "others" count
            $othersQuery = $selectBuilderFactory
                ->create()
                ->forUser($this->user)
                ->from('ChatwootConversation')
                ->withAccessControlFilter()
                ->buildQueryBuilder()
                ->where([
                    'status' => $status,
                    'OR' => [
                        ['assignedUserId' => null],
                        ['assignedUserId!=' => $currentUserId]
                    ]
                ])
                ->build();

            $others = $this->entityManager
                ->getRDBRepository('ChatwootConversation')
                ->clone($othersQuery)
                ->count();

            $counts->$status = (object) [
                'mine' => $mine,
                'others' => $others
            ];
        }

        return $counts;
    }
}




