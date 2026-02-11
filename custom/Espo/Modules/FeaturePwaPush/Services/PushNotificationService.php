<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\FeaturePwaPush\Services;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\FeaturePwaPush\Entities\PushNotificationQueue;
use Espo\Modules\FeaturePwaPush\Entities\PushSubscription;
use Espo\ORM\EntityManager;
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

/**
 * Service for sending push notifications.
 */
class PushNotificationService
{
    private ?WebPush $webPush = null;

    public function __construct(
        private EntityManager $entityManager,
        private VapidKeyService $vapidKeyService,
        private SubscriptionService $subscriptionService,
        private Config $config,
        private Log $log
    ) {}

    /**
     * Queue a notification for a user.
     */
    public function queueForUser(
        string $userId,
        string $title,
        string $body,
        array $options = []
    ): PushNotificationQueue {
        $subscriptions = $this->subscriptionService->getUserSubscriptions($userId);

        // Create a queue entry for each subscription
        $firstQueueEntry = null;
        foreach ($subscriptions as $subscription) {
            $queueEntry = $this->createQueueEntry(
                $userId,
                $subscription->getId(),
                $title,
                $body,
                $options
            );

            if ($firstQueueEntry === null) {
                $firstQueueEntry = $queueEntry;
            }
        }

        // If no subscriptions, still create a queue entry (will be marked as failed)
        if ($firstQueueEntry === null) {
            $firstQueueEntry = $this->createQueueEntry($userId, null, $title, $body, $options);
        }

        return $firstQueueEntry;
    }

    /**
     * Queue a notification for a specific subscription.
     */
    public function queueForSubscription(
        string $subscriptionId,
        string $title,
        string $body,
        array $options = []
    ): PushNotificationQueue {
        $subscription = $this->entityManager
            ->getEntityById(PushSubscription::ENTITY_TYPE, $subscriptionId);

        if (!$subscription || !$subscription->isActive()) {
            throw new \InvalidArgumentException('Subscription not found or inactive');
        }

        return $this->createQueueEntry(
            $subscription->getUserId(),
            $subscriptionId,
            $title,
            $body,
            $options
        );
    }

    /**
     * Process pending notifications in the queue.
     *
     * @return array{processed: int, sent: int, failed: int}
     */
    public function processQueue(int $batchSize = 50): array
    {
        $result = [
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
        ];

        // Get pending notifications
        $pendingNotifications = $this->entityManager
            ->getRDBRepository(PushNotificationQueue::ENTITY_TYPE)
            ->where([
                'status' => PushNotificationQueue::STATUS_PENDING,
            ])
            ->where([
                'OR' => [
                    ['scheduledAt<=' => date('Y-m-d H:i:s')],
                    ['scheduledAt' => null],
                ],
            ])
            ->order('priority', 'DESC')
            ->order('createdAt')
            ->limit($batchSize)
            ->find();

        foreach ($pendingNotifications as $notification) {
            $result['processed']++;

            try {
                $this->sendNotification($notification);
                $result['sent']++;
            } catch (\Throwable $e) {
                $result['failed']++;
                $this->log->error("Failed to send push notification {$notification->getId()}: " . $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Send a notification from the queue.
     */
    public function sendNotification(PushNotificationQueue $queueEntry): bool
    {
        $queueEntry->markAsProcessing();
        $queueEntry->incrementAttempts();
        $this->entityManager->saveEntity($queueEntry);

        // Check if subscription exists
        $subscriptionId = $queueEntry->getSubscriptionId();
        if (!$subscriptionId) {
            $queueEntry->markAsFailed('No subscription associated');
            $this->entityManager->saveEntity($queueEntry);
            return false;
        }

        $subscription = $this->entityManager
            ->getEntityById(PushSubscription::ENTITY_TYPE, $subscriptionId);

        if (!$subscription || !$subscription->isActive()) {
            $queueEntry->markAsFailed('Subscription not found or inactive');
            $this->entityManager->saveEntity($queueEntry);
            return false;
        }

        try {
            $webPush = $this->getWebPush();

            $pushSubscription = Subscription::create([
                'endpoint' => $subscription->getEndpoint(),
                'publicKey' => $subscription->getP256dhKey(),
                'authToken' => $subscription->getAuthKey(),
            ]);

            $payload = json_encode([
                'title' => $queueEntry->getTitle(),
                'body' => $queueEntry->getBody(),
                'icon' => $queueEntry->getIcon(),
                'url' => $queueEntry->getUrl(),
                'data' => $queueEntry->getData(),
            ]);

            $report = $webPush->sendOneNotification($pushSubscription, $payload);

            if ($report->isSuccess()) {
                $queueEntry->markAsSent();
                $subscription->updateLastUsed();
                $this->entityManager->saveEntity($subscription);
                $this->entityManager->saveEntity($queueEntry);
                return true;
            }

            // Handle failure
            $reason = $report->getReason();
            $queueEntry->markAsFailed($reason ?? 'Unknown error');

            // Check if subscription is expired
            if ($report->isSubscriptionExpired()) {
                $subscription->deactivate();
                $this->entityManager->saveEntity($subscription);
            }

            $this->entityManager->saveEntity($queueEntry);
            return false;
        } catch (\Throwable $e) {
            $queueEntry->markAsFailed($e->getMessage());
            $this->entityManager->saveEntity($queueEntry);
            throw $e;
        }
    }

    /**
     * Create a queue entry.
     */
    private function createQueueEntry(
        string $userId,
        ?string $subscriptionId,
        string $title,
        string $body,
        array $options = []
    ): PushNotificationQueue {
        $queueEntry = $this->entityManager->getNewEntity(PushNotificationQueue::ENTITY_TYPE);
        $queueEntry->set([
            'name' => $title,
            'userId' => $userId,
            'subscriptionId' => $subscriptionId,
            'title' => $title,
            'body' => $body,
            'icon' => $options['icon'] ?? null,
            'url' => $options['url'] ?? null,
            'data' => $options['data'] ?? null,
            'notificationType' => $options['notificationType'] ?? null,
            'priority' => $options['priority'] ?? PushNotificationQueue::PRIORITY_NORMAL,
            'status' => PushNotificationQueue::STATUS_PENDING,
            'scheduledAt' => $options['scheduledAt'] ?? null,
            'maxAttempts' => $options['maxAttempts'] ?? 3,
        ]);

        $this->entityManager->saveEntity($queueEntry);

        return $queueEntry;
    }

    /**
     * Get or create the WebPush instance.
     */
    private function getWebPush(): WebPush
    {
        if ($this->webPush === null) {
            $auth = [
                'VAPID' => [
                    'subject' => $this->vapidKeyService->getSubject(),
                    'publicKey' => $this->vapidKeyService->getPublicKey(),
                    'privateKey' => $this->vapidKeyService->getPrivateKey(),
                ],
            ];

            $this->webPush = new WebPush($auth);
        }

        return $this->webPush;
    }
}
