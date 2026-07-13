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
use Espo\ORM\EntityManager;

class InboxAccessResolver
{
    /** @var array<string, string[]> */
    private array $allowedInboxIdListByUserId = [];

    public function __construct(
        private EntityManager $entityManager,
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
