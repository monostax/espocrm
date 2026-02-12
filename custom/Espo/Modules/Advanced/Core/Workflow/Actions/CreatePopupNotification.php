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

namespace Espo\Modules\Advanced\Core\Workflow\Actions;

use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Entities\Notification;
use Espo\Entities\User;
use stdClass;

/**
 * Workflow action that creates a notification AND triggers a popup notification.
 * Identical to CreateNotification but adds an `isPopup` flag to the notification data,
 * which the PopupWebSocketSubmit hook and WorkflowMessageProvider use to show a popup.
 *
 * @noinspection PhpUnused
 */
class CreatePopupNotification extends Base
{
    protected function run(CoreEntity $entity, stdClass $actionData, array $options): bool
    {
        if (empty($actionData->recipient)) {
            return false;
        }

        if (empty($actionData->messageTemplate)) {
            return false;
        }

        $userList = [];

        switch ($actionData->recipient) {
            case 'specifiedUsers':
                if (empty($actionData->userIdList) || !is_array($actionData->userIdList)) {
                    return false;
                }

                $userIds = $actionData->userIdList;

                break;

            case 'specifiedTeams':
                $userIds = $this->workflowHelper->getUserIdsByTeamIds($actionData->specifiedTeamsIds);

                break;

            case 'teamUsers':
                $entity->loadLinkMultipleField('teams');
                $userIds = $this->workflowHelper->getUserIdsByTeamIds($entity->get('teamsIds'));

                break;

            case 'followers':
                $userIds = $this->workflowHelper->getFollowerUserIds($entity);

                break;

            case 'followersExcludingAssignedUser':
                $userIds = $this->workflowHelper->getFollowerUserIdsExcludingAssignedUser($entity);
                break;

            case 'currentUser':
                $userIds = [$this->user->getId()];

                break;

            default:
                $userIds = $this->getRecipients($this->getEntity(), $actionData->recipient)->getIds();

                break;
        }

        foreach ($userIds as $userId) {
            $user = $this->entityManager->getEntityById(User::ENTITY_TYPE, $userId);

            $userList[] = $user;
        }

        $message = $actionData->messageTemplate;

        $variables = $this->getVariables();

        foreach (get_object_vars($variables) as $key => $value) {
            if (is_string($value) || is_int($value) || is_float($value)) {
                if (is_int($value) || is_float($value)) {
                    $value = strval($value);
                } else {
                    if (!$value) {
                        continue;
                    }
                }

                $message = str_replace('{$$' . $key . '}', $value, $message);
            }
        }

        foreach ($userList as $user) {
            $notification = $this->entityManager->getNewEntity(Notification::ENTITY_TYPE);

            $notification->set([
                'type' => Notification::TYPE_MESSAGE,
                'data' => [
                    'entityId' => $entity->getId(),
                    'entityType' => $entity->getEntityType(),
                    'entityName' => $entity->get('name'),
                    'userId' => $this->user->getId(),
                    'userName' => $this->user->getName(),
                    'isPopup' => true,
                ],
                'userId' => $user->getId(),
                'message' => $message,
                'relatedId' => $entity->getId(),
                'relatedType' => $entity->getEntityType(),
            ]);

            $this->entityManager->saveEntity($notification);
        }

        return true;
    }
}
