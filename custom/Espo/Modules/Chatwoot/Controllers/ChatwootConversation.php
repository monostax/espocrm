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
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\NotFound;
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

    /**
     * GET ChatwootConversation/action/agentsForAssignment?id={conversationId}
     * Returns the list of agents available for assignment in the conversation's account.
     */
    public function getActionAgentsForAssignment(Request $request): object
    {
        $id = $request->getQueryParam('id');
        
        if (!$id) {
            throw new BadRequest('Conversation ID is required.');
        }

        if (!$this->acl->check('ChatwootConversation', 'read')) {
            throw new Forbidden();
        }

        // Get the conversation
        $conversation = $this->entityManager->getEntityById('ChatwootConversation', $id);
        
        if (!$conversation) {
            throw new NotFound('Conversation not found.');
        }

        // Check entity-level read permission
        if (!$this->acl->check($conversation, 'read')) {
            throw new Forbidden('Access denied.');
        }

        // Get the account ID from the conversation
        $accountId = $conversation->get('chatwootAccountId');
        
        if (!$accountId) {
            return (object) ['list' => []];
        }

        // Fetch agents for this account
        $agents = $this->entityManager
            ->getRDBRepository('ChatwootAgent')
            ->where(['chatwootAccountId' => $accountId])
            ->order('name')
            ->find();

        $list = [];
        foreach ($agents as $agent) {
            $list[] = (object) [
                'id' => $agent->get('chatwootAgentId'),
                'name' => $agent->get('name'),
                'availableName' => $agent->get('availableName'),
                'email' => $agent->get('email'),
                'availabilityStatus' => $agent->get('availabilityStatus'),
                'avatarUrl' => $agent->get('avatarUrl'),
                'role' => $agent->get('role'),
            ];
        }

        return (object) [
            'list' => $list,
            'currentAssigneeId' => $conversation->get('assigneeId'),
            'currentAssigneeName' => $conversation->get('assigneeName'),
        ];
    }
}
