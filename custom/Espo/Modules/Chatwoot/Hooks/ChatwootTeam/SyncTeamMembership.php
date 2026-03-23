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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootTeam;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Hook to sync ChatwootTeam membership with Chatwoot.
 * When a membership is linked/unlinked to a ChatwootTeam (from team side), sync to Chatwoot API.
 */
class SyncTeamMembership
{
    public static int $order = 20;

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    /**
     * Called when a ChatwootAccountUserMembership is linked to this ChatwootTeam.
     */
    public function afterRelate(Entity $entity, array $options, array $relationParams): void
    {
        if (!isset($relationParams['relationName']) || $relationParams['relationName'] !== 'accountUserMemberships') {
            return;
        }

        if (!isset($relationParams['foreignId'])) {
            return;
        }

        $membershipId = $relationParams['foreignId'];
        $this->syncAddMembershipToTeam($entity, $membershipId);
    }

    /**
     * Called when a ChatwootAccountUserMembership is unlinked from this ChatwootTeam.
     */
    public function afterUnrelate(Entity $entity, array $options, array $relationParams): void
    {
        if (!isset($relationParams['relationName']) || $relationParams['relationName'] !== 'accountUserMemberships') {
            return;
        }

        if (!isset($relationParams['foreignId'])) {
            return;
        }

        $membershipId = $relationParams['foreignId'];
        $this->syncRemoveMembershipFromTeam($entity, $membershipId);
    }

    /**
     * Add membership to team in Chatwoot.
     */
    private function syncAddMembershipToTeam(Entity $team, string $membershipEntityId): void
    {
        $chatwootTeamId = $team->get('chatwootTeamId');
        if (!$chatwootTeamId) {
            $this->log->warning('SyncTeamMembership (Team): Team has no chatwootTeamId, cannot sync');
            return;
        }

        try {
            $membership = $this->entityManager->getEntityById('ChatwootAccountUserMembership', $membershipEntityId);
            if (!$membership) {
                $this->log->warning('SyncTeamMembership (Team): ChatwootAccountUserMembership not found: ' . $membershipEntityId);
                return;
            }

            $platformUserId = $this->resolvePlatformUserId($membership);
            if (!$platformUserId) {
                $this->log->warning('SyncTeamMembership (Team): Membership has no linked ChatwootUser with chatwootUserId, cannot sync');
                return;
            }

            // Get API credentials from the team's account
            $credentials = $this->getApiCredentials($team);
            if (!$credentials) {
                return;
            }

            $this->log->info("SyncTeamMembership (Team): Adding membership (platformUserId={$platformUserId}) to team {$chatwootTeamId}");

            $this->apiClient->addTeamMembers(
                $credentials['platformUrl'],
                $credentials['apiKey'],
                $credentials['chatwootAccountId'],
                $chatwootTeamId,
                [$platformUserId]
            );

            $this->log->info("SyncTeamMembership (Team): Successfully added membership (platformUserId={$platformUserId}) to team {$chatwootTeamId}");

        } catch (\Exception $e) {
            $this->log->error('SyncTeamMembership (Team): Failed to add membership to team: ' . $e->getMessage());
        }
    }

    /**
     * Remove membership from team in Chatwoot.
     */
    private function syncRemoveMembershipFromTeam(Entity $team, string $membershipEntityId): void
    {
        $chatwootTeamId = $team->get('chatwootTeamId');
        if (!$chatwootTeamId) {
            $this->log->warning('SyncTeamMembership (Team): Team has no chatwootTeamId, cannot sync');
            return;
        }

        try {
            $membership = $this->entityManager->getEntityById('ChatwootAccountUserMembership', $membershipEntityId);
            if (!$membership) {
                $this->log->warning('SyncTeamMembership (Team): ChatwootAccountUserMembership not found: ' . $membershipEntityId);
                return;
            }

            $platformUserId = $this->resolvePlatformUserId($membership);
            if (!$platformUserId) {
                $this->log->warning('SyncTeamMembership (Team): Membership has no linked ChatwootUser with chatwootUserId, cannot sync');
                return;
            }

            // Get API credentials from the team's account
            $credentials = $this->getApiCredentials($team);
            if (!$credentials) {
                return;
            }

            $this->log->info("SyncTeamMembership (Team): Removing membership (platformUserId={$platformUserId}) from team {$chatwootTeamId}");

            $this->apiClient->removeTeamMembers(
                $credentials['platformUrl'],
                $credentials['apiKey'],
                $credentials['chatwootAccountId'],
                $chatwootTeamId,
                [$platformUserId]
            );

            $this->log->info("SyncTeamMembership (Team): Successfully removed membership (platformUserId={$platformUserId}) from team {$chatwootTeamId}");

        } catch (\Exception $e) {
            $this->log->error('SyncTeamMembership (Team): Failed to remove membership from team: ' . $e->getMessage());
        }
    }

    /**
     * Resolve the Chatwoot platform user ID from the membership's linked ChatwootUser.
     */
    private function resolvePlatformUserId(Entity $membership): ?int
    {
        $chatwootUserId = $membership->get('chatwootUserId');
        if (!$chatwootUserId) {
            return null;
        }

        $chatwootUser = $this->entityManager->getEntityById('ChatwootUser', $chatwootUserId);
        if (!$chatwootUser) {
            return null;
        }

        $platformUserId = $chatwootUser->get('chatwootUserId');
        return $platformUserId ? (int) $platformUserId : null;
    }

    /**
     * Get API credentials from the team's account.
     *
     * @return array{platformUrl: string, apiKey: string, chatwootAccountId: int}|null
     */
    private function getApiCredentials(Entity $team): ?array
    {
        $accountId = $team->get('accountId');
        if (!$accountId) {
            $this->log->warning('SyncTeamMembership (Team): Team has no accountId');
            return null;
        }

        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
        if (!$account) {
            $this->log->warning('SyncTeamMembership (Team): ChatwootAccount not found: ' . $accountId);
            return null;
        }

        $chatwootAccountId = $account->get('chatwootAccountId');
        $apiKey = $account->get('apiKey');
        $platformId = $account->get('platformId');

        if (!$chatwootAccountId || !$apiKey || !$platformId) {
            $this->log->warning('SyncTeamMembership (Team): Account missing credentials');
            return null;
        }

        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
        if (!$platform) {
            $this->log->warning('SyncTeamMembership (Team): ChatwootPlatform not found: ' . $platformId);
            return null;
        }

        $platformUrl = $platform->get('backendUrl');
        if (!$platformUrl) {
            $this->log->warning('SyncTeamMembership (Team): Platform has no URL');
            return null;
        }

        return [
            'platformUrl' => $platformUrl,
            'apiKey' => $apiKey,
            'chatwootAccountId' => $chatwootAccountId,
        ];
    }
}
