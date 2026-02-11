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

namespace Espo\Modules\FeatureEnhancedNotification\Hooks\Notification;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\WebSocket\Submission as WebSocketSubmission;
use Espo\Entities\Notification;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Sends a WebSocket event for popup notifications when a
 * Workflow/Formula-generated notification (TYPE_MESSAGE) is created.
 * Runs after the core WebSocketSubmit hook (order 20).
 *
 * @implements AfterSave<Notification>
 */
class PopupWebSocketSubmit implements AfterSave
{
    public static int $order = 25;

    public function __construct(
        private WebSocketSubmission $webSocketSubmission,
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity->isNew()) {
            return;
        }

        if ($entity->getType() !== Notification::TYPE_MESSAGE) {
            return;
        }

        $notificationData = $entity->getData();

        if (!($notificationData?->isPopup ?? false)) {
            return;
        }

        $userId = $entity->getUserId();

        if (!$userId) {
            return;
        }

        $data = [
            'list' => [
                [
                    'id' => $entity->getId(),
                    'data' => (object) [
                        'message' => $entity->get('message') ?? '',
                        'entityType' => $entity->get('relatedType'),
                        'entityId' => $entity->get('relatedId'),
                        'entityName' => $notificationData?->entityName ?? '',
                        'userName' => $notificationData?->userName ?? '',
                    ],
                ],
            ],
        ];

        $this->webSocketSubmission->submit(
            'popupNotifications.workflowMessage',
            $userId,
            (object) $data
        );
    }
}
