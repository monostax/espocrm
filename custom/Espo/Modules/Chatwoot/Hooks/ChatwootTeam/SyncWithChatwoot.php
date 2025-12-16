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

use Espo\Core\Record\Hook\CreateHook;
use Espo\Core\Record\CreateParams;
use Espo\Core\Exceptions\Error;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Hook to synchronize ChatwootTeam with Chatwoot Account API.
 * Creates team on Chatwoot BEFORE saving to database.
 * This ensures the database only contains teams that fully exist in Chatwoot.
 */
class SyncWithChatwoot implements CreateHook
{
    public static int $order = 10; // Run after ValidateBeforeSync

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    /**
     * Create team on Chatwoot BEFORE entity is saved to database.
     * The entity will be populated with chatwootTeamId before the INSERT.
     * If the operation fails, an exception is thrown and nothing is saved.
     * 
     * @throws Error
     */
    public function process(Entity $entity, CreateParams $params): void
    {
        // Skip if chatwootTeamId already exists (team already synced)
        if ($entity->get('chatwootTeamId')) {
            return;
        }

        // Skip if no account is linked (should have been caught by validation)
        $accountId = $entity->get('accountId');
        if (!$accountId) {
            throw new Error('Account is required for ChatwootTeam.');
        }

        $createdTeamId = null;

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

            // Get platform URL
            $platformUrl = $platform->get('url');

            if (!$platformUrl) {
                throw new Error('ChatwootPlatform does not have a URL.');
            }

            // STEP 1: Create team on Chatwoot
            $this->log->info('Creating Chatwoot team: ' . $entity->get('name'));
            
            $teamData = $this->prepareTeamData($entity);
            $teamResponse = $this->apiClient->createTeam(
                $platformUrl,
                $accountApiKey,
                $chatwootAccountId,
                $teamData
            );

            if (!isset($teamResponse['id'])) {
                throw new Error('Chatwoot API response missing team ID.');
            }

            $chatwootTeamId = $teamResponse['id'];
            $createdTeamId = $chatwootTeamId;
            
            $this->log->info('Chatwoot team created successfully with ID: ' . $chatwootTeamId);

            // STEP 2: Set chatwootTeamId and other data on entity BEFORE database insert
            $entity->set('chatwootTeamId', $chatwootTeamId);
            
            // Set is_member if returned
            if (isset($teamResponse['is_member'])) {
                $entity->set('isMember', $teamResponse['is_member']);
            }

            $this->log->info(
                'Successfully prepared Chatwoot team ' . 
                $chatwootTeamId . ' for account ' . $chatwootAccountId .
                ' for database insert'
            );

        } catch (\Exception $e) {
            // ROLLBACK: If anything failed, log for manual cleanup
            $this->log->error(
                'Failed to create Chatwoot team for ' . $entity->get('name') . 
                ': ' . $e->getMessage()
            );
            
            if ($createdTeamId) {
                $this->log->error(
                    'Orphaned Chatwoot team created with ID: ' . $createdTeamId . 
                    '. Manual cleanup may be required.'
                );
            }

            // Re-throw - this will prevent the database INSERT from happening
            throw new Error(
                'Failed to create team on Chatwoot: ' . $e->getMessage() . 
                '. The team was not created in EspoCRM to maintain synchronization.'
            );
        }
    }

    /**
     * Prepare team data for Chatwoot API.
     *
     * @param Entity $entity
     * @return array<string, mixed>
     */
    private function prepareTeamData(Entity $entity): array
    {
        $data = [
            'name' => $entity->get('name')
        ];

        // Add description if set
        if ($entity->get('description')) {
            $data['description'] = $entity->get('description');
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

