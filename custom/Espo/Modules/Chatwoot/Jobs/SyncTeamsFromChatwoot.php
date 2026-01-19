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

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use Espo\ORM\Entity;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Scheduled job to sync teams from Chatwoot to EspoCRM.
 * Iterates through all ChatwootAccount records with contactSyncEnabled = true
 * and pulls teams from Chatwoot.
 */
class SyncTeamsFromChatwoot implements JobDataLess
{
    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    public function run(): void
    {
        $this->log->warning('SyncTeamsFromChatwoot: Job started');

        try {
            $accounts = $this->getEnabledAccounts();
            $accountList = iterator_to_array($accounts);
            $accountCount = count($accountList);

            $this->log->warning("SyncTeamsFromChatwoot: Found {$accountCount} account(s) to sync");

            foreach ($accountList as $account) {
                $this->syncAccountTeams($account);
            }

            $this->log->warning("SyncTeamsFromChatwoot: Job completed - processed {$accountCount} account(s)");
        } catch (\Throwable $e) {
            $this->log->error('SyncTeamsFromChatwoot: Job failed - ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    /**
     * Get all ChatwootAccounts with contact sync enabled.
     *
     * @return iterable<Entity>
     */
    private function getEnabledAccounts(): iterable
    {
        return $this->entityManager
            ->getRDBRepository('ChatwootAccount')
            ->where([
                'contactSyncEnabled' => true,
                'status' => 'active',
            ])
            ->find();
    }

    /**
     * Sync teams for a single ChatwootAccount.
     */
    private function syncAccountTeams(Entity $account): void
    {
        $accountName = $account->get('name');

        try {
            $platform = $this->entityManager->getEntityById(
                'ChatwootPlatform',
                $account->get('platformId')
            );

            if (!$platform) {
                throw new \Exception('ChatwootPlatform not found');
            }

            $platformUrl = $platform->get('url');
            $apiKey = $account->get('apiKey');
            $chatwootAccountId = $account->get('chatwootAccountId');

            if (!$platformUrl || !$apiKey || !$chatwootAccountId) {
                throw new \Exception('Missing platform URL, API key, or Chatwoot account ID');
            }

            // Get EspoCRM teams from the ChatwootAccount for assignment
            $espoTeamsIds = $this->getAccountTeamsIds($account);

            // Sync teams
            $stats = $this->syncTeams(
                $platformUrl,
                $apiKey,
                $chatwootAccountId,
                $account->getId(),
                $espoTeamsIds
            );

            $this->log->warning(
                "SyncTeamsFromChatwoot: Account {$accountName} - " .
                "{$stats['synced']} synced, {$stats['errors']} errors"
            );

        } catch (\Exception $e) {
            $this->log->error(
                "SyncTeamsFromChatwoot: Sync failed for account {$accountName}: " . $e->getMessage()
            );
        }
    }

    /**
     * Sync teams from Chatwoot to EspoCRM.
     *
     * @param array<string> $espoTeamsIds EspoCRM Team IDs to assign to synced entities
     * @return array{synced: int, errors: int}
     */
    private function syncTeams(
        string $platformUrl,
        string $apiKey,
        int $chatwootAccountId,
        string $espoAccountId,
        array $espoTeamsIds = []
    ): array {
        $stats = ['synced' => 0, 'errors' => 0];

        $teams = $this->apiClient->listTeams(
            $platformUrl,
            $apiKey,
            $chatwootAccountId
        );

        $this->log->warning(
            "SyncTeamsFromChatwoot: Found " . count($teams) . " teams"
        );

        // Track which team IDs we've seen for cleanup
        $seenTeamIds = [];

        foreach ($teams as $chatwootTeam) {
            try {
                $this->syncSingleTeam($chatwootTeam, $espoAccountId, $espoTeamsIds);
                $stats['synced']++;
                $seenTeamIds[] = (int) $chatwootTeam['id'];
            } catch (\Exception $e) {
                $stats['errors']++;
                $teamId = $chatwootTeam['id'] ?? 'unknown';
                $this->log->warning(
                    "SyncTeamsFromChatwoot: Failed to sync team {$teamId}: " . $e->getMessage()
                );
            }
        }

        // Mark teams not in response as removed
        $this->markRemovedTeams($espoAccountId, $seenTeamIds);

        return $stats;
    }

    /**
     * Sync a single team from Chatwoot to EspoCRM.
     *
     * @param array<string> $espoTeamsIds EspoCRM Team IDs to assign to synced entities
     */
    private function syncSingleTeam(array $chatwootTeam, string $espoAccountId, array $espoTeamsIds = []): void
    {
        $chatwootTeamId = (int) $chatwootTeam['id'];

        // Check if ChatwootTeam already exists
        $existingTeam = $this->entityManager
            ->getRDBRepository('ChatwootTeam')
            ->where([
                'chatwootTeamId' => $chatwootTeamId,
                'accountId' => $espoAccountId,
            ])
            ->findOne();

        if ($existingTeam) {
            $this->updateExistingTeam($existingTeam, $chatwootTeam, $espoTeamsIds);
        } else {
            $this->createNewTeam($chatwootTeam, $espoAccountId, $espoTeamsIds);
        }
    }

    /**
     * Update an existing ChatwootTeam from Chatwoot data.
     *
     * @param array<string> $espoTeamsIds EspoCRM Team IDs to assign to synced entities
     */
    private function updateExistingTeam(Entity $team, array $chatwootTeam, array $espoTeamsIds = []): void
    {
        $team->set('name', $chatwootTeam['name'] ?? 'Team #' . $chatwootTeam['id']);
        $team->set('description', $chatwootTeam['description'] ?? null);
        $team->set('allowAutoAssign', $chatwootTeam['allow_auto_assign'] ?? true);
        $team->set('isMember', $chatwootTeam['is_member'] ?? false);
        $team->set('syncStatus', 'synced');
        $team->set('lastSyncedAt', date('Y-m-d H:i:s'));
        $team->set('lastSyncError', null);

        // Assign EspoCRM teams from ChatwootAccount
        if (!empty($espoTeamsIds)) {
            $team->set('teamsIds', $espoTeamsIds);
        }

        $this->entityManager->saveEntity($team, ['silent' => true]);
    }

    /**
     * Create a new ChatwootTeam from Chatwoot data.
     *
     * @param array<string> $espoTeamsIds EspoCRM Team IDs to assign to synced entities
     */
    private function createNewTeam(array $chatwootTeam, string $espoAccountId, array $espoTeamsIds = []): void
    {
        $data = [
            'name' => $chatwootTeam['name'] ?? 'Team #' . $chatwootTeam['id'],
            'description' => $chatwootTeam['description'] ?? null,
            'allowAutoAssign' => $chatwootTeam['allow_auto_assign'] ?? true,
            'isMember' => $chatwootTeam['is_member'] ?? false,
            'chatwootTeamId' => $chatwootTeam['id'],
            'accountId' => $espoAccountId,
            'syncStatus' => 'synced',
            'lastSyncedAt' => date('Y-m-d H:i:s'),
        ];

        // Assign EspoCRM teams from ChatwootAccount
        if (!empty($espoTeamsIds)) {
            $data['teamsIds'] = $espoTeamsIds;
        }

        $this->entityManager->createEntity('ChatwootTeam', $data, ['silent' => true]);
    }

    /**
     * Mark teams that are no longer in Chatwoot as removed.
     *
     * @param array<int> $seenTeamIds Chatwoot team IDs that were seen in the sync
     */
    private function markRemovedTeams(string $espoAccountId, array $seenTeamIds): void
    {
        if (empty($seenTeamIds)) {
            return;
        }

        // Find teams that weren't in the API response
        $removedTeams = $this->entityManager
            ->getRDBRepository('ChatwootTeam')
            ->where([
                'accountId' => $espoAccountId,
                'chatwootTeamId!=' => $seenTeamIds,
                'syncStatus!=' => 'error',
            ])
            ->find();

        foreach ($removedTeams as $team) {
            $team->set('syncStatus', 'error');
            $team->set('lastSyncError', 'Team no longer exists in Chatwoot');
            $team->set('lastSyncedAt', date('Y-m-d H:i:s'));
            $this->entityManager->saveEntity($team, ['silent' => true]);

            $this->log->info(
                "SyncTeamsFromChatwoot: Marked team {$team->get('chatwootTeamId')} as removed"
            );
        }
    }

    /**
     * Get EspoCRM team IDs from a ChatwootAccount.
     *
     * @return array<string>
     */
    private function getAccountTeamsIds(Entity $account): array
    {
        $teamsIds = [];
        $teams = $this->entityManager
            ->getRDBRepository('ChatwootAccount')
            ->getRelation($account, 'teams')
            ->find();

        foreach ($teams as $team) {
            $teamsIds[] = $team->getId();
        }

        return $teamsIds;
    }
}
