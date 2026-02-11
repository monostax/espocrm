<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\FeaturePwaPush\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Controllers\Record;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeaturePwaPush\Services\SubscriptionService;

/**
 * Controller for managing push subscriptions.
 *
 * Note: All actions except vapidPublicKey require authentication, which is
 * enforced by EspoCRM's middleware (no NoAuth trait). The user is guaranteed
 * to be authenticated when these actions are called.
 */
class PushSubscription extends Record
{
    /**
     * Subscribe the current user to push notifications.
     *
     * @throws BadRequest
     */
    public function postActionSubscribe(Request $request, Response $response): array
    {
        $data = $request->getParsedBody();

        if (empty($data->endpoint) || empty($data->keys?->p256dh) || empty($data->keys?->auth)) {
            throw new BadRequest('Missing required subscription data: endpoint, p256dh, auth');
        }

        $user = $this->getUser();

        /** @var SubscriptionService $service */
        $service = $this->injectableFactory->create(SubscriptionService::class);

        $subscription = $service->subscribe(
            $user->getId(),
            $data->endpoint,
            $data->keys->p256dh,
            $data->keys->auth,
            [
                'userAgent' => $data->userAgent ?? null,
                'deviceName' => $data->deviceName ?? null,
            ]
        );

        return [
            'success' => true,
            'id' => $subscription->getId(),
        ];
    }

    /**
     * Unsubscribe the current user from push notifications.
     *
     * @throws BadRequest
     */
    public function postActionUnsubscribe(Request $request, Response $response): array
    {
        $data = $request->getParsedBody();

        if (empty($data->endpoint)) {
            throw new BadRequest('Missing endpoint');
        }

        $user = $this->getUser();

        /** @var SubscriptionService $service */
        $service = $this->injectableFactory->create(SubscriptionService::class);

        $service->unsubscribe($user->getId(), $data->endpoint);

        return ['success' => true];
    }

    /**
     * Get all subscriptions for the current user.
     */
    public function getActionMySubscriptions(Request $request, Response $response): array
    {
        $user = $this->getUser();

        /** @var SubscriptionService $service */
        $service = $this->injectableFactory->create(SubscriptionService::class);

        $subscriptions = $service->getUserSubscriptions($user->getId());

        return [
            'list' => array_map(function ($sub) {
                return [
                    'id' => $sub->getId(),
                    'deviceName' => $sub->getDeviceName(),
                    'userAgent' => $sub->getUserAgent(),
                    'lastUsedAt' => $sub->getLastUsedAt(),
                    'isActive' => $sub->isActive(),
                ];
            }, $subscriptions),
        ];
    }

    /**
     * Get the VAPID public key for client-side subscription.
     */
    public function getActionVapidPublicKey(Request $request, Response $response): array
    {
        /** @var SubscriptionService $service */
        $service = $this->injectableFactory->create(SubscriptionService::class);

        return [
            'publicKey' => $service->getVapidPublicKey(),
        ];
    }
}
