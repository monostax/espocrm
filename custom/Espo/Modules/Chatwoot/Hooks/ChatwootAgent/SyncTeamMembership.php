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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootAgent;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Hook to sync ChatwootAgent team membership with Chatwoot.
 * When an agent is linked/unlinked to a ChatwootTeam, sync to Chatwoot API.
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
     * Called when a ChatwootAgent is linked to a ChatwootTeam.
     */
    public function afterRelate(Entity $entity, array $options, array $relationParams): void
    {
        if (!isset($relationParams['relationName']) || $relationParams['relationName'] !== 'chatwootTeams') {
            return;
        }

        if (!isset($relationParams['foreignId'])) {
            return;
        }

        $teamId = $relationParams['foreignId'];
        $this->syncAddAgentToTeam($entity, $teamId);
    }

    /**
     * Called when a ChatwootAgent is unlinked from a ChatwootTeam.
     */
    public function afterUnrelate(Entity $entity, array $options, array $relationParams): void
    {
        if (!isset($relationParams['relationName']) || $relationParams['relationName'] !== 'chatwootTeams') {
            return;
        }

        if (!isset($relationParams['foreignId'])) {
            return;
        }

        $teamId = $relationParams['foreignId'];
        $this->syncRemoveAgentFromTeam($entity, $teamId);
    }

    /**
     * Add agent to team in Chatwoot.
     */
    private function syncAddAgentToTeam(Entity $agent, string $teamEntityId): void
    {
        $chatwootAgentId = $agent->get('chatwootAgentId');
        if (!$chatwootAgentId) {
            $this->log->warning('SyncTeamMembership: Agent has no chatwootAgentId, cannot sync');
            return;
        }

        try {
            $team = $this->entityManager->getEntityById('ChatwootTeam', $teamEntityId);
            if (!$team) {
                $this->log->warning('SyncTeamMembership: ChatwootTeam not found: ' . $teamEntityId);
                return;
            }

            $chatwootTeamId = $team->get('chatwootTeamId');
            if (!$chatwootTeamId) {
                $this->log->warning('SyncTeamMembership: Team has no chatwootTeamId, cannot sync');
                return;
            }

            // Get API credentials from the account
            $credentials = $this->getApiCredentials($agent);
            if (!$credentials) {
                return;
            }

            $this->log->info("SyncTeamMembership: Adding agent {$chatwootAgentId} to team {$chatwootTeamId}");

            $this->apiClient->addTeamMembers(
                $credentials['platformUrl'],
                $credentials['apiKey'],
                $credentials['chatwootAccountId'],
                $chatwootTeamId,
                [$chatwootAgentId]
            );

            $this->log->info("SyncTeamMembership: Successfully added agent {$chatwootAgentId} to team {$chatwootTeamId}");

        } catch (\Exception $e) {
            $this->log->error('SyncTeamMembership: Failed to add agent to team: ' . $e->getMessage());
        }
    }

    /**
     * Remove agent from team in Chatwoot.
     */
    private function syncRemoveAgentFromTeam(Entity $agent, string $teamEntityId): void
    {
        $chatwootAgentId = $agent->get('chatwootAgentId');
        if (!$chatwootAgentId) {
            $this->log->warning('SyncTeamMembership: Agent has no chatwootAgentId, cannot sync');
            return;
        }

        try {
            $team = $this->entityManager->getEntityById('ChatwootTeam', $teamEntityId);
            if (!$team) {
                $this->log->warning('SyncTeamMembership: ChatwootTeam not found: ' . $teamEntityId);
                return;
            }

            $chatwootTeamId = $team->get('chatwootTeamId');
            if (!$chatwootTeamId) {
                $this->log->warning('SyncTeamMembership: Team has no chatwootTeamId, cannot sync');
                return;
            }

            // Get API credentials from the account
            $credentials = $this->getApiCredentials($agent);
            if (!$credentials) {
                return;
            }

            $this->log->info("SyncTeamMembership: Removing agent {$chatwootAgentId} from team {$chatwootTeamId}");

            $this->apiClient->removeTeamMembers(
                $credentials['platformUrl'],
                $credentials['apiKey'],
                $credentials['chatwootAccountId'],
                $chatwootTeamId,
                [$chatwootAgentId]
            );

            $this->log->info("SyncTeamMembership: Successfully removed agent {$chatwootAgentId} from team {$chatwootTeamId}");

        } catch (\Exception $e) {
            $this->log->error('SyncTeamMembership: Failed to remove agent from team: ' . $e->getMessage());
        }
    }

    /**
     * Get API credentials from the agent's account.
     *
     * @return array{platformUrl: string, apiKey: string, chatwootAccountId: int}|null
     */
    private function getApiCredentials(Entity $agent): ?array
    {
        $accountId = $agent->get('chatwootAccountId');
        if (!$accountId) {
            $this->log->warning('SyncTeamMembership: Agent has no chatwootAccountId');
            return null;
        }

        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
        if (!$account) {
            $this->log->warning('SyncTeamMembership: ChatwootAccount not found: ' . $accountId);
            return null;
        }

        $chatwootAccountId = $account->get('chatwootAccountId');
        $apiKey = $account->get('apiKey');
        $platformId = $account->get('platformId');

        if (!$chatwootAccountId || !$apiKey || !$platformId) {
            $this->log->warning('SyncTeamMembership: Account missing credentials');
            return null;
        }

        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
        if (!$platform) {
            $this->log->warning('SyncTeamMembership: ChatwootPlatform not found: ' . $platformId);
            return null;
        }

        $platformUrl = $platform->get('url');
        if (!$platformUrl) {
            $this->log->warning('SyncTeamMembership: Platform has no URL');
            return null;
        }

        return [
            'platformUrl' => $platformUrl,
            'apiKey' => $apiKey,
            'chatwootAccountId' => $chatwootAccountId,
        ];
    }
}
