<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\FeaturePwaPush\Hooks\Notification;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Utils\Log;
use Espo\Entities\Notification;
use Espo\Entities\Preferences;
use Espo\Modules\FeaturePwaPush\Services\PushNotificationService;
use Espo\Modules\FeaturePwaPush\Services\SubscriptionService;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Universal hook that queues a push notification for every new Notification entity.
 * This covers all notification types: assignments, mentions, messages, notes,
 * email received, entity removed, collaborating, reactions, system, etc.
 *
 * Runs after the core WebSocketSubmit hook (order 20) and the popup hook (order 25).
 *
 * @implements AfterSave<Notification>
 */
class QueuePushNotification implements AfterSave
{
    public static int $order = 30;

    public function __construct(
        private EntityManager $entityManager,
        private PushNotificationService $pushNotificationService,
        private SubscriptionService $subscriptionService,
        private Log $log
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity->isNew()) {
            return;
        }

        $userId = $entity->getUserId();

        if (!$userId) {
            return;
        }

        // Skip if user has no push subscriptions (avoid unnecessary work)
        $subscriptions = $this->subscriptionService->getUserSubscriptions($userId);
        if (empty($subscriptions)) {
            return;
        }

        // Check if push notifications are enabled for this user
        if (!$this->isPushEnabled($userId)) {
            return;
        }

        $type = $entity->getType();
        $title = $this->buildTitle($entity, $type);
        $body = $this->buildBody($entity, $type);
        $url = $this->buildUrl($entity, $type);

        try {
            $this->pushNotificationService->queueForUser(
                $userId,
                $title,
                $body,
                [
                    'url' => $url,
                    'notificationType' => $type,
                ]
            );
        } catch (\Throwable $e) {
            $this->log->error(
                "FeaturePwaPush: Failed to queue push for notification {$entity->getId()}: " . $e->getMessage()
            );
        }
    }

    /**
     * Build a human-readable title based on notification type.
     */
    private function buildTitle(Entity $entity, ?string $type): string
    {
        return match ($type) {
            Notification::TYPE_ASSIGN => 'New Assignment',
            Notification::TYPE_MENTION_IN_POST => 'You were mentioned',
            Notification::TYPE_NOTE => 'New Activity',
            Notification::TYPE_MESSAGE => $this->getMessageTitle($entity),
            Notification::TYPE_EMAIL_RECEIVED => 'New Email',
            Notification::TYPE_ENTITY_REMOVED => 'Record Removed',
            Notification::TYPE_COLLABORATING => 'Collaboration Update',
            Notification::TYPE_USER_REACTION => 'New Reaction',
            Notification::TYPE_SYSTEM => 'System Notification',
            default => 'Notification',
        };
    }

    /**
     * Build a notification body from the entity data.
     */
    private function buildBody(Entity $entity, ?string $type): string
    {
        // If there's a message field, use it directly
        $message = $entity->get('message');
        if ($message) {
            // Strip HTML tags and truncate
            return mb_substr(strip_tags($message), 0, 200);
        }

        $data = $entity->getData();

        return match ($type) {
            Notification::TYPE_ASSIGN => $this->buildAssignBody($data),
            Notification::TYPE_MENTION_IN_POST => 'You were mentioned in a post',
            Notification::TYPE_NOTE => $this->buildNoteBody($data),
            Notification::TYPE_EMAIL_RECEIVED => $this->buildEmailBody($data),
            Notification::TYPE_ENTITY_REMOVED => $this->buildEntityRemovedBody($data),
            Notification::TYPE_COLLABORATING => 'A record you follow was updated',
            Notification::TYPE_USER_REACTION => 'Someone reacted to your post',
            Notification::TYPE_SYSTEM => 'System notification',
            default => 'You have a new notification',
        };
    }

    /**
     * Build a URL to navigate to when the notification is clicked.
     */
    private function buildUrl(Entity $entity, ?string $type): string
    {
        $data = $entity->getData();

        // Try related entity first
        $relatedType = $entity->get('relatedType');
        $relatedId = $entity->get('relatedId');
        if ($relatedType && $relatedId) {
            return "/#{$relatedType}/view/{$relatedId}";
        }

        // Try relatedParent
        $parentType = $entity->get('relatedParentType');
        $parentId = $entity->get('relatedParentId');
        if ($parentType && $parentId) {
            return "/#{$parentType}/view/{$parentId}";
        }

        // Try data fields
        $entityType = $data?->entityType ?? null;
        $entityId = $data?->entityId ?? null;
        if ($entityType && $entityId) {
            return "/#{$entityType}/view/{$entityId}";
        }

        return '/';
    }

    private function getMessageTitle(Entity $entity): string
    {
        $data = $entity->getData();
        if (isset($data->header)) {
            return mb_substr(strip_tags($data->header), 0, 100);
        }

        return 'New Message';
    }

    private function buildAssignBody(?object $data): string
    {
        $entityType = $data?->entityType ?? 'record';
        $entityName = $data?->entityName ?? '';

        if ($entityName) {
            return "You have been assigned to {$entityType}: {$entityName}";
        }

        return "You have been assigned to a {$entityType}";
    }

    private function buildNoteBody(?object $data): string
    {
        $entityType = $data?->entityType ?? '';
        $entityName = $data?->entityName ?? '';

        if ($entityName && $entityType) {
            return "New activity on {$entityType}: {$entityName}";
        }

        return 'New activity on a record you follow';
    }

    private function buildEmailBody(?object $data): string
    {
        $entityName = $data?->entityName ?? '';

        if ($entityName) {
            return "New email: {$entityName}";
        }

        return 'You received a new email';
    }

    private function buildEntityRemovedBody(?object $data): string
    {
        $entityType = $data?->entityType ?? 'record';
        $entityName = $data?->entityName ?? '';

        if ($entityName) {
            return "{$entityType} \"{$entityName}\" has been removed";
        }

        return "A {$entityType} has been removed";
    }

    /**
     * Check if push notifications are globally enabled for the user.
     */
    private function isPushEnabled(string $userId): bool
    {
        $preferences = $this->entityManager->getEntityById(Preferences::ENTITY_TYPE, $userId);

        if (!$preferences) {
            return true; // Default to enabled
        }

        $settings = $preferences->get('pwaPushNotifications') ?? [];

        return ($settings['enabled'] ?? true) !== false;
    }
}
