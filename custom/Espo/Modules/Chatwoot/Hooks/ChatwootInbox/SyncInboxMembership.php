<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Hooks\ChatwootInbox;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Hook to sync ChatwootInbox member changes with Chatwoot.
 *
 * When a ChatwootAccountUserMembership is linked/unlinked to a ChatwootInbox
 * (from the inbox side), this hook pushes the updated full member list to
 * Chatwoot via PATCH /api/v1/accounts/{id}/inbox_members.
 *
 * The Chatwoot API uses replace-all semantics, so both relate and unrelate
 * operations read the current full membership list and send it to Chatwoot.
 */
class SyncInboxMembership
{
    public static int $order = 20;

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    /**
     * Called when a ChatwootAccountUserMembership is linked to this ChatwootInbox.
     */
    public function afterRelate(Entity $entity, array $options, array $relationParams): void
    {
        if (!isset($relationParams['relationName']) || $relationParams['relationName'] !== 'accountUserMemberships') {
            return;
        }

        $this->syncInboxMembersToChawoot($entity, 'relate', $relationParams['foreignId'] ?? null);
    }

    /**
     * Called when a ChatwootAccountUserMembership is unlinked from this ChatwootInbox.
     */
    public function afterUnrelate(Entity $entity, array $options, array $relationParams): void
    {
        if (!isset($relationParams['relationName']) || $relationParams['relationName'] !== 'accountUserMemberships') {
            return;
        }

        $this->syncInboxMembersToChawoot($entity, 'unrelate', $relationParams['foreignId'] ?? null);
    }

    /**
     * Push the current full membership list for this inbox to Chatwoot.
     *
     * Reads all currently-linked ChatwootAccountUserMemberships, resolves each
     * to its Chatwoot platform user ID, and sends the complete list via
     * PATCH /api/v1/accounts/{id}/inbox_members.
     */
    private function syncInboxMembersToChawoot(Entity $inbox, string $action, ?string $membershipId): void
    {
        $chatwootInboxId = $inbox->get('chatwootInboxId');

        if (!$chatwootInboxId) {
            $this->log->warning(
                "SyncInboxMembership: Inbox {$inbox->getId()} has no chatwootInboxId, cannot sync"
            );
            return;
        }

        try {
            $credentials = $this->getApiCredentials($inbox);

            if (!$credentials) {
                return;
            }

            // Read the current full list of memberships on this inbox
            $memberships = $this->entityManager
                ->getRDBRepository('ChatwootInbox')
                ->getRelation($inbox, 'accountUserMemberships')
                ->find();

            // Resolve each membership to its Chatwoot platform user ID
            $chatwootUserIds = [];

            foreach ($memberships as $membership) {
                $chatwootUserId = $membership->get('chatwootUserId');

                if (!$chatwootUserId) {
                    continue;
                }

                $localUser = $this->entityManager->getEntityById('ChatwootUser', $chatwootUserId);

                if (!$localUser) {
                    continue;
                }

                $platformUserId = $localUser->get('chatwootUserId');

                if ($platformUserId) {
                    $chatwootUserIds[] = (int) $platformUserId;
                }
            }

            $this->log->info(
                "SyncInboxMembership: {$action} on inbox {$chatwootInboxId} — " .
                "pushing " . count($chatwootUserIds) . " member(s) to Chatwoot" .
                ($membershipId ? " (triggered by membership {$membershipId})" : '')
            );

            $this->apiClient->updateInboxMembers(
                $credentials['platformUrl'],
                $credentials['apiKey'],
                $credentials['chatwootAccountId'],
                $chatwootInboxId,
                $chatwootUserIds
            );

            $this->log->info(
                "SyncInboxMembership: Successfully synced inbox {$chatwootInboxId} members to Chatwoot"
            );

        } catch (\Exception $e) {
            $this->log->error(
                "SyncInboxMembership: Failed to sync inbox {$chatwootInboxId} members: " . $e->getMessage()
            );
        }
    }

    /**
     * Get API credentials from the inbox's account.
     *
     * @return array{platformUrl: string, apiKey: string, chatwootAccountId: int}|null
     */
    private function getApiCredentials(Entity $inbox): ?array
    {
        $accountId = $inbox->get('chatwootAccountId');

        if (!$accountId) {
            $this->log->warning('SyncInboxMembership: Inbox has no chatwootAccountId');
            return null;
        }

        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);

        if (!$account) {
            $this->log->warning('SyncInboxMembership: ChatwootAccount not found: ' . $accountId);
            return null;
        }

        $chatwootAccountId = $account->get('chatwootAccountId');
        $apiKey = $account->get('apiKey');
        $platformId = $account->get('platformId');

        if (!$chatwootAccountId || !$apiKey || !$platformId) {
            $this->log->warning('SyncInboxMembership: Account missing credentials');
            return null;
        }

        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);

        if (!$platform) {
            $this->log->warning('SyncInboxMembership: ChatwootPlatform not found: ' . $platformId);
            return null;
        }

        $platformUrl = $platform->get('backendUrl');

        if (!$platformUrl) {
            $this->log->warning('SyncInboxMembership: Platform has no URL');
            return null;
        }

        return [
            'platformUrl' => $platformUrl,
            'apiKey' => $apiKey,
            'chatwootAccountId' => $chatwootAccountId,
        ];
    }
}
