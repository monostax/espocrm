<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\FeaturePwaPush\Entities;

use Espo\Core\ORM\Entity;

/**
 * PushNotificationQueue entity stores pending push notifications.
 */
class PushNotificationQueue extends Entity
{
    public const ENTITY_TYPE = 'PushNotificationQueue';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';

    /**
     * Get the user ID.
     */
    public function getUserId(): ?string
    {
        return $this->get('userId');
    }

    /**
     * Get the subscription ID.
     */
    public function getSubscriptionId(): ?string
    {
        return $this->get('subscriptionId');
    }

    /**
     * Get the notification title.
     */
    public function getTitle(): string
    {
        return $this->get('title');
    }

    /**
     * Get the notification body.
     */
    public function getBody(): ?string
    {
        return $this->get('body');
    }

    /**
     * Get the notification icon URL.
     */
    public function getIcon(): ?string
    {
        return $this->get('icon');
    }

    /**
     * Get the URL to open when notification is clicked.
     */
    public function getUrl(): ?string
    {
        return $this->get('url');
    }

    /**
     * Get additional data payload.
     */
    public function getData(): ?object
    {
        return $this->get('data');
    }

    /**
     * Get the notification type.
     */
    public function getNotificationType(): ?string
    {
        return $this->get('notificationType');
    }

    /**
     * Get the priority level.
     */
    public function getPriority(): string
    {
        return $this->get('priority') ?? self::PRIORITY_NORMAL;
    }

    /**
     * Get the current status.
     */
    public function getStatus(): string
    {
        return $this->get('status') ?? self::STATUS_PENDING;
    }

    /**
     * Set the status.
     */
    public function setStatus(string $status): void
    {
        $this->set('status', $status);
    }

    /**
     * Get the number of attempts made.
     */
    public function getAttempts(): int
    {
        return (int) $this->get('attempts');
    }

    /**
     * Get the maximum number of attempts allowed.
     */
    public function getMaxAttempts(): int
    {
        return (int) ($this->get('maxAttempts') ?? 3);
    }

    /**
     * Increment the attempt counter.
     */
    public function incrementAttempts(): void
    {
        $this->set('attempts', $this->getAttempts() + 1);
    }

    /**
     * Check if more attempts are allowed.
     */
    public function canRetry(): bool
    {
        return $this->getAttempts() < $this->getMaxAttempts();
    }

    /**
     * Get the last error message.
     */
    public function getLastError(): ?string
    {
        return $this->get('lastError');
    }

    /**
     * Set the last error message.
     */
    public function setLastError(string $error): void
    {
        $this->set('lastError', $error);
    }

    /**
     * Get the scheduled time.
     */
    public function getScheduledAt(): ?string
    {
        return $this->get('scheduledAt');
    }

    /**
     * Get the sent timestamp.
     */
    public function getSentAt(): ?string
    {
        return $this->get('sentAt');
    }

    /**
     * Mark as sent.
     */
    public function markAsSent(): void
    {
        $this->setStatus(self::STATUS_SENT);
        $this->set('sentAt', date('Y-m-d H:i:s'));
    }

    /**
     * Mark as failed.
     */
    public function markAsFailed(string $error): void
    {
        $this->setStatus(self::STATUS_FAILED);
        $this->setLastError($error);
    }

    /**
     * Mark as processing.
     */
    public function markAsProcessing(): void
    {
        $this->setStatus(self::STATUS_PROCESSING);
    }
}
