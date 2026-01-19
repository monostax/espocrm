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

use Espo\Core\Exceptions\Error;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Hook to synchronize ChatwootTeam with Chatwoot Account API.
 * Creates or updates team on Chatwoot BEFORE saving to database.
 * This ensures the database only contains teams that fully exist in Chatwoot.
 */
class SyncWithChatwoot
{
    public static int $order = 10; // Run after ValidateBeforeSync

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    /**
     * Create or update team on Chatwoot BEFORE entity is saved to database.
     * 
     * @param Entity $entity
     * @param array<string, mixed> $options
     * @throws Error
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        // Skip if this is a silent save (from sync job)
        if (!empty($options['silent'])) {
            return;
        }

        $isNew = $entity->isNew();
        $chatwootTeamId = $entity->get('chatwootTeamId');

        // Skip if no account is linked
        $accountId = $entity->get('accountId');
        if (!$accountId) {
            return;
        }

        try {
            // Get the account entity
            $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
            
            if (!$account) {
                throw new Error('ChatwootAccount not found: ' . $accountId);
            }

            $chatwootAccountId = $account->get('chatwootAccountId');
            if (!$chatwootAccountId) {
                throw new Error('ChatwootAccount has not been synchronized with Chatwoot.');
            }

            // Get account API key
            $accountApiKey = $account->get('apiKey');
            if (!$accountApiKey) {
                throw new Error('ChatwootAccount does not have an API key configured.');
            }

            // Get platform from account
            $platformId = $account->get('platformId');
            $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
            
            if (!$platform) {
                throw new Error('ChatwootPlatform not found: ' . $platformId);
            }

            $platformUrl = $platform->get('url');
            if (!$platformUrl) {
                throw new Error('ChatwootPlatform does not have a URL.');
            }

            if ($isNew && !$chatwootTeamId) {
                // Create new team on Chatwoot (or link to existing)
                $this->createTeamOnChatwoot($entity, $platformUrl, $accountApiKey, $chatwootAccountId);
            } elseif ($chatwootTeamId) {
                // Update existing team on Chatwoot
                $this->updateTeamOnChatwoot($entity, $platformUrl, $accountApiKey, $chatwootAccountId, $chatwootTeamId);
            }

            // Mark as synced
            $entity->set('syncStatus', 'synced');
            $entity->set('lastSyncedAt', date('Y-m-d H:i:s'));
            $entity->set('lastSyncError', null);

        } catch (\Exception $e) {
            $this->log->error(
                'Failed to sync ChatwootTeam to Chatwoot: ' . $e->getMessage()
            );

            // Mark as error but allow save to continue for updates
            $entity->set('syncStatus', 'error');
            $entity->set('lastSyncError', $e->getMessage());
            $entity->set('lastSyncedAt', date('Y-m-d H:i:s'));

            // For new teams, we should throw to prevent orphaned records
            if ($isNew && !$chatwootTeamId) {
                throw new Error(
                    'Failed to create team on Chatwoot: ' . $e->getMessage() . 
                    '. The team was not created in EspoCRM to maintain synchronization.'
                );
            }
        }
    }

    /**
     * Create a new team on Chatwoot, or link to existing one if already exists.
     */
    private function createTeamOnChatwoot(
        Entity $entity,
        string $platformUrl,
        string $apiKey,
        int $chatwootAccountId
    ): void {
        $name = $entity->get('name');
        $this->log->info('Creating/linking Chatwoot team: ' . $name);

        // First, check if a team with this name already exists in Chatwoot
        $existingTeam = $this->findExistingTeamByName($platformUrl, $apiKey, $chatwootAccountId, $name);
        
        if ($existingTeam) {
            $this->log->info('Found existing Chatwoot team with ID: ' . $existingTeam['id'] . ', linking instead of creating');
            $this->populateEntityFromChatwootTeam($entity, $existingTeam);
            return;
        }

        // Team doesn't exist, create new one
        $teamData = $this->prepareTeamData($entity);

        $response = $this->apiClient->createTeam(
            $platformUrl,
            $apiKey,
            $chatwootAccountId,
            $teamData
        );

        if (!isset($response['id'])) {
            throw new Error('Chatwoot API response missing team ID.');
        }

        $this->populateEntityFromChatwootTeam($entity, $response);
        $this->log->info('Chatwoot team created successfully with ID: ' . $response['id']);
    }

    /**
     * Find an existing team by name in Chatwoot.
     * 
     * @return array|null The team data if found, null otherwise
     */
    private function findExistingTeamByName(
        string $platformUrl,
        string $apiKey,
        int $chatwootAccountId,
        string $name
    ): ?array {
        try {
            $teams = $this->apiClient->listTeams($platformUrl, $apiKey, $chatwootAccountId);
            
            foreach ($teams as $team) {
                if (isset($team['name']) && strtolower($team['name']) === strtolower($name)) {
                    return $team;
                }
            }
        } catch (\Exception $e) {
            $this->log->warning('Failed to list teams from Chatwoot: ' . $e->getMessage());
        }
        
        return null;
    }

    /**
     * Populate entity fields from Chatwoot team data.
     */
    private function populateEntityFromChatwootTeam(Entity $entity, array $chatwootTeam): void
    {
        $entity->set('chatwootTeamId', $chatwootTeam['id']);
        
        if (isset($chatwootTeam['description'])) {
            $entity->set('description', $chatwootTeam['description']);
        }
        if (isset($chatwootTeam['allow_auto_assign'])) {
            $entity->set('allowAutoAssign', $chatwootTeam['allow_auto_assign']);
        }
        if (isset($chatwootTeam['is_member'])) {
            $entity->set('isMember', $chatwootTeam['is_member']);
        }
    }

    /**
     * Update an existing team on Chatwoot.
     */
    private function updateTeamOnChatwoot(
        Entity $entity,
        string $platformUrl,
        string $apiKey,
        int $chatwootAccountId,
        int $chatwootTeamId
    ): void {
        // Check if relevant fields have changed
        $fieldsToCheck = ['name', 'description', 'allowAutoAssign'];
        $hasChanges = false;
        
        foreach ($fieldsToCheck as $field) {
            if ($entity->isAttributeChanged($field)) {
                $hasChanges = true;
                break;
            }
        }

        if (!$hasChanges) {
            return;
        }

        $this->log->info('Updating Chatwoot team: ' . $chatwootTeamId);

        $teamData = $this->prepareTeamData($entity);

        $response = $this->apiClient->updateTeam(
            $platformUrl,
            $apiKey,
            $chatwootAccountId,
            $chatwootTeamId,
            $teamData
        );

        // Update fields from response
        if (isset($response['is_member'])) {
            $entity->set('isMember', $response['is_member']);
        }

        $this->log->info('Chatwoot team updated successfully: ' . $chatwootTeamId);
    }

    /**
     * Prepare team data for Chatwoot API.
     *
     * @return array<string, mixed>
     */
    private function prepareTeamData(Entity $entity): array
    {
        $data = [
            'name' => $entity->get('name')
        ];

        // Add description if set
        $description = $entity->get('description');
        if ($description !== null && $description !== '') {
            $data['description'] = $description;
        }

        // Add allow_auto_assign
        $allowAutoAssign = $entity->get('allowAutoAssign');
        if ($allowAutoAssign !== null) {
            $data['allow_auto_assign'] = (bool) $allowAutoAssign;
        } else {
            $data['allow_auto_assign'] = true; // default
        }

        return $data;
    }
}
