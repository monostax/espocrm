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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootAccount;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Exceptions\Error;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Config;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Hook to synchronize ChatwootAccount with Chatwoot Platform API.
 * Creates accounts on Chatwoot when they are created in EspoCRM.
 */
class SyncWithChatwoot implements AfterSave, BeforeSave
{
    public static int $order = 10;

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log,
        private Config $config
    ) {}

    /**
     * Validate platform is set and configured before save.
     */
    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        // Only validate for new records
        if (!$entity->isNew()) {
            return;
        }

        // Ensure platform is set
        $platformId = $entity->get('platformId');
        if (!$platformId) {
            throw new Error('Platform is required for ChatwootAccount.');
        }

        // Validate that the platform exists and is properly configured
        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
        
        if (!$platform) {
            throw new Error('Selected ChatwootPlatform does not exist.');
        }

        // Validate platform has URL
        $url = $platform->get('url');
        if (!$url) {
            throw new Error('ChatwootPlatform does not have a URL configured. Please configure the platform URL first.');
        }

        // Validate platform has access token
        $accessToken = $platform->get('accessToken');
        if (!$accessToken) {
            throw new Error('ChatwootPlatform does not have an access token configured. Please configure the access token first.');
        }
    }

    /**
     * Create account on Chatwoot after entity is saved.
     */
    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        // Only run for new ChatwootAccount records (not updates)
        if (!$entity->isNew()) {
            return;
        }

        // Skip if chatwootAccountId already exists (account already synced)
        if ($entity->get('chatwootAccountId')) {
            return;
        }

        // Skip if no platform is linked
        $platformId = $entity->get('platformId');
        if (!$platformId) {
            $this->log->warning('ChatwootAccount created without platform link: ' . $entity->getId());
            return;
        }

        try {
            // Get the platform entity
            $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
            
            if (!$platform) {
                throw new Error('ChatwootPlatform not found: ' . $platformId);
            }

            // Get platform URL and access token
            $platformUrl = $this->getPlatformUrl($platform);
            $accessToken = $platform->get('accessToken');

            if (!$accessToken) {
                throw new Error('ChatwootPlatform does not have an access token.');
            }

            // Prepare account data for Chatwoot API
            $accountData = $this->prepareAccountData($entity);

            // Create account on Chatwoot
            $response = $this->apiClient->createAccount($platformUrl, $accessToken, $accountData);

            // Update entity with Chatwoot account ID
            if (isset($response['id'])) {
                $entity->set('chatwootAccountId', $response['id']);
                
                // Save without triggering hooks again
                $this->entityManager->saveEntity($entity, [
                    'skipHooks' => true,
                    'silent' => true
                ]);

                $this->log->info(
                    'Successfully created Chatwoot account: ' . 
                    $response['id'] . ' for ' . $entity->getId()
                );
            } else {
                throw new Error('Chatwoot API response missing account ID.');
            }
        } catch (\Exception $e) {
            // Since afterSave runs after the entity is committed to DB,
            // we need to delete it if the API call fails to maintain sync
            $this->log->error(
                'Failed to create Chatwoot account for ' . $entity->getId() . 
                ': ' . $e->getMessage() . '. Deleting entity to maintain sync.'
            );
            
            // Delete the entity to prevent orphaned records
            $this->entityManager->getRDBRepository($entity->getEntityType())
                ->deleteFromDb($entity->getId());
            
            // Re-throw the exception with a clear message
            throw new Error(
                'Failed to create account on Chatwoot: ' . $e->getMessage() . 
                '. The account was not created to maintain synchronization.'
            );
        }
    }

    /**
     * Prepare account data for Chatwoot API.
     *
     * @param Entity $entity
     * @return array<string, mixed>
     */
    private function prepareAccountData(Entity $entity): array
    {
        $data = [
            'name' => $entity->get('name'),
            'locale' => $entity->get('locale') ?? 'pt_BR',
            'status' => $entity->get('status') ?? 'active',
        ];

        // Add optional fields if they exist
        if ($entity->get('domain')) {
            $data['domain'] = $entity->get('domain');
        }

        if ($entity->get('supportEmail')) {
            $data['support_email'] = $entity->get('supportEmail');
        }

        // Add empty arrays for limits and custom_attributes as per API spec
        $data['limits'] = [];
        $data['custom_attributes'] = [];

        return $data;
    }

    /**
     * Get platform URL from platform entity.
     *
     * @param Entity $platform
     * @return string
     * @throws Error
     */
    private function getPlatformUrl(Entity $platform): string
    {
        $url = $platform->get('url');
        
        if (!$url) {
            throw new Error('ChatwootPlatform does not have a URL configured.');
        }

        return $url;
    }
}

