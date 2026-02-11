<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\FeaturePwaPush\Services;

use Espo\Core\Utils\Log;
use Espo\Modules\FeaturePwaPush\Entities\PushSubscription;
use Espo\ORM\EntityManager;

/**
 * Service for managing push subscriptions.
 */
class SubscriptionService
{
    public function __construct(
        private EntityManager $entityManager,
        private VapidKeyService $vapidKeyService,
        private Log $log
    ) {}

    /**
     * Subscribe a user to push notifications.
     */
    public function subscribe(
        string $userId,
        string $endpoint,
        string $p256dhKey,
        string $authKey,
        array $options = []
    ): PushSubscription {
        // Check if subscription already exists for this endpoint
        $existing = $this->entityManager
            ->getRDBRepository(PushSubscription::ENTITY_TYPE)
            ->where(['endpoint' => $endpoint])
            ->findOne();

        if ($existing) {
            // Update existing subscription
            $existing->set([
                'userId' => $userId,
                'p256dhKey' => $p256dhKey,
                'authKey' => $authKey,
                'userAgent' => $options['userAgent'] ?? $existing->getUserAgent(),
                'deviceName' => $options['deviceName'] ?? $existing->getDeviceName(),
                'isActive' => true,
                'lastUsedAt' => date('Y-m-d H:i:s'),
            ]);
            $this->entityManager->saveEntity($existing);
            
            $this->log->debug("Updated existing push subscription for user {$userId}");
            return $existing;
        }

        // Create new subscription
        $subscription = $this->entityManager->getNewEntity(PushSubscription::ENTITY_TYPE);
        $subscription->set([
            'name' => $options['deviceName'] ?? 'Device ' . date('Y-m-d H:i:s'),
            'userId' => $userId,
            'endpoint' => $endpoint,
            'p256dhKey' => $p256dhKey,
            'authKey' => $authKey,
            'userAgent' => $options['userAgent'] ?? null,
            'deviceName' => $options['deviceName'] ?? null,
            'isActive' => true,
            'lastUsedAt' => date('Y-m-d H:i:s'),
        ]);
        $this->entityManager->saveEntity($subscription);

        $this->log->info("Created new push subscription for user {$userId}");
        return $subscription;
    }

    /**
     * Unsubscribe a user from push notifications.
     */
    public function unsubscribe(string $userId, string $endpoint): bool
    {
        $subscription = $this->entityManager
            ->getRDBRepository(PushSubscription::ENTITY_TYPE)
            ->where([
                'userId' => $userId,
                'endpoint' => $endpoint,
            ])
            ->findOne();

        if (!$subscription) {
            return false;
        }

        $subscription->deactivate();
        $this->entityManager->saveEntity($subscription);

        $this->log->info("Deactivated push subscription for user {$userId}");
        return true;
    }

    /**
     * Get all active subscriptions for a user.
     *
     * @return PushSubscription[]
     */
    public function getUserSubscriptions(string $userId): array
    {
        $collection = $this->entityManager
            ->getRDBRepository(PushSubscription::ENTITY_TYPE)
            ->where([
                'userId' => $userId,
                'isActive' => true,
            ])
            ->find();

        return iterator_to_array($collection);
    }

    /**
     * Get all active subscriptions (for admin purposes).
     *
     * @return PushSubscription[]
     */
    public function getAllActiveSubscriptions(): array
    {
        $collection = $this->entityManager
            ->getRDBRepository(PushSubscription::ENTITY_TYPE)
            ->where(['isActive' => true])
            ->find();

        return iterator_to_array($collection);
    }

    /**
     * Get the VAPID public key for client-side subscription.
     */
    public function getVapidPublicKey(): ?string
    {
        return $this->vapidKeyService->getPublicKey();
    }

    /**
     * Deactivate a subscription by ID.
     */
    public function deactivateSubscription(string $subscriptionId): bool
    {
        $subscription = $this->entityManager
            ->getEntityById(PushSubscription::ENTITY_TYPE, $subscriptionId);

        if (!$subscription) {
            return false;
        }

        $subscription->deactivate();
        $this->entityManager->saveEntity($subscription);

        $this->log->info("Deactivated push subscription {$subscriptionId}");
        return true;
    }

    /**
     * Delete old inactive subscriptions.
     *
     * @return int Number of deleted subscriptions
     */
    public function cleanupInactiveSubscriptions(int $daysOld = 30): int
    {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysOld} days"));

        $count = 0;
        $subscriptions = $this->entityManager
            ->getRDBRepository(PushSubscription::ENTITY_TYPE)
            ->where([
                'isActive' => false,
                'modifiedAt<' => $cutoffDate,
            ])
            ->find();

        foreach ($subscriptions as $subscription) {
            $this->entityManager->removeEntity($subscription);
            $count++;
        }

        if ($count > 0) {
            $this->log->info("Cleaned up {$count} inactive push subscriptions");
        }

        return $count;
    }
}
