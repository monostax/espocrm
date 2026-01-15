<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Hooks\ChatwootConversation;

use Espo\Core\WebSocket\Submission;
use Espo\ORM\Entity;

/**
 * Push WebSocket message when a ChatwootConversation status changes.
 * This enables real-time badge updates across all connected browsers.
 */
class PushWebSocketUpdate
{
    public static int $order = 99;

    public function __construct(
        private Submission $webSocketSubmission
    ) {}

    /**
     * After save hook - push WebSocket message if status changed.
     */
    public function afterSave(Entity $entity, array $options): void
    {
        // Skip if no status change
        if (!$entity->isAttributeChanged('status')) {
            return;
        }

        // Push to all connected clients
        $this->webSocketSubmission->submit('chatwootConversationUpdate', null, (object) [
            'conversationId' => $entity->getId(),
            'oldStatus' => $entity->getFetched('status'),
            'newStatus' => $entity->get('status'),
            'assignedUserId' => $entity->get('assignedUserId'),
        ]);
    }
}


