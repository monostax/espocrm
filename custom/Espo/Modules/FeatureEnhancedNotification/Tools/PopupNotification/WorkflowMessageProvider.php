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

namespace Espo\Modules\FeatureEnhancedNotification\Tools\PopupNotification;

use DateInterval;
use DateTime;
use Espo\Core\Utils\DateTime as DateTimeUtil;
use Espo\Entities\Notification;
use Espo\Entities\User;
use Espo\ORM\EntityManager;
use Espo\Tools\PopupNotification\Item;
use Espo\Tools\PopupNotification\Provider;

/**
 * Provides popup notification items for Workflow/Formula-generated
 * notifications (TYPE_MESSAGE) that have the isPopup flag set.
 * Only returns notifications created within the last 10 minutes
 * to avoid flooding on page reload.
 */
class WorkflowMessageProvider implements Provider
{
    private const PAST_MINUTES = 10;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    /**
     * @return Item[]
     */
    public function get(User $user): array
    {
        $userId = $user->getId();

        $dt = new DateTime();

        $now = $dt->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT);

        $cutoff = (new DateTime())
            ->sub(new DateInterval('PT' . self::PAST_MINUTES . 'M'))
            ->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT);

        /** @var iterable<Notification> $notificationCollection */
        $notificationCollection = $this->entityManager
            ->getRDBRepository(Notification::ENTITY_TYPE)
            ->select([
                'id',
                'message',
                'data',
                'relatedId',
                'relatedType',
                'createdAt',
            ])
            ->where([
                'type' => Notification::TYPE_MESSAGE,
                'userId' => $userId,
                'read' => false,
                'createdAt>=' => $cutoff,
                'createdAt<=' => $now,
            ])
            ->order('createdAt', 'DESC')
            ->limit(0, 10)
            ->find();

        $resultList = [];

        foreach ($notificationCollection as $notification) {
            $notificationData = $notification->getData();

            // Only show popup for notifications with the isPopup flag
            // (created by the "Create Popup Notification" workflow action).
            if (!($notificationData?->isPopup ?? false)) {
                continue;
            }

            $notificationId = $notification->getId();

            $data = (object) [
                'message' => $notification->get('message') ?? '',
                'entityType' => $notification->get('relatedType'),
                'entityId' => $notification->get('relatedId'),
                'entityName' => $notificationData?->entityName ?? '',
                'userName' => $notificationData?->userName ?? '',
            ];

            $resultList[] = new Item($notificationId, $data);
        }

        return $resultList;
    }
}
