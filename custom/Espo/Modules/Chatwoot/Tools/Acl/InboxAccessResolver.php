<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax - Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Tools\Acl;

use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class InboxAccessResolver
{
    /** @var array<string, string[]> */
    private array $allowedInboxIdListByUserId = [];

    /** CRM user -> CRM account -> linked Chatwoot users eligible for task management. */
    private array $taskActors = [];

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $api,
    ) {}

    /**
     * Resolve CRM ChatwootInbox IDs the user can access via:
     * User -> ChatwootUser.assignedUserId -> ChatwootAccountUserMembership -> chatwootInboxes.
     *
     * Memberships with role "administrator" AND globalAdmin = true grant access
     * to ALL inboxes in that account. Scoped administrators (globalAdmin = false)
     * are resolved through the per-membership chatwootInboxes link, exactly like
     * agents — mirroring Chatwoot's `account_users.global_admin` semantics where
     * scoped admins only see inboxes they are members of (directly or via teams).
     *
     * Returns `null` for unrestricted access (Espo admins), otherwise a concrete allow-list.
     *
     * @return ?string[]
     */
    public function getAllowedInboxIdList(User $user): ?array
    {
        if ($user->isAdmin()) {
            return null;
        }

        $userId = $user->getId();

        if (array_key_exists($userId, $this->allowedInboxIdListByUserId)) {
            return $this->allowedInboxIdListByUserId[$userId];
        }

        $membershipCollection = $this->entityManager
            ->getRDBRepository('ChatwootAccountUserMembership')
            ->leftJoin('chatwootUser')
            ->where([
                'chatwootUser.assignedUserId' => $userId,
            ])
            ->find();

        $inboxIdMap = [];
        $administratorAccountIds = [];

        foreach ($membershipCollection as $membership) {
            if (!$membership instanceof CoreEntity) {
                continue;
            }

            if ($membership->get('isAI') && $membership->get('tasksEnabled')) {
                $this->taskActors[$userId][$membership->get('chatwootAccountId')][$membership->get('chatwootUserId')] = true;
            }

            if ($membership->get('role') === 'administrator' && $membership->get('globalAdmin')) {
                $accountId = $membership->get('chatwootAccountId');

                if ($accountId) {
                    $administratorAccountIds[] = $accountId;
                }

                continue;
            }

            foreach ($membership->getLinkMultipleIdList('chatwootInboxes') as $inboxId) {
                $inboxIdMap[$inboxId] = true;
            }
        }

        if (count($administratorAccountIds)) {
            $adminInboxCollection = $this->entityManager
                ->getRDBRepository('ChatwootInbox')
                ->where(['chatwootAccountId' => $administratorAccountIds])
                ->find();

            foreach ($adminInboxCollection as $inbox) {
                $inboxIdMap[$inbox->getId()] = true;
            }
        }

        $inboxIdList = array_keys($inboxIdMap);

        $this->allowedInboxIdListByUserId[$userId] = $inboxIdList;

        return $inboxIdList;
    }

    public function canAccessInboxId(User $user, ?string $inboxId): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (!$inboxId) {
            return false;
        }

        $allowedInboxIdList = $this->getAllowedInboxIdList($user) ?? [];

        return in_array($inboxId, $allowedInboxIdList, true);
    }

    public function canAccessConversationId(User $user, ?string $conversationId): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (!$conversationId) {
            return false;
        }

        $conversation = $this->entityManager->getEntityById('ChatwootConversation', $conversationId);

        if (!$conversation) {
            return false;
        }

        return $this->canAccessInboxId($user, $conversation->get('inboxId'));
    }

    /** A mention grants one conversation in Chatwoot, not membership of its inbox. */
    public function canReadTaskConversation(User $user, Entity $conversation): bool
    {
        $this->getAllowedInboxIdList($user);
        $accountId = $conversation->get('chatwootAccountId');
        $displayId = (int) $conversation->get('chatwootConversationId');
        $actors = $this->taskActors[$user->getId()][$accountId] ?? [];
        if (count($actors) !== 1 || $displayId <= 0) {
            return false;
        }

        $actor = $this->entityManager->getEntityById('ChatwootUser', array_key_first($actors));
        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
        if (!$actor || !$account || $actor->get('assignedUserId') !== $user->getId() ||
            !$account->get('platformId') || $actor->get('platformId') !== $account->get('platformId')) {
            return false;
        }

        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $account->get('platformId'));
        $remoteAccount = (int) $account->get('chatwootAccountId');
        $remoteUser = (int) $actor->get('chatwootUserId');
        if (!$platform?->get('backendUrl') || !$platform->get('accessToken') || $remoteAccount <= 0 || $remoteUser <= 0) {
            return false;
        }

        try {
            $allowed = $this->api->getAccessibleConversationIds($platform->get('backendUrl'),
                $platform->get('accessToken'), $remoteAccount, $remoteUser, [$displayId]);
            return in_array($displayId, $allowed, true);
        } catch (\Throwable) {
            return false;
        }
    }

    public function canAccessContactId(User $user, ?string $chatwootContactId): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (!$chatwootContactId) {
            return false;
        }

        $allowedInboxIdList = $this->getAllowedInboxIdList($user) ?? [];

        if ($allowedInboxIdList === []) {
            return false;
        }

        $contactInbox = $this->entityManager
            ->getRDBRepository('ChatwootContactInbox')
            ->where([
                'chatwootContactId' => $chatwootContactId,
                'inboxId' => $allowedInboxIdList,
            ])
            ->findOne();

        return (bool) $contactInbox;
    }
}
